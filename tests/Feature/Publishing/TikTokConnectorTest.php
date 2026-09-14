<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\TikTokConnector;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;

/**
 * @param  list<PostMedia>  $media
 * @param  array<string, mixed>  $targetOverrides
 * @param  array<int, list<PostMedia>>  $mediaBySection
 */
function tiktokPublishContext(array $media, array $targetOverrides = [], array $mediaBySection = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge([
        'platform' => Platform::TikTok,
        'sections' => ['TikTok caption'],
    ], $targetOverrides));
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok,
        'remote_account_id' => 'open-42',
    ]);

    return new PublishContext(
        target: $target,
        segments: ['TikTok caption'],
        media: $media,
        account: $account,
        credentials: ['access_token' => 'tiktok-token'],
        mediaBySection: $mediaBySection,
    );
}

function tiktokPublishConnector(): TikTokConnector
{
    return new TikTokConnector(app(HttpFactory::class));
}

function tiktokStoredVideo(string $bytes = 'private-video-bytes'): PostMedia
{
    $video = PostMedia::factory()->video()->create(['disk' => 'local', 'size_bytes' => strlen($bytes)]);
    Storage::disk('local')->put($video->path, $bytes);

    return $video;
}

function tiktokTestUploadUrl(): string
{
    return 'https://open-upload.tiktokapis.com/video/?upload_id=test-upload&upload_token=test-upload-secret';
}

/** @param array<string, mixed> $overrides */
function tiktokStoredUploadContext(PostMedia $video, array $overrides = []): PublishContext
{
    $chunkSize = min(8 * 1024 * 1024, $video->size_bytes);

    return tiktokPublishContext([$video], ['media_upload_state' => [
        $video->id => [
            'remote_ref' => 'publish-42',
            'state' => 'processing',
            'metadata' => array_replace([
                'source' => 'FILE_UPLOAD',
                'total_bytes' => $video->size_bytes,
                'content_type' => $video->mime,
                'chunk_size' => $chunkSize,
                'chunk_count' => max(1, intdiv($video->size_bytes, $chunkSize)),
                'uploaded_bytes' => 0,
                'upload_expires_at' => now()->addHour()->timestamp,
                'upload_url' => Crypt::encryptString(tiktokTestUploadUrl()),
            ], $overrides),
        ],
    ]]);
}

beforeEach(function () {
    config()->set('services.tiktok.inbox_enabled', true);
    Storage::fake('local');
    Http::preventStrayRequests();
});

test('tiktok stays fail closed until inbox publishing is explicitly enabled', function () {
    config()->set('services.tiktok.inbox_enabled', false);
    Http::fake();

    $video = PostMedia::factory()->video()->create();
    $result = tiktokPublishConnector()->publish(tiktokPublishContext([$video]));

    expect($result->errorKind)->toBe(ErrorKind::Unsupported);
    Http::assertNothingSent();
});

test('tiktok transfers one video and keeps the target processing until native finish', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/inbox/video/init/')) {
            return Http::response([
                'data' => ['publish_id' => 'publish-42', 'upload_url' => tiktokTestUploadUrl()],
                'error' => ['code' => 'ok'],
            ]);
        }

        if ($request->method() === 'PUT') {
            return Http::response('', 201);
        }

        return Http::response([
            'data' => ['status' => 'SEND_TO_USER_INBOX'],
            'error' => ['code' => 'ok'],
        ]);
    });

    $video = tiktokStoredVideo();
    $context = tiktokPublishContext([$video]);
    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($result->errorMessage)->toContain('Open TikTok')
        ->and($context->target->fresh()->media_upload_state[$video->id]['remote_ref'])->toBe('publish-42');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/inbox/video/init/')
        && $request['source_info'] === [
            'source' => 'FILE_UPLOAD',
            'video_size' => strlen('private-video-bytes'),
            'chunk_size' => strlen('private-video-bytes'),
            'total_chunk_count' => 1,
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === tiktokTestUploadUrl()
        && $request->body() === 'private-video-bytes'
        && $request->header('Content-Type') === ['video/mp4']
        && $request->header('Content-Length') === ['19']
        && $request->header('Content-Range') === ['bytes 0-18/19']
        && ! $request->hasHeader('Authorization'));
    expect($context->target->fresh()->media_upload_state[$video->id]['metadata'])->not->toHaveKey('upload_url');
    Http::assertSentCount(3);
});

