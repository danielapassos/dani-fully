<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\YouTubeConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** @param list<PostMedia> $media */
function youtubePublishContext(array $media, array $targetOverrides = []): PublishContext
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

test('youtube keeps an uploaded private video in processing state', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
            'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'private'],
            'processingDetails' => ['processingStatus' => 'succeeded'],
        ]]]),
    ]);

    $video = PostMedia::factory()->video()->create();
    $context = youtubePublishContext([$video], ['remote_id' => 'video_42']);
    $result = app(YouTubeConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($result->errorMessage)->toContain('not public');
});

test('youtube requires one video and every publishing declaration', function () {
    Http::fake();
    config()->set('services.youtube.contains_synthetic_media', null);

    $result = app(YouTubeConnector::class)->publish(youtubePublishContext([]));

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('declarations');
    Http::assertNothingSent();
});
