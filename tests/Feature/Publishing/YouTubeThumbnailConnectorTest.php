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

/** @return array{PublishContext, PostMedia, PostMedia, string} */
function youtubeThumbnailContext(string $privacy = 'public', bool $canUpdate = true): array
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::YouTube,
        'capabilities' => ['oauth_scopes' => array_filter([
            'https://www.googleapis.com/auth/youtube.upload',
            $canUpdate ? 'https://www.googleapis.com/auth/youtube.force-ssl' : null,
        ])],
    ]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::YouTube,
        'connected_account_id' => $account->id,
    ]);
    $bytes = transparentPng(9, 16);
    $thumbnail = PostMedia::factory()->create([
        'workspace_id' => $target->post->workspace_id,
        'disk' => 'public', 'path' => 'media/approved-cover.png', 'mime' => 'image/png',
        'size_bytes' => strlen($bytes), 'width' => 9, 'height' => 16,
    ]);
    $video = PostMedia::factory()->video()->create([
        'workspace_id' => $target->post->workspace_id,
        'disk' => 'public', 'path' => 'media/approved-video.mp4', 'size_bytes' => 11,
    ]);
    Storage::disk('public')->put($thumbnail->path, $bytes);
    Storage::disk('public')->put($video->path, 'video-bytes');
    $target->forceFill(['content_override' => ['youtube' => [
        'privacy_status' => $privacy, 'category_id' => '22', 'format_intent' => 'short',
        'made_for_kids' => false, 'contains_synthetic_media' => true,
        'has_paid_product_placement' => false, 'notify_subscribers' => false,
        'thumbnail_media_id' => $thumbnail->id,
    ]]])->save();

    return [new PublishContext(
        target: $target, segments: ['Approved title', 'Approved description'],
        media: [$video], account: $account, credentials: ['access_token' => 'test-token'],
    ), $video, $thumbnail, $bytes];
}

function youtubeThumbnailProvider(object $provider): void
{
    $provider->calls = [];
    $provider->privacy = 'private';
    $provider->hasThumbnail = false;
    $provider->thumbnailAttempts = 0;
    $provider->privacyAttempts = 0;
    Http::fake(function (Request $request) use ($provider) {
        if ($request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable')) {
            $provider->calls[] = 'initialize';
            $provider->initialStatus = $request['status'];
            if (($provider->initializationUnknown ?? false) === true) {
                throw new ConnectionException('Initialization receipt lost');
            }

            return Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=cover-42']);
        }
        if ($request->method() === 'PUT' && str_contains($request->url(), 'upload_id=cover-42')) {
            $provider->calls[] = 'video-upload';
            if (($provider->uploadUnknown ?? false) === true) {
                throw new ConnectionException('Video upload receipt lost');
            }

            return Http::response(['id' => 'covered_42']);
        }
        if ($request->method() === 'GET') {
            $provider->calls[] = 'read';

            return Http::response(['items' => [[
                'id' => 'covered_42',
                'status' => [
                    'uploadStatus' => 'processed', 'privacyStatus' => $provider->privacy,
                    'selfDeclaredMadeForKids' => false, 'containsSyntheticMedia' => true,
                    'embeddable' => false, 'license' => 'creativeCommon',
                    'publicStatsViewable' => false,
                ],
                'processingDetails' => ['processingStatus' => 'succeeded'],
                'contentDetails' => ['hasCustomThumbnail' => $provider->hasThumbnail],
            ]]]);
        }
        if (str_contains($request->url(), '/thumbnails/set')) {
            $provider->calls[] = 'thumbnail';
            $provider->thumbnailAttempts++;
            $provider->thumbnailBody = $request->body();
            if (($provider->thumbnailIneligible ?? false) === true) {
                return Http::response(['error' => ['message' => 'Custom thumbnails are unavailable', 'errors' => [['reason' => 'forbidden']]]], 403);
            }
            $provider->hasThumbnail = ($provider->hideThumbnailReadback ?? false) !== true;
            if (($provider->thumbnailUnknown ?? false) === true && $provider->thumbnailAttempts === 1) {
                throw new ConnectionException('Thumbnail receipt lost');
            }
            if (($provider->thumbnailMalformed ?? false) === true) {
                return Http::response(['items' => []]);
            }

            return Http::response(['items' => [['default' => ['url' => 'https://i.ytimg.com/vi/covered_42/default.jpg']]]]);
        }
        if ($request->method() === 'PUT' && str_contains($request->url(), '/youtube/v3/videos?part=status')) {
            $provider->calls[] = 'release';
            $provider->privacyAttempts++;
            $provider->releaseStatus = $request['status'];
            if (($provider->privacyForbidden ?? false) === true) {
                return Http::response(['error' => ['message' => 'Public uploads require project audit', 'errors' => [['reason' => 'forbidden']]]], 403);
            }
            $provider->privacy = $request['status']['privacyStatus'];
            if (($provider->privacyUnknown ?? false) === true && $provider->privacyAttempts === 1) {
                throw new ConnectionException('Privacy receipt lost');
            }

            return Http::response(['id' => 'covered_42', 'status' => $request['status']]);
        }
        throw new RuntimeException('Unexpected fake YouTube request');
    });
}

