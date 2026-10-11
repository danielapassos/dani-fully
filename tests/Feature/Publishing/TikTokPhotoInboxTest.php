<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\TikTokConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

function tikTokInboxPhoto(string $mime = 'image/jpeg'): PostMedia
{
    $photo = PostMedia::factory()->create([
        'disk' => 'local', 'mime' => $mime, 'size_bytes' => 11, 'width' => 1080, 'height' => 1350,
    ]);
    Storage::disk('local')->put($photo->path, 'photo-bytes');

    return $photo;
}

/**
 * @param  list<PostMedia>  $photos
 * @param  array<string, mixed>  $targetOverrides
 * @param  array<int, list<PostMedia>>  $placements
 */
function tikTokPhotoInboxContext(array $photos, string $caption = 'tabis until further notice\n#WoodChuckSato', array $targetOverrides = [], array $placements = []): PublishContext
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.upload']],
    ]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok, 'connected_account_id' => $account->id,
        'sections' => [$caption], ...$targetOverrides,
    ]);

    return new PublishContext($target, [$caption], $photos, $account, ['access_token' => 'photo-token'], $placements);
}

function fakeTikTokPhotoInbox(): void
{
    Http::fake([
        '*/content/init/' => Http::response(['data' => ['publish_id' => 'photo-publish-42'], 'error' => ['code' => 'ok']]),
        '*/status/fetch/' => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX'], 'error' => ['code' => 'ok']]),
    ]);
}

beforeEach(function (): void {
    config([
        'app.url' => 'https://shoutrrr.example.test',
        'services.tiktok.publishing_provider' => 'native',
        'services.tiktok.inbox_enabled' => true,
        'services.tiktok.direct_post_enabled' => false,
    ]);
    Storage::fake('local');
    Http::preventStrayRequests();
});

test('photo inbox sends ordered original URLs and exact caption without claiming publication or selecting music', function (): void {
    $first = tikTokInboxPhoto();
    $second = tikTokInboxPhoto('image/webp');
    $excluded = tikTokInboxPhoto();
    $caption = "tabis until further notice\n#WoodChuckSato #TabiBalletFlats 🩰";
    $context = tikTokPhotoInboxContext([$first, $second, $excluded], $caption, [], [0 => [$second, $first]]);
    fakeTikTokPhotoInbox();

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->outcome)->toBe('awaiting_action')
        ->and($result->statusMessage)->toContain('not a live post');
    Http::assertSent(function (Request $request) use ($first, $second, $caption): bool {
        if (! str_ends_with($request->url(), '/content/init/')) {
            return false;
        }
        expect($request['media_type'])->toBe('PHOTO')
            ->and($request['post_mode'])->toBe('MEDIA_UPLOAD')
            ->and($request['post_info'])->toBe(['description' => $caption])
            ->and($request['is_aigc'])->toBeFalse()
            ->and($request['source_info']['source'])->toBe('PULL_FROM_URL')
            ->and($request['source_info']['photo_cover_index'])->toBe(0);
        $urls = $request['source_info']['photo_images'];
        expect($urls)->toHaveCount(2);
        foreach ([$second, $first] as $index => $photo) {
            expect($urls[$index])->toStartWith('https://shoutrrr.example.test/provider-media/tiktok/'.$photo->id.'?')
                ->and(URL::hasValidSignature(Illuminate\Http\Request::create($urls[$index])))->toBeTrue();
        }

        return true;
    });
    $entries = array_filter($context->target->fresh()->media_upload_state, is_array(...));
    expect($entries)->toHaveCount(1)
        ->and($entries[$second->id]['remote_ref'])->toBe('photo-publish-42')
        ->and($entries[$second->id]['metadata']['connected_account_id'])->toBe($context->account->id)
        ->and($entries[$second->id]['metadata']['remote_account_id'])->toBe($context->account->remote_account_id)
        ->and($entries[$second->id]['metadata']['photo_ids'])->toBe([$second->id, $first->id])
        ->and($entries[$second->id]['metadata']['caption'])->toBe($caption)
        ->and(json_encode($entries))->not->toContain('signature=', 'photo-token');
    Http::assertSentCount(2);
});

test('photo inbox preserves an explicitly supplied AI disclosure', function (): void {
    $context = tikTokPhotoInboxContext([tikTokInboxPhoto()], targetOverrides: ['content_override' => ['tiktok' => ['is_aigc' => true]]]);
    fakeTikTokPhotoInbox();

    app(TikTokConnector::class)->publish($context);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/content/init/') && $request['is_aigc'] === true);
});

