<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\YouTubeConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<PostMedia>  $media
 * @param  array<string, mixed>  $targetOverrides
 * @param  array<int, list<PostMedia>>  $mediaBySection
 */
function youtubePublishContext(array $media, array $targetOverrides = [], array $mediaBySection = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge([
        'platform' => Platform::YouTube,
        'sections' => ['A sharp YouTube title', 'The rest of the description.'],
    ], $targetOverrides));
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::YouTube,
        'remote_account_id' => 'UC-dani',
    ]);

    return new PublishContext(
        target: $target,
        segments: ['A sharp YouTube title', 'The rest of the description.'],
        media: $media,
        account: $account,
        credentials: ['access_token' => 'youtube-token'],
        mediaBySection: $mediaBySection,
    );
}

beforeEach(function () {
    config()->set([
        'services.youtube.publishing_enabled' => true,
        'services.youtube.privacy_status' => 'public',
        'services.youtube.category_id' => '22',
        'services.youtube.format_intent' => 'short',
        'services.youtube.made_for_kids' => false,
        'services.youtube.contains_synthetic_media' => false,
        'services.youtube.has_paid_product_placement' => false,
        'services.youtube.notify_subscribers' => false,
    ]);
});

test('youtube stays fail closed until publishing is explicitly enabled', function () {
    config()->set('services.youtube.publishing_enabled', false);
    Http::fake();

    $video = PostMedia::factory()->video()->create();
    $result = app(YouTubeConnector::class)->publish(youtubePublishContext([$video]));

    expect($result->errorKind)->toBe(ErrorKind::Unsupported);
    Http::assertNothingSent();
});

test('youtube starts an upload for the placed video while ignoring excluded media', function () {
    Http::fake([
        'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
            'error' => ['message' => 'deliberate test stop'],
        ], 400),
    ]);

    $image = PostMedia::factory()->create(['kind' => 'image']);
    $video = PostMedia::factory()->video()->create();
    $result = app(YouTubeConnector::class)->publish(
        youtubePublishContext([$image, $video], [], [0 => [$video]]),
    );

    expect($result->errorMessage)->not->toContain('exactly one video');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'uploadType=resumable'));
});

test('youtube completes a resumable upload and waits for public processing', function () {
    Storage::fake('public');
    $bytes = 'video-bytes';
    Storage::disk('public')->put('media/clip.mp4', $bytes);
    $video = PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/clip.mp4',
        'size_bytes' => strlen($bytes),
    ]);
    $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=upload-42';

    Http::fake(function (Request $request) use ($session) {
        if ($request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable')) {
            return Http::response([], 200, ['Location' => $session]);
        }
        if ($request->method() === 'PUT' && $request->url() === $session) {
            return Http::response(['id' => 'video_42'], 200);
        }
        if ($request->method() === 'GET' && str_contains($request->url(), '/youtube/v3/videos')) {
            return Http::response(['items' => [[
                'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'public'],
                'processingDetails' => ['processingStatus' => 'succeeded'],
            ]]]);
        }

        return Http::response(['error' => ['message' => 'unexpected request']], 500);
    });

    $context = youtubePublishContext([$video]);
    $result = app(YouTubeConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['video_42'])
        ->and($context->target->fresh()->remote_id)->toBe('video_42')
        ->and(json_encode($context->target->fresh()->media_upload_state))->not->toContain('upload-42');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'notifySubscribers=false')
        && $request['snippet']['title'] === 'A sharp YouTube title'
        && $request['snippet']['categoryId'] === '22'
        && $request['status']['selfDeclaredMadeForKids'] === false
        && $request['status']['containsSyntheticMedia'] === false);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === $session
        && $request->hasHeader('Content-Range', 'bytes 0-10/11'));
});

test('youtube completes a processed private video using the privacy stored at upload time', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
            'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'private'],
            'processingDetails' => ['processingStatus' => 'succeeded'],
        ]]]),
    ]);

    $video = PostMedia::factory()->video()->create();
    $context = youtubePublishContext([$video], [
        'remote_id' => 'video_42',
        'media_upload_state' => [
            $video->id => [
                'state' => 'processing',
                'metadata' => ['privacy_status' => 'private'],
            ],
        ],
    ]);
    $result = app(YouTubeConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['video_42']);
});

test('youtube fails closed when processed privacy differs from the requested privacy', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
            'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'private'],
            'processingDetails' => ['processingStatus' => 'succeeded'],
        ]]]),
    ]);

    $video = PostMedia::factory()->video()->create();
    $context = youtubePublishContext([$video], ['remote_id' => 'video_42']);
    $result = app(YouTubeConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Unsupported)
        ->and($result->errorMessage)->toContain('requested public');
});