beforeEach(function () {
    config()->set('services.youtube.publishing_enabled', true);
    Storage::fake('public');
    Http::preventStrayRequests();
});

test('youtube binds the exact cover and stages privately until cover and final visibility are confirmed', function (string $privacy) {
    [$context, $video, $thumbnail, $bytes] = youtubeThumbnailContext($privacy);
    $provider = new stdClass;
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);

    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($provider->initialStatus['privacyStatus'])->toBe('private')
        ->and($provider->privacy)->toBe('private')
        ->and($provider->thumbnailBody)->toBe($bytes)
        ->and($context->target->remote_id)->toBe('covered_42');
    $binding = $context->target->fresh()->media_upload_state[$video->id]['metadata']['thumbnail'];
    expect($binding['media_id'])->toBe($thumbnail->id)
        ->and($binding['sha256'])->toBe(hash('sha256', $bytes))
        ->and($binding['accepted_sha256'])->toBe($binding['sha256'])
        ->and($binding['intended_privacy'])->toBe($privacy);

    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);
    $result = $connector->publish($context);
    expect($result->isSuccessful())->toBeTrue()
        ->and($result->outcome)->toBe($privacy === 'public' ? 'published' : 'completed')
        ->and($result->remoteIds)->toBe(['covered_42'])
        ->and($provider->calls)->toBe(['initialize', 'video-upload', 'read', 'thumbnail', 'read', 'release', 'read'])
        ->and($provider->releaseStatus)->toBe([
            'selfDeclaredMadeForKids' => false, 'containsSyntheticMedia' => true,
            'embeddable' => false, 'license' => 'creativeCommon', 'publicStatsViewable' => false,
            'privacyStatus' => $privacy,
        ]);
})->with(['public', 'unlisted']);

test('youtube private cover upload uses the existing upload scope and never changes visibility', function () {
    [$context] = youtubeThumbnailContext('private', false);
    $provider = new stdClass;
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);
    expect($connector->publish($context)->outcome)->toBe('completed')
        ->and($provider->privacyAttempts)->toBe(0);
});

test('youtube requires video update permission before any nonprivate covered upload', function (string $privacy) {
    [$context] = youtubeThumbnailContext($privacy, false);
    Http::fake();
    $result = app(YouTubeConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::AuthExpired)
        ->and($context->target->remote_id)->toBeNull();
    Http::assertNothingSent();
})->with(['public', 'unlisted']);