test('tiktok publishes the placed video while ignoring media excluded for that target', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/inbox/video/init/')) {
            return Http::response([
                'data' => ['publish_id' => 'publish-subset', 'upload_url' => tiktokTestUploadUrl()],
                'error' => ['code' => 'ok'],
            ]);
        }

        if ($request->method() === 'PUT') {
            return Http::response('', 201);
        }

        return Http::response([
            'data' => ['status' => 'SEND_TO_USER_INBOX'],
            'error' => ['code' => 'ok'],
        ]);
    });

    $image = PostMedia::factory()->create(['kind' => 'image']);
    $video = tiktokStoredVideo();
    $result = tiktokPublishConnector()->publish(
        tiktokPublishContext([$image, $video], [], [0 => [$video]]),
    );

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/inbox/video/init/'));
});

test('tiktok persists a manual-review gate when the inbox init response is lost', function () {
    Http::fake(fn () => throw new ConnectionException('connection lost after inbox init'));

    $video = tiktokStoredVideo();
    $context = tiktokPublishContext([$video]);
    $connector = tiktokPublishConnector();
    $result = $connector->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Unknown)
        ->and($result->errorKind?->isRetryable())->toBeFalse()
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['init_outcome_unknown'])->toBeTrue();

    $retryCalledProvider = false;
    Http::fake(function () use (&$retryCalledProvider) {
        $retryCalledProvider = true;

        return Http::response([], 500);
    });

    $retry = $connector->publish($context);

    expect($retry->errorKind)->toBe(ErrorKind::Unknown)
        ->and($retryCalledProvider)->toBeFalse();
});

test('tiktok retains safe initialization diagnostics without creating an upload', function () {
    Http::fake(['*/inbox/video/init/' => Http::response([
        'error' => ['code' => 'invalid_param', 'message' => 'The total_chunk_count is invalid.', 'logid' => '20260914153227ABCDEF'],
        'data' => ['upload_url' => tiktokTestUploadUrl()],
    ], 400)]);
    $video = tiktokStoredVideo();
    $context = tiktokPublishContext([$video]);

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->httpStatus)->toBe(400)
        ->and($result->errorMessage)->toContain('invalid_param', 'total_chunk_count')
        ->and(json_decode($result->responseExcerpt, true))->toBe([
            'code' => 'invalid_param', 'message' => 'The total_chunk_count is invalid.', 'log_id' => '20260914153227ABCDEF',
        ])
        ->and($result->responseExcerpt)->not->toContain('test-upload-secret')
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata'])->not->toHaveKey('init_outcome_unknown');
    Http::assertSentCount(1);
});

test('tiktok omits unsafe initialization prose and never saves the raw response', function (string $message) {
    Http::fake(['*/inbox/video/init/' => Http::response([
        'error' => ['code' => 'invalid_param', 'message' => $message, 'log_id' => 'https://secret.example.test'],
        'data' => ['upload_url' => tiktokTestUploadUrl()],
    ], 400)]);

    $result = tiktokPublishConnector()->publish(tiktokPublishContext([tiktokStoredVideo()]));

    expect($result->errorMessage)->not->toContain($message)
        ->and(json_decode($result->responseExcerpt, true))->toBe(['code' => 'invalid_param', 'message' => null, 'log_id' => null]);
})->with([
    'URL' => 'See https://example.test/?upload_token=secret',
    'credential' => 'access_token: secret',
    'long opaque value' => str_repeat('a', 64),
    'structured message' => '{"secret":"value"}',
]);

test('tiktok preserves the unknown-outcome gate for an initialization server error', function () {
    Http::fake(['*/inbox/video/init/' => Http::response(['error' => ['code' => 'internal_error']], 500)]);
    $video = tiktokStoredVideo();
    $context = tiktokPublishContext([$video]);
    $connector = tiktokPublishConnector();

    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::Unknown)
        ->and($connector->publish($context)->errorKind)->toBe(ErrorKind::Unknown)
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['init_outcome_unknown'])->toBeTrue();
    Http::assertSentCount(1);
});

