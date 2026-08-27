<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\PublicMediaUrl;
use App\Services\Publishing\Connectors\TikTokConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
    $publicUrl = Mockery::mock(PublicMediaUrl::class);
    $publicUrl->shouldReceive('for')->andReturn('https://media.example.test/video.mp4');

    return new TikTokConnector(app(HttpFactory::class), $publicUrl);
}

beforeEach(function () {
    config()->set('services.tiktok.inbox_enabled', true);
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
                'data' => ['publish_id' => 'publish-42'],
                'error' => ['code' => 'ok'],
            ]);
        }

        return Http::response([
            'data' => ['status' => 'SEND_TO_USER_INBOX'],
            'error' => ['code' => 'ok'],
        ]);
    });

    $video = PostMedia::factory()->video()->create();
    $context = tiktokPublishContext([$video]);
    $result = tiktokPublishConnector()->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($result->errorMessage)->toContain('Open TikTok')
        ->and($context->target->fresh()->media_upload_state[$video->id]['remote_ref'])->toBe('publish-42');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/inbox/video/init/')
        && $request['source_info']['source'] === 'PULL_FROM_URL'
        && $request['source_info']['video_url'] === 'https://media.example.test/video.mp4');
});

test('tiktok publishes the placed video while ignoring media excluded for that target', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/inbox/video/init/')) {
            return Http::response([
                'data' => ['publish_id' => 'publish-subset'],
                'error' => ['code' => 'ok'],
            ]);
        }

        return Http::response([
            'data' => ['status' => 'SEND_TO_USER_INBOX'],
            'error' => ['code' => 'ok'],
        ]);
    });

    $image = PostMedia::factory()->create(['kind' => 'image']);
    $video = PostMedia::factory()->video()->create();
    $result = tiktokPublishConnector()->publish(
        tiktokPublishContext([$image, $video], [], [0 => [$video]]),
    );

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/inbox/video/init/'));
});

test('tiktok persists a manual-review gate when the inbox init response is lost', function () {
    Http::fake(fn () => throw new ConnectionException('connection lost after inbox init'));

    $video = PostMedia::factory()->video()->create();
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