test('youtube retains a private video for ineligible custom shorts thumbnails', function () {
    [$context] = youtubeThumbnailContext();
    $provider = (object) ['thumbnailIneligible' => true];
    youtubeThumbnailProvider($provider);
    $result = app(YouTubeConnector::class)->publish($context);
    expect($result->outcome)->toBe('awaiting_action')
        ->and($result->statusMessage)->toContain('remains private')
        ->and($result->remoteIds)->toBe(['covered_42'])
        ->and($context->target->fresh()->remote_id)->toBe('covered_42')
        ->and($provider->privacyAttempts)->toBe(0);
});

test('youtube does not trust a custom thumbnail boolean after losing the exact file receipt', function () {
    [$context, $video, , $bytes] = youtubeThumbnailContext();
    $provider = (object) ['thumbnailUnknown' => true];
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::Network)
        ->and($provider->hasThumbnail)->toBeTrue()
        ->and($provider->privacy)->toBe('private')
        ->and($context->target->media_upload_state[$video->id]['metadata']['thumbnail'])->not->toHaveKey('accepted_sha256');
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($provider->calls)->toBe(['initialize', 'video-upload', 'read', 'thumbnail', 'read', 'thumbnail'])
        ->and($provider->thumbnailBody)->toBe($bytes)
        ->and($provider->privacyAttempts)->toBe(0);
    $connector->publish($context);
    expect($connector->publish($context)->outcome)->toBe('published')
        ->and(array_count_values($provider->calls)['initialize'])->toBe(1)
        ->and(array_count_values($provider->calls)['video-upload'])->toBe(1);
});

test('youtube reconciles a lost privacy response from the same video without resubmitting it', function () {
    [$context] = youtubeThumbnailContext();
    $provider = (object) ['privacyUnknown' => true];
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    $connector->publish($context);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::Network);
    expect($connector->publish($context)->outcome)->toBe('published')
        ->and($provider->privacyAttempts)->toBe(1)
        ->and(array_count_values($provider->calls)['initialize'])->toBe(1);
});

test('youtube never releases a private video before the uploaded cover appears in readback', function () {
    [$context] = youtubeThumbnailContext();
    $provider = (object) ['hideThumbnailReadback' => true];
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    $connector->publish($context);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($provider->privacyAttempts)->toBe(0)
        ->and($provider->privacy)->toBe('private');
});

test('youtube retains the private video when the provider rejects public release', function () {
    [$context] = youtubeThumbnailContext();
    $provider = (object) ['privacyForbidden' => true];
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    $connector->publish($context);
    $result = $connector->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($provider->privacy)->toBe('private')
        ->and($context->target->remote_id)->toBe('covered_42');
    $connector->publish($context);
    expect(array_count_values($provider->calls)['initialize'])->toBe(1);
});

test('youtube refuses changed cover bytes after a lost cover receipt while retaining the original private video', function () {
    [$context, , $thumbnail, $bytes] = youtubeThumbnailContext();
    $provider = (object) ['thumbnailUnknown' => true];
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    $connector->publish($context);
    Storage::disk('public')->put($thumbnail->path, str_repeat('x', strlen($bytes)));
    $result = $connector->publish($context);
    expect($result->outcome)->toBe('awaiting_action')
        ->and($result->statusMessage)->toContain('no longer matches')
        ->and($provider->thumbnailAttempts)->toBe(1)
        ->and($provider->privacyAttempts)->toBe(0)
        ->and($context->target->remote_id)->toBe('covered_42');
});

test('youtube keeps bound cover and privacy choices when draft options change after upload', function () {
    [$context, $video, $thumbnail] = youtubeThumbnailContext('unlisted');
    $provider = new stdClass;
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    $connector->publish($context);
    $override = $context->target->content_override;
    $override['youtube']['privacy_status'] = 'public';
    $override['youtube']['thumbnail_media_id'] = null;
    $context->target->forceFill(['content_override' => $override])->save();
    $connector->publish($context);
    expect($connector->publish($context)->outcome)->toBe('completed')
        ->and($provider->privacy)->toBe('unlisted')
        ->and($context->target->media_upload_state[$video->id]['metadata']['thumbnail']['media_id'])->toBe($thumbnail->id);
});