test('photo completion with public IDs still waits for ownership and visibility reconciliation', function (): void {
    $photo = tikTokInboxPhoto();
    $context = tikTokPhotoInboxContext([$photo]);
    Http::fake([
        '*/content/init/' => Http::response(['data' => ['publish_id' => 'photo-publish-42'], 'error' => ['code' => 'ok']]),
        '*/status/fetch/' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7788990011223344556']], 'error' => ['code' => 'ok']]),
    ]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->outcome)->toBe('completed')
        ->and($result->remoteIds)->toBe([])
        ->and($result->statusMessage)->toContain('ownership still need verification')
        ->and($context->target->fresh()->media_upload_state[$photo->id]['remote_ref'])->toBe('photo-publish-42');
    Http::assertSentCount(2);
});

test('new TikTok video submissions cannot ignore a saved photo music requirement', function (string $provider): void {
    config(['services.tiktok.publishing_provider' => $provider]);
    $video = PostMedia::factory()->video()->create();
    $context = tikTokPhotoInboxContext([$video], targetOverrides: ['content_override' => ['music' => ['track' => 'Required artist - song']]]);
    Http::fake();

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('music note');
    Http::assertNothingSent();
})->with(['native', 'metricool', 'accounts_api']);

test('music notes never block reconciliation of an already accepted native video upload', function (): void {
    $video = PostMedia::factory()->video()->create();
    $context = tikTokPhotoInboxContext([$video], targetOverrides: [
        'content_override' => ['music' => ['track' => 'Saved reminder']],
        'media_upload_state' => [$video->id => ['remote_ref' => 'existing-video', 'metadata' => ['publish_mode' => 'inbox', 'source' => 'PULL_FROM_URL']]],
    ]);
    Http::fake(['*/status/fetch/' => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX']])]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->outcome)->toBe('awaiting_action');
    Http::assertSentCount(1);
});

test('photo inbox rejects unsupported routes without a provider request', function (string $route): void {
    if ($route === 'direct') {
        config(['services.tiktok.direct_post_enabled' => true]);
    } else {
        config(['services.tiktok.publishing_provider' => $route]);
    }
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tikTokPhotoInboxContext([tikTokInboxPhoto()]));

    expect($result->errorKind)->toBe(ErrorKind::Unsupported);
    Http::assertNothingSent();
})->with(['direct', 'metricool', 'accounts_api']);

test('photo inbox requires upload authorization before initializing', function (): void {
    $context = tikTokPhotoInboxContext([tikTokInboxPhoto()]);
    $context->account->forceFill(['capabilities' => ['oauth_scopes' => ['video.publish']]])->save();
    Http::fake();

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::AuthExpired)->and($result->errorMessage)->toContain('video.upload');
    Http::assertNothingSent();
});

test('photo inbox rejects invalid image metadata before initializing', function (array $attributes): void {
    $photo = tikTokInboxPhoto();
    $photo->forceFill($attributes)->save();
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tikTokPhotoInboxContext([$photo]));

    expect($result->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
})->with([
    'empty photo' => [['size_bytes' => 0]],
    'oversized photo' => [['size_bytes' => 20_000_001]],
    'unsupported png' => [['mime' => 'image/png']],
    'unsupported gif' => [['mime' => 'image/gif']],
    'changed storage size' => [['size_bytes' => 20]],
]);

test('photo inbox rejects more than 35 photos and mixed photo video submissions', function (string $case): void {
    $photos = $case === 'too many' ? array_map(fn (): PostMedia => tikTokInboxPhoto(), range(1, 36))
        : [tikTokInboxPhoto(), PostMedia::factory()->video()->create()];
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tikTokPhotoInboxContext($photos));

    expect($result->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
})->with(['too many', 'mixed']);

test('photo inbox enforces UTF16 description length without truncation', function (int $emojiCount): void {
    fakeTikTokPhotoInbox();
    $caption = str_repeat('🩰', $emojiCount);
    $result = app(TikTokConnector::class)->publish(tikTokPhotoInboxContext([tikTokInboxPhoto()], $caption));

    if ($emojiCount === 2000) {
        expect($result->outcome)->toBe('awaiting_action');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/content/init/') && $request['post_info']['description'] === $caption);
    } else {
        expect($result->errorKind)->toBe(ErrorKind::Validation);
        Http::assertNothingSent();
    }
})->with([2000, 2001]);

test('photo inbox retains an uncertain init boundary without sending duplicate shares', function (string $failure): void {
    $context = tikTokPhotoInboxContext([tikTokInboxPhoto()]);
    Http::fake(fn () => match ($failure) {
        'connection' => throw new ConnectionException('Lost response containing secret'),
        'server' => Http::response(['error' => ['code' => 'internal_error']], 500),
        'redirect' => Http::response('', 302, ['Location' => 'https://other.example.test']),
        default => Http::response(['error' => ['code' => 'ok']]),
    });
    $connector = app(TikTokConnector::class);
    $first = $connector->publish($context);
    expect($first->errorKind)->toBe(ErrorKind::Unknown);
    Http::fake();

    $second = $connector->publish($context);

    expect($second->errorKind)->toBe(ErrorKind::Unknown);
    Http::assertNothingSent();
})->with(['connection', 'server', 'missing publish id', 'redirect']);