test('youtube classifies resumable session authentication failures', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/clip.mp4', 'video-bytes');
    $video = PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/clip.mp4',
        'size_bytes' => 11,
    ]);

    Http::fake([
        'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
            'error' => ['message' => 'Invalid Credentials'],
        ], 401),
    ]);

    $result = app(YouTubeConnector::class)->publish(youtubePublishContext([$video]));

    expect($result->errorKind)->toBe(ErrorKind::AuthExpired)
        ->and($result->httpStatus)->toBe(401)
        ->and($result->errorMessage)->toBe('Invalid Credentials');
});

test('youtube maps quota-exceeded 403 responses to rate limiting', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
            'error' => [
                'message' => 'The request cannot be completed because you have exceeded your quota.',
                'errors' => [['reason' => 'quotaExceeded']],
            ],
        ], 403),
    ]);

    $video = PostMedia::factory()->video()->create();
    $result = app(YouTubeConnector::class)->publish(youtubePublishContext([$video], ['remote_id' => 'video_42']));

    expect($result->errorKind)->toBe(ErrorKind::RateLimited)
        ->and($result->httpStatus)->toBe(403);
});

test('youtube maps missing-scope 403 responses to reconnect-required authentication', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
            'error' => [
                'message' => 'Insufficient Permission',
                'errors' => [['reason' => 'insufficientPermissions']],
            ],
        ], 403),
    ]);

    $video = PostMedia::factory()->video()->create();
    $result = app(YouTubeConnector::class)->publish(youtubePublishContext([$video], ['remote_id' => 'video_42']));

    expect($result->errorKind)->toBe(ErrorKind::AuthExpired)
        ->and($result->httpStatus)->toBe(403);
});

test('youtube retries a stored video processing-status connection failure without crashing or reopening the upload', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('processing status timed out');
    });

    $video = PostMedia::factory()->video()->create();
    $context = youtubePublishContext([$video], ['remote_id' => 'video_42']);
    $result = app(YouTubeConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Network)
        ->and($result->retryAfter)->toBe(10)
        ->and($context->target->fresh()->remote_id)->toBe('video_42')
        ->and($context->target->fresh()->media_upload_state)->toBeNull()
        ->and($calls)->toBe(1);
});

test('youtube resumes from the byte range actually acknowledged by the server', function () {
    Storage::fake('public');
    $size = (8 * 1024 * 1024) + 4;
    Storage::disk('public')->put('media/clip.mp4', str_repeat('v', $size));
    $video = PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/clip.mp4',
        'size_bytes' => $size,
    ]);
    $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=partial-42';

    Http::fake(function (Request $request) use ($session) {
        if ($request->method() === 'POST') {
            return Http::response([], 200, ['Location' => $session]);
        }

        return Http::response([], 308, ['Range' => 'bytes=0-4194303']);
    });

    $context = youtubePublishContext([$video]);
    $result = app(YouTubeConnector::class)->publish($context);
    $metadata = $context->target->fresh()->media_upload_state[$video->id]['metadata'];

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($metadata['uploaded_bytes'])->toBe(4 * 1024 * 1024)
        ->and($metadata['privacy_status'])->toBe('public')
        ->and($metadata['outcome_unknown'])->toBeFalse();
});

test('youtube probes before retrying after an ambiguous server error', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/clip.mp4', 'video-bytes');
    $video = PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/clip.mp4',
        'size_bytes' => 11,
    ]);
    $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=ambiguous-42';
    $chunkAttempts = 0;

    Http::fake(function (Request $request) use ($session, &$chunkAttempts) {
        if ($request->method() === 'POST') {
            return Http::response([], 200, ['Location' => $session]);
        }

        if ($request->hasHeader('Content-Range', 'bytes */11')) {
            return Http::response([], 308);
        }

        if ($request->method() === 'PUT') {
            $chunkAttempts++;

            return $chunkAttempts === 1
                ? Http::response(['error' => ['message' => 'temporary']], 503)
                : Http::response(['id' => 'video_42'], 200);
        }

        return Http::response(['items' => [[
            'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'public'],
            'processingDetails' => ['processingStatus' => 'succeeded'],
        ]]]);
    });

    $context = youtubePublishContext([$video]);
    $first = app(YouTubeConnector::class)->publish($context);

    expect($first->errorKind)->toBe(ErrorKind::ServerError)
        ->and($context->target->fresh()->media_upload_state[$video->id]['metadata']['outcome_unknown'])->toBeTrue();

    $second = app(YouTubeConnector::class)->publish($context);

    expect($second->isSuccessful())->toBeTrue()
        ->and($second->remoteIds)->toBe(['video_42'])
        ->and($chunkAttempts)->toBe(2);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === $session
        && $request->hasHeader('Content-Range', 'bytes */11'));
});

test('youtube requires one video and every publishing declaration', function () {
    Http::fake();
    config()->set('services.youtube.contains_synthetic_media', null);

    $result = app(YouTubeConnector::class)->publish(youtubePublishContext([]));

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('declarations');
    Http::assertNothingSent();
});