test('tiktok resumes the stored publish and records the public video id', function () {
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => ['7390000000000000042'],
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $video = PostMedia::factory()->video()->create();
    $context = tiktokPublishContext([$video], [
        'media_upload_state' => [
            $video->id => ['remote_ref' => 'publish-42', 'state' => 'processing'],
        ],
    ]);
    $result = tiktokPublishConnector()->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['7390000000000000042']);

    Http::assertSentCount(1);
});

test('tiktok completes a non-public post without storing the publish tracker as a video id', function () {
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => ['', null],
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $video = PostMedia::factory()->video()->create();
    $context = tiktokPublishContext([$video], [
        'media_upload_state' => [
            $video->id => ['remote_ref' => 'publish-42', 'state' => 'processing'],
        ],
    ]);
    $result = tiktokPublishConnector()->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe([])
        ->and($context->target->fresh()->media_upload_state[$video->id]['remote_ref'])->toBe('publish-42');

    Http::assertSentCount(1);
});

test('tiktok clears a failed publish tracker before retrying a documented transient failure', function () {
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'FAILED',
                'fail_reason' => 'video_pull_failed',
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $video = PostMedia::factory()->video()->create();
    $context = tiktokPublishContext([$video], [
        'media_upload_state' => [
            $video->id => ['remote_ref' => 'publish-42', 'state' => 'processing'],
        ],
    ]);
    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::ServerError)
        ->and($result->retryAfter)->toBe(30)
        ->and($context->target->fresh()->media_upload_state)->not->toHaveKey($video->id);
});

