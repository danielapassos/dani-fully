<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\PublicMediaUrl;
use App\Services\Publishing\Connectors\TikTokConnector;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** @param list<PostMedia> $media */
function tiktokPublishContext(array $media, array $targetOverrides = []): PublishContext
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

test('tiktok rejects anything other than one video', function () {
    Http::fake();

    $result = tiktokPublishConnector()->publish(tiktokPublishContext([]));

    expect($result->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});