test('known photo inbox transfers keep polling when current media or route settings change', function (): void {
    $photo = tikTokInboxPhoto();
    $context = tikTokPhotoInboxContext([$photo]);
    fakeTikTokPhotoInbox();
    app(TikTokConnector::class)->publish($context);
    config(['services.tiktok.publishing_provider' => 'metricool', 'services.tiktok.inbox_enabled' => false, 'services.tiktok.direct_post_enabled' => true]);
    $changed = new PublishContext($context->target->fresh(), ['edited caption'], [], $context->account, $context->credentials);
    Http::fake(['*/status/fetch/' => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX']])]);

    $result = app(TikTokConnector::class)->publish($changed);

    expect($result->outcome)->toBe('awaiting_action');
    Http::assertSentCount(1);
});

test('photo inbox does not start a new share over an existing video transfer', function (): void {
    $context = tikTokPhotoInboxContext([tikTokInboxPhoto()], targetOverrides: ['media_upload_state' => [
        'old-video-id' => ['remote_ref' => 'existing-video-transfer', 'metadata' => ['publish_mode' => 'inbox']],
    ]]);
    Http::fake();

    expect(app(TikTokConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Unknown);
    Http::assertNothingSent();
});

test('photo inbox refuses a different creator reconnected onto its saved account row', function (string $initialState): void {
    $context = tikTokPhotoInboxContext([tikTokInboxPhoto()]);
    if ($initialState === 'accepted') {
        fakeTikTokPhotoInbox();
    } else {
        Http::fake(['*/content/init/' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);
    }
    app(TikTokConnector::class)->publish($context);
    $context->account->forceFill(['remote_account_id' => 'another-creator-open-id'])->save();
    Http::fake();

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('account identity differs');
    Http::assertNothingSent();
})->with(['accepted', 'retryable rejection']);

test('photo inbox refuses credentials from an account other than the selected target', function (): void {
    $context = tikTokPhotoInboxContext([tikTokInboxPhoto()]);
    $otherAccount = ConnectedAccount::factory()->create(['platform' => Platform::TikTok]);
    $mismatched = new PublishContext($context->target, $context->segments, $context->media, $otherAccount, $context->credentials);
    Http::fake();

    expect(app(TikTokConnector::class)->publish($mismatched)->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

test('photo initialization rate limits remain retryable without dropping the submitted caption snapshot', function (): void {
    $context = tikTokPhotoInboxContext([tikTokInboxPhoto()]);
    Http::fake(['*/content/init/' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '60'])]);

    $first = app(TikTokConnector::class)->publish($context);
    expect($first->errorKind)->toBe(ErrorKind::RateLimited)->and($first->retryAfter)->toBe(60);
    fakeTikTokPhotoInbox();

    expect(app(TikTokConnector::class)->publish($context)->outcome)->toBe('awaiting_action');
    Http::assertSentCount(2);
});

test('photo inbox retry after definitive failure preserves original media order and caption', function (bool $changeCaption): void {
    $context = tikTokPhotoInboxContext([tikTokInboxPhoto(), tikTokInboxPhoto()]);
    Http::fake([
        '*/content/init/' => Http::response(['data' => ['publish_id' => 'failed-photo-publish']]),
        '*/status/fetch/' => Http::response(['data' => ['status' => 'FAILED', 'fail_reason' => 'internal']]),
    ]);
    $first = app(TikTokConnector::class)->publish($context);
    expect($first->errorKind)->toBe(ErrorKind::ServerError);
    config(['services.tiktok.direct_post_enabled' => true]);
    $retry = new PublishContext($context->target->fresh(), $changeCaption ? ['changed'] : $context->segments, $context->media, $context->account, $context->credentials);
    fakeTikTokPhotoInbox();

    $result = app(TikTokConnector::class)->publish($retry);

    if ($changeCaption) {
        expect($result->errorKind)->toBe(ErrorKind::Validation);
        Http::assertNothingSent();
    } else {
        expect($result->outcome)->toBe('awaiting_action');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/content/init/') && $request['post_mode'] === 'MEDIA_UPLOAD');
    }
})->with([false, true]);

test('photo inbox rejects an unsafe application media origin without initializing', function (): void {
    config(['app.url' => 'http://shoutrrr.example.test']);
    Http::fake();

    expect(app(TikTokConnector::class)->publish(tikTokPhotoInboxContext([tikTokInboxPhoto()]))->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});