test('tiktok marks removed creator access as an authentication failure', function () {
    Http::fake([
        'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'FAILED',
                'fail_reason' => 'auth_removed',
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $video = PostMedia::factory()->video()->create();
    $context = tiktokPublishContext([$video], [
        'media_upload_state' => [
            $video->id => ['remote_ref' => 'publish-42', 'state' => 'processing'],
        ],
    ]);
    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::AuthExpired)
        ->and($result->errorMessage)->toContain('reconnect');
});

test('tiktok rejects anything other than one video', function () {
    Http::fake();

    $result = tiktokPublishConnector()->publish(tiktokPublishContext([]));

    expect($result->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

test('tiktok uploads one chunk per invocation and merges trailing bytes into the final chunk', function () {
    $chunk = 8 * 1024 * 1024;
    $bytes = str_repeat('a', $chunk).str_repeat('b', $chunk).'remainder';
    $video = tiktokStoredVideo($bytes);
    $context = tiktokPublishContext([$video]);
    $puts = [];
    Http::fake(function (Request $request, array $options) use (&$puts, $context, $video, $chunk) {
        if (str_contains($request->url(), '/inbox/video/init/')) {
            expect($request['source_info'])->toBe([
                'source' => 'FILE_UPLOAD',
                'video_size' => $chunk * 2 + 9,
                'chunk_size' => $chunk,
                'total_chunk_count' => 2,
            ]);

            return Http::response(['data' => ['publish_id' => 'publish-42', 'upload_url' => tiktokTestUploadUrl()]]);
        }
        if ($request->method() === 'PUT') {
            $saved = $context->target->fresh()->media_upload_state[$video->id];
            expect($saved['remote_ref'])->toBe('publish-42')
                ->and($saved['metadata']['outcome_unknown'])->toBeTrue()
                ->and(Crypt::decryptString($saved['metadata']['upload_url']))->toBe(tiktokTestUploadUrl())
                ->and(json_encode($saved))->not->toContain('test-upload-secret')
                ->and($options['allow_redirects'])->toBeFalse()
                ->and($request->hasHeader('Authorization'))->toBeFalse();
            $puts[] = [$request->header('Content-Range'), $request->body()];

            return Http::response('', count($puts) === 1 ? 206 : 201);
        }

        return Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX']]);
    });

    $first = tiktokPublishConnector()->publish($context);
    expect($first->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($puts)->toHaveCount(1)
        ->and($puts[0])->toBe([['bytes 0-8388607/16777225'], str_repeat('a', $chunk)])
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['uploaded_bytes'])->toBe($chunk);

    $context->target->refresh();
    $second = tiktokPublishConnector()->publish($context);
    expect($second->errorMessage)->toContain('Open TikTok')
        ->and($puts)->toHaveCount(2)
        ->and($puts[1])->toBe([['bytes 8388608-16777224/16777225'], str_repeat('b', $chunk).'remainder']);
    Http::assertSentCount(4);
});

test('tiktok reconciles a lost or rejected chunk response before deciding which bytes to send', function (int|string $failure, bool $accepted) {
    $chunk = 8 * 1024 * 1024;
    $video = tiktokStoredVideo(str_repeat('a', $chunk).str_repeat('b', $chunk));
    $context = tiktokStoredUploadContext($video);
    $firstAttempt = true;
    Http::fake(function () use ($failure, &$firstAttempt) {
        if (! $firstAttempt) {
            return null;
        }
        if ($failure === 'network') {
            throw new ConnectionException('PUT '.tiktokTestUploadUrl().' failed');
        }

        return Http::response(tiktokTestUploadUrl(), $failure);
    });

    $first = tiktokPublishConnector()->publish($context);
    expect($first->errorMessage)->not->toContain('test-upload-secret')
        ->and($first->responseExcerpt)->toBeNull()
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['outcome_unknown'])->toBeTrue();

    $firstAttempt = false;
    $requests = [];
    Http::fake(function (Request $request) use (&$requests, $accepted, $chunk) {
        $requests[] = $request->method();
        if ($request->method() === 'PUT') {
            expect($requests)->toBe(['POST', 'PUT'])
                ->and($request->body())->toBe(str_repeat($accepted ? 'b' : 'a', $chunk));

            return Http::response('', $accepted ? 201 : 206);
        }

        expect($request->url())->toContain('/status/fetch/');

        return Http::response(['data' => [
            'status' => count($requests) === 1 ? 'PROCESSING_UPLOAD' : 'SEND_TO_USER_INBOX',
            'uploaded_bytes' => $accepted ? $chunk : 0,
        ]]);
    });
    $context->target->refresh();
    $result = tiktokPublishConnector()->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($context->target->fresh()->media_upload_state[$video->id]['remote_ref'])->toBe('publish-42');
})->with([
    'lost unaccepted chunk' => ['network', false],
    'lost accepted chunk' => ['network', true],
    'server failed after acceptance' => [500, true],
    'range mismatch' => [416, true],
]);

test('tiktok will not guess missing invalid or partial upload progress', function (mixed $uploaded) {
    $chunk = 8 * 1024 * 1024;
    $video = tiktokStoredVideo(str_repeat('v', $chunk * 3));
    $context = tiktokStoredUploadContext($video, [
        'uploaded_bytes' => $chunk,
        'pending_end' => $chunk * 2,
        'outcome_unknown' => true,
    ]);
    Http::fake(['*/status/fetch/' => Http::response(['data' => ['status' => 'PROCESSING_UPLOAD', 'uploaded_bytes' => $uploaded]])]);

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Unknown)
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['outcome_unknown'])->toBeTrue();
    Http::assertSentCount(1);
})->with([null, '8388608', -1, 0, 8388609, 16777217]);

test('tiktok keeps polling when a final chunk response was lost but all bytes are confirmed', function () {
    $video = tiktokStoredVideo();
    $context = tiktokStoredUploadContext($video, ['outcome_unknown' => true, 'pending_end' => $video->size_bytes]);
    Http::fake(['*/status/fetch/' => Http::response(['data' => [
        'status' => 'PROCESSING_UPLOAD', 'uploaded_bytes' => $video->size_bytes,
    ]])]);

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['upload_complete'])->toBeTrue()
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata'])->not->toHaveKey('upload_url');
    Http::assertSentCount(1);
});

test('tiktok honors delivery even when an interrupted upload URL has expired', function (string $status) {
    $video = tiktokStoredVideo();
    $context = tiktokStoredUploadContext($video, [
        'outcome_unknown' => true,
        'pending_end' => $video->size_bytes,
        'upload_expires_at' => now()->subMinute()->timestamp,
    ]);
    Http::fake(['*/status/fetch/' => Http::response(['data' => ['status' => $status]])]);

    $result = tiktokPublishConnector()->publish($context);

    expect($result->isSuccessful())->toBe($status === 'PUBLISH_COMPLETE')
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['upload_complete'])->toBeTrue()
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata'])->not->toHaveKey('upload_url');
    Http::assertSentCount(1);
})->with(['SEND_TO_USER_INBOX', 'PUBLISH_COMPLETE']);

