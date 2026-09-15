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

function directTikTokOptions(array $overrides = []): array
{
    return array_replace([
        'privacy_level' => 'PUBLIC_TO_EVERYONE',
        'disable_comment' => true,
        'disable_duet' => true,
        'disable_stitch' => true,
        'commercial_content' => false,
        'brand_organic_toggle' => false,
        'brand_content_toggle' => false,
        'is_aigc' => false,
        'music_usage_confirmed' => true,
        'branded_content_policy_confirmed' => false,
    ], $overrides);
}

function directTikTokContext(array $options = [], array $state = []): PublishContext
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.publish']],
    ]);
    $video = PostMedia::factory()->video()->create([
        'disk' => 'local', 'path' => 'videos/direct.mp4', 'size_bytes' => 10, 'duration_seconds' => 5,
    ]);
    Storage::disk('local')->put($video->path, '0123456789');
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok,
        'connected_account_id' => $account->id,
        'content_override' => ['tiktok' => directTikTokOptions($options)],
        'media_upload_state' => $state === [] ? [] : [$video->id => $state],
    ]);

    return new PublishContext($target, ['Exact approved caption #test'], [$video], $account, ['access_token' => 'test-token']);
}

function fakeTikTokDirect(array $creator = [], string $status = 'PUBLISH_COMPLETE', array $ids = ['public-123'], mixed $init = null, mixed $statusResponse = null): void
{
    Http::fake([
        '*/creator_info/query/' => Http::response(['error' => ['code' => 'ok'], 'data' => array_replace([
            'creator_username' => 'dani', 'creator_nickname' => 'Dani',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
            'comment_disabled' => false, 'duet_disabled' => false, 'stitch_disabled' => false,
            'max_video_post_duration_sec' => 600,
        ], $creator)]),
        '*/video/init/' => $init ?? Http::response(['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'direct-session']]),
        '*/status/fetch/' => $statusResponse ?? Http::response(['error' => ['code' => 'ok'], 'data' => [
            'status' => $status, 'publicaly_available_post_id' => $ids,
        ]]),
    ]);
}

beforeEach(function () {
    config()->set('services.tiktok.direct_post_enabled', true);
    config()->set('services.tiktok.inbox_enabled', false);
    config()->set('app.url', 'https://shoutrrr.example.test');
    config()->set('media.public_url', 'https://media.example.test');
    Storage::fake('local');
    Http::preventStrayRequests();
});

test('direct post uses the latest creator restrictions and exact reviewed options with a server media URL', function () {
    fakeTikTokDirect();
    $context = directTikTokContext(['disable_comment' => false, 'video_cover_timestamp_ms' => 1000]);
    $result = app(TikTokConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['public-123'])
        ->and($context->target->fresh()->media_upload_state[$context->media[0]->id]['remote_ref'])->toBe('direct-session');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/video/init/')
        && ! str_contains($request->url(), '/inbox/')
        && $request['post_info']['title'] === 'Exact approved caption #test'
        && $request['post_info']['privacy_level'] === 'PUBLIC_TO_EVERYONE'
        && $request['post_info']['disable_comment'] === false
        && $request['post_info']['disable_duet'] === true
        && $request['post_info']['video_cover_timestamp_ms'] === 1000
        && $request['source_info']['source'] === 'PULL_FROM_URL'
        && str_starts_with($request['source_info']['video_url'], 'https://shoutrrr.example.test/provider-media/tiktok/'.$context->media[0]->id.'?')
        && URL::hasValidSignature(Illuminate\Http\Request::create($request['source_info']['video_url'])));
    Http::assertSentCount(3);
});

test('direct post never substitutes an inbox upload for a missing direct grant', function () {
    Http::fake();
    $context = directTikTokContext();
    $context->account->forceFill(['capabilities' => ['oauth_scopes' => ['video.upload']]])->save();

    $result = app(TikTokConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::AuthExpired);
    Http::assertNothingSent();
});

test('direct post blocks options that no longer match creator settings before initializing', function (array $options, array $creator) {
    fakeTikTokDirect($creator);
    $result = app(TikTokConnector::class)->publish(directTikTokContext($options));

    expect($result->errorKind)->toBe(ErrorKind::Validation);
    Http::assertSentCount(1);
})->with([
    'no default privacy' => [['privacy_level' => null], []],
    'privacy changed' => [[], ['privacy_level_options' => ['SELF_ONLY']]],
    'comments disabled' => [['disable_comment' => false], ['comment_disabled' => true]],
    'duration limit changed' => [[], ['max_video_post_duration_sec' => 3]],
    'missing consent' => [['music_usage_confirmed' => false], []],
    'private branded post' => [['privacy_level' => 'SELF_ONLY', 'commercial_content' => true, 'brand_content_toggle' => true, 'branded_content_policy_confirmed' => true], []],
]);

test('lost direct initialization response persists uncertainty and cannot create another submission', function () {
    fakeTikTokDirect(init: fn () => throw new ConnectionException('lost reply'));
    $context = directTikTokContext();
    $first = app(TikTokConnector::class)->publish($context);
    $second = app(TikTokConnector::class)->publish($context);

    expect($first->errorKind)->toBe(ErrorKind::Unknown)
        ->and($second->errorKind)->toBe(ErrorKind::Unknown)
        ->and($context->target->fresh()->media_upload_state[$context->media[0]->id]['metadata']['init_outcome_unknown'])->toBeTrue();
    Http::assertSentCount(1);
});

test('a direct session is resumed without another init or changed privacy', function () {
    fakeTikTokDirect(statusResponse: Http::sequence()
        ->push(['error' => ['code' => 'ok'], 'data' => ['status' => 'PROCESSING_DOWNLOAD']])
        ->push(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE']]));
    $context = directTikTokContext(['privacy_level' => 'SELF_ONLY']);
    $first = app(TikTokConnector::class)->publish($context);
    $context->target->forceFill(['content_override' => ['tiktok' => directTikTokOptions()]])->save();
    $second = app(TikTokConnector::class)->publish($context);

    expect($first->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($second->outcome)->toBe('completed')
        ->and($second->statusMessage)->toContain('private')
        ->and($second->remoteIds)->toBe([]);
});

test('enabling direct post preserves an existing inbox submission and truthful terminal status', function () {
    fakeTikTokDirect(status: 'SEND_TO_USER_INBOX', ids: []);
    $context = directTikTokContext([], ['remote_ref' => 'retained-inbox', 'state' => 'processing']);
    $context->account->forceFill(['capabilities' => ['oauth_scopes' => ['video.upload']]])->save();
    $result = app(TikTokConnector::class)->publish($context);

    expect($result->outcome)->toBe('awaiting_action')
        ->and($result->remoteIds)->toBe([])
        ->and($context->target->fresh()->media_upload_state[$context->media[0]->id]['remote_ref'])->toBe('retained-inbox');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/status/fetch/'));
});

test('direct init provider review failures explain the gate without downgrading privacy or using the inbox', function (string $code, string $message) {
    fakeTikTokDirect(init: Http::response(['error' => ['code' => $code]], 403));
    $context = directTikTokContext();
    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain($message);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/inbox/'));
})->with([
    ['unaudited_client_can_only_post_to_private_accounts', 'not approved'],
    ['url_ownership_unverified', 'Verify the media URL'],
]);

test('an inbox failure retains its mode when direct posting is enabled later', function () {
    $context = directTikTokContext();
    $media = $context->effectiveMedia()[0];
    $context->target->forceFill(['media_upload_state' => [
        $media->id => ['remote_ref' => 'old-inbox', 'metadata' => ['publish_mode' => 'inbox']],
    ]])->save();
    Http::fake([
        '*/status/fetch/' => Http::sequence()
            ->push(['error' => ['code' => 'ok'], 'data' => ['status' => 'FAILED', 'fail_reason' => 'internal']])
            ->push(['error' => ['code' => 'ok'], 'data' => ['status' => 'SEND_TO_USER_INBOX']]),
        '*/inbox/video/init/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'new-inbox', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=retry-id&upload_token=retry-token']]),
        'https://open-upload.tiktokapis.com/*' => Http::response([], 201),
    ]);
    $connector = app(TikTokConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::ServerError);
    $context->target->refresh();
    expect($connector->publish($context)->outcome)->toBe('awaiting_action');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/publish/video/init/'));
});

test('an inbox initialization retry cannot change to direct posting after a configuration change', function () {
    config()->set('services.tiktok.inbox_enabled', true);
    config()->set('services.tiktok.direct_post_enabled', false);
    $context = directTikTokContext();
    Http::fake([
        '*/inbox/video/init/' => Http::sequence()
            ->push(['error' => ['code' => 'rate_limit_exceeded']], 429)
            ->push(['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'inbox-retry', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=retry-id&upload_token=retry-token']]),
        'https://open-upload.tiktokapis.com/*' => Http::response([], 201),
        '*/status/fetch/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'SEND_TO_USER_INBOX']]),
    ]);
    $connector = app(TikTokConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::RateLimited);
    config()->set('services.tiktok.direct_post_enabled', true);
    $context->target->refresh();
    expect($connector->publish($context)->outcome)->toBe('awaiting_action');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/publish/video/init/'));
});