test('youtube holds an ambiguous covered upload initialization without opening another session', function () {
    [$context] = youtubeThumbnailContext();
    $provider = (object) ['initializationUnknown' => true];
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::Network);
    expect($connector->publish($context)->outcome)->toBe('awaiting_action')
        ->and($provider->calls)->toBe(['initialize']);
});

test('youtube does not release a cover upload after an empty thumbnail response', function () {
    [$context] = youtubeThumbnailContext();
    $provider = (object) ['thumbnailMalformed' => true];
    youtubeThumbnailProvider($provider);
    $result = app(YouTubeConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::ServerError)
        ->and($provider->privacyAttempts)->toBe(0)
        ->and($provider->privacy)->toBe('private');
});

test('youtube does not treat requested but unverified video management scopes as granted', function () {
    [$context] = youtubeThumbnailContext();
    $capabilities = $context->account->capabilities;
    $capabilities['oauth_scopes_verified'] = false;
    $context->account->forceFill(['capabilities' => $capabilities])->save();
    Http::fake();
    expect(app(YouTubeConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::AuthExpired);
    Http::assertNothingSent();
});

test('youtube requires the saved cover receipt for the same video before release', function () {
    [$context, $video] = youtubeThumbnailContext();
    $provider = new stdClass;
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    $connector->publish($context);
    $state = $context->target->media_upload_state;
    $state[$video->id]['metadata']['thumbnail']['accepted_video_id'] = 'other_video';
    $context->target->forceFill(['media_upload_state' => $state])->save();
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($provider->thumbnailAttempts)->toBe(2)
        ->and($provider->privacyAttempts)->toBe(0);
});

test('youtube refuses a replaced video source after a cover binding or upload session exists', function (string $stage) {
    [$original, $video] = youtubeThumbnailContext();
    $provider = (object) [
        'initializationUnknown' => $stage === 'unknown initialization',
        'uploadUnknown' => $stage === 'unknown upload',
    ];
    youtubeThumbnailProvider($provider);
    $connector = app(YouTubeConnector::class);
    $connector->publish($original);
    $callsBeforeReplacement = $provider->calls;
    $stateBeforeReplacement = $original->target->fresh()->media_upload_state;
    $replacement = PostMedia::factory()->video()->create([
        'workspace_id' => $video->workspace_id,
        'disk' => 'public', 'path' => 'media/replacement.mp4', 'size_bytes' => 11,
    ]);
    $changed = new PublishContext(
        target: $original->target, segments: $original->segments, media: [$replacement],
        account: $original->account, credentials: $original->credentials,
    );

    $result = $connector->publish($changed);

    expect($result->outcome)->toBe('awaiting_action')
        ->and($result->statusMessage)->toContain('bound to a different video')
        ->and($result->remoteIds)->toBe($stage === 'uploaded' ? ['covered_42'] : [])
        ->and($provider->calls)->toBe($callsBeforeReplacement)
        ->and($changed->target->fresh()->media_upload_state)->toBe($stateBeforeReplacement)
        ->and($changed->target->fresh()->remote_id)->toBe($stage === 'uploaded' ? 'covered_42' : null);
})->with(['unknown initialization', 'unknown upload', 'uploaded']);

test('youtube refuses a replaced video when an older upload reference has no cover metadata', function () {
    [$context, $video] = youtubeThumbnailContext();
    $originalVideo = PostMedia::factory()->video()->create(['workspace_id' => $video->workspace_id]);
    $context->target->forceFill(['media_upload_state' => [
        $originalVideo->id => ['remote_ref' => 'retained-existing-session', 'state' => 'processing'],
    ]])->save();
    Http::fake();

    expect(app(YouTubeConnector::class)->publish($context)->outcome)->toBe('awaiting_action');
    Http::assertNothingSent();
});