test('tiktok does not initialize another inbox transfer when an incomplete upload has expired', function () {
    $video = tiktokStoredVideo();
    $context = tiktokStoredUploadContext($video, ['upload_expires_at' => now()->subMinute()->timestamp]);
    Http::fake(['*/status/fetch/' => Http::response(['data' => ['status' => 'PROCESSING_UPLOAD', 'uploaded_bytes' => 0]])]);

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Unknown)
        ->and($result->errorMessage)->toContain('expired')
        ->and($context->target->fresh()->media_upload_state[$video->id]['remote_ref'])->toBe('publish-42');
    Http::assertSentCount(1);
});

test('tiktok rejects untrusted upload URLs without exposing their signed query', function (string $url) {
    $video = tiktokStoredVideo();
    $context = tiktokPublishContext([$video]);
    Http::fake(['*/inbox/video/init/' => Http::response(['data' => ['publish_id' => 'publish-42', 'upload_url' => $url]])]);

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->not->toContain('secret')
        ->and($context->target->fresh()->media_upload_state[$video->id]['remote_ref'])->toBe('publish-42');
    $context->target->refresh();
    tiktokPublishConnector()->publish($context);
    Http::assertSentCount(1);
})->with([
    'external host' => 'https://example.test/video/?upload_id=id&upload_token=secret',
    'host suffix' => 'https://open-upload.tiktokapis.com.example.test/video/?upload_id=id&upload_token=secret',
    'http' => 'http://open-upload.tiktokapis.com/video/?upload_id=id&upload_token=secret',
    'userinfo' => 'https://user:secret@open-upload.tiktokapis.com/video/?upload_id=id&upload_token=secret',
    'alternate port' => 'https://open-upload.tiktokapis.com:444/video/?upload_id=id&upload_token=secret',
    'fragment' => 'https://open-upload.tiktokapis.com/video/?upload_id=id&upload_token=secret#fragment',
    'missing token' => 'https://open-upload.tiktokapis.com/video/?upload_id=id',
    'wrong path' => 'https://open-upload.tiktokapis.com/other/?upload_id=id&upload_token=secret',
]);

test('tiktok rejects unsupported sizes formats and changed storage before initialization', function (array $changes) {
    Http::fake();
    $video = tiktokStoredVideo();
    $video->forceFill($changes)->save();

    $result = tiktokPublishConnector()->publish(tiktokPublishContext([$video]));

    expect($result->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
})->with([
    'zero' => [['size_bytes' => 0]],
    'oversize' => [['size_bytes' => 4294967297]],
    'changed size' => [['size_bytes' => 100]],
    'unsupported mime' => [['mime' => 'video/x-msvideo']],
]);

test('tiktok does not treat an unexpected successful PUT response as confirmed upload', function () {
    $video = tiktokStoredVideo();
    $context = tiktokStoredUploadContext($video);
    Http::fake([tiktokTestUploadUrl() => Http::response('', 206)]);

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['uploaded_bytes'])->toBe(0)
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['outcome_unknown'])->toBeTrue();
    Http::assertSentCount(1);
});

test('tiktok reads only the next authenticated S3 byte range and closes the source stream', function () {
    $chunk = 8 * 1024 * 1024;
    $size = $chunk * 2 + 9;
    $video = PostMedia::factory()->video()->create(['disk' => 's3', 'path' => 'media/private.mp4', 'size_bytes' => $size]);
    $context = tiktokStoredUploadContext($video, ['uploaded_bytes' => $chunk]);
    $source = Utils::streamFor(str_repeat('b', $chunk).'remainder');
    $driver = Mockery::mock(FilesystemOperator::class);
    $driver->shouldReceive('fileSize')->once()->with('media/private.mp4')->andReturn($size);
    $driver->shouldNotReceive('readStream');
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('getObject')->once()->with([
        'Bucket' => 'private-test-bucket',
        'Key' => 'root/prefix/media/private.mp4',
        'Range' => 'bytes=8388608-16777224',
        '@http' => ['stream' => true, 'timeout' => 120, 'connect_timeout' => 10],
    ])->andReturn(new Result([
        'Body' => $source,
        'ContentRange' => 'bytes 8388608-16777224/16777225',
        'ContentLength' => $chunk + 9,
    ]));
    Storage::set('s3', new AwsS3V3Adapter($driver, Mockery::mock(FilesystemAdapter::class), [
        'bucket' => 'private-test-bucket', 'root' => 'root', 'prefix' => 'prefix',
    ], $client));
    Http::fake([
        tiktokTestUploadUrl() => Http::response('', 201),
        '*/status/fetch/' => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX']]),
    ]);

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorMessage)->toContain('Open TikTok')
        ->and($source->isReadable())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->body() === str_repeat('b', $chunk).'remainder'
        && $request->header('Content-Range') === ['bytes 8388608-16777224/16777225']);
    Http::assertSentCount(2);
});

test('tiktok refuses incorrect or truncated S3 ranges before sending any bytes', function (string $mode) {
    $video = PostMedia::factory()->video()->create(['disk' => 's3', 'size_bytes' => 19]);
    $context = tiktokStoredUploadContext($video);
    $source = Utils::streamFor($mode === 'truncated' ? 'short' : 'private-video-bytes');
    $driver = Mockery::mock(FilesystemOperator::class);
    $driver->shouldReceive('fileSize')->once()->andReturn(19);
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('getObject')->once()->andReturn(new Result([
        'Body' => $source,
        'ContentRange' => $mode === 'ignored range' ? null : 'bytes 0-18/19',
        'ContentLength' => 19,
    ]));
    Storage::set('s3', new AwsS3V3Adapter($driver, Mockery::mock(FilesystemAdapter::class), [
        'bucket' => 'private-test-bucket',
    ], $client));
    Http::fake();

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($source->isReadable())->toBeFalse()
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata'])->not->toHaveKey('outcome_unknown');
    Http::assertNothingSent();
})->with(['ignored range', 'truncated']);

test('tiktok sanitizes private-storage exceptions without creating another inbox transfer', function () {
    $video = tiktokStoredVideo();
    $context = tiktokStoredUploadContext($video);
    $disk = Mockery::mock(Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('size')->once()->andReturn($video->size_bytes);
    $disk->shouldReceive('readStream')->once()->andThrow(UnableToReadFile::fromLocation('private-secret-path'));
    Storage::set('local', $disk);
    Http::fake();

    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->not->toContain('private-secret-path');
    Http::assertNothingSent();
});

test('tiktok retries transient private storage failures at the same byte range without another init', function (string $mode) {
    $video = PostMedia::factory()->video()->create(['disk' => 's3', 'size_bytes' => 19]);
    $context = tiktokStoredUploadContext($video);
    $driver = Mockery::mock(FilesystemOperator::class);
    $driver->shouldReceive('fileSize')->twice()->andReturn(19);
    $client = Mockery::mock(S3Client::class);
    $reads = 0;
    $client->shouldReceive('getObject')->twice()->with(Mockery::on(fn (array $arguments): bool => $arguments['Range'] === 'bytes=0-18'))
        ->andReturnUsing(function () use (&$reads, $mode) {
            if (++$reads === 1) {
                throw new AwsException('https://private-secret-host/object?signature=secret', new Command('GetObject'), [
                    'connection_error' => $mode === 'timeout',
                    'response' => new Response($mode === 'server' ? 503 : 400),
                ]);
            }

            return new Result([
                'Body' => Utils::streamFor('private-video-bytes'),
                'ContentRange' => 'bytes 0-18/19',
                'ContentLength' => 19,
            ]);
        });
    Storage::set('s3', new AwsS3V3Adapter($driver, Mockery::mock(FilesystemAdapter::class), [
        'bucket' => 'private-test-bucket',
    ], $client));
    Http::fake([
        tiktokTestUploadUrl() => Http::response('', 201),
        '*/status/fetch/' => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX']]),
    ]);

    $first = tiktokPublishConnector()->publish($context);
    expect($first->errorKind)->toBe(ErrorKind::Network)
        ->and($first->errorMessage)->not->toContain('secret')
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['uploaded_bytes'])->toBe(0);
    Http::assertNothingSent();

    $context->target->refresh();
    $second = tiktokPublishConnector()->publish($context);
    expect($second->errorMessage)->toContain('Open TikTok')
        ->and($context->target->fresh()->media_upload_state[$video->id]['remote_ref'])->toBe('publish-42');
    Http::assertSentCount(2);
})->with(['timeout', 'server']);
