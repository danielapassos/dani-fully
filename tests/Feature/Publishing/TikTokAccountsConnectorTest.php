<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Auth\TikTokAccountsOAuthProvider;
use App\Services\Publishing\Connectors\TikTokAccountsConnector;
use App\Services\Publishing\TikTokAccounts\TikTokAccountsClient;
use App\Services\Publishing\TikTokAccountsTokenManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function accountsApiContext(array $options = [], array $mediaAttributes = []): PublishContext
{
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok, 'handle' => '@shesoutdooor']);
    $account->secret()->create(['access_token' => 'native-secret', 'session' => ['tiktok_accounts' => [
        'access_token' => 'accounts-secret', 'open_id' => 'accounts-open-id', 'client_id' => '12345',
        'account_id' => $account->id, 'workspace_id' => $account->workspace_id, 'handle' => 'shesoutdooor',
        'reconnect_required' => false, 'expires_at' => now()->addDay()->timestamp,
        'scopes' => TikTokAccountsOAuthProvider::REQUIRED_SCOPES,
    ]]]);
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    $media = PostMedia::factory()->video()->create([
        'post_id' => $post->id, 'workspace_id' => $account->workspace_id,
        'duration_seconds' => 12, 'size_bytes' => 1000, ...$mediaAttributes,
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::TikTok, 'connected_account_id' => $account->id,
        'sections' => ['The exact approved caption'],
        'content_override' => ['tiktok' => array_replace([
            'privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_comment' => true, 'disable_duet' => true, 'disable_stitch' => true,
            'commercial_content' => false, 'brand_organic_toggle' => false, 'brand_content_toggle' => false,
            'is_aigc' => true, 'music_usage_confirmed' => true, 'branded_content_policy_confirmed' => false,
        ], $options)],
    ]);
    $tokens = Mockery::mock(TikTokAccountsTokenManager::class);
    $tokens->shouldReceive('fresh')->with(Mockery::on(fn ($value): bool => $value->id === $account->id))
        ->andReturn(['access_token' => 'accounts-secret', 'open_id' => 'accounts-open-id', 'client_id' => '12345']);
    app()->instance(TikTokAccountsTokenManager::class, $tokens);

    return new PublishContext($target, $target->sections, [$media], $account, []);
}

function fakeAccountsApi(?Closure $override = null): void
{
    Http::fake(function (Request $request) use ($override) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($override && ($response = $override($request, $path)) !== null) {
            return $response;
        }
        $data = match ($path) {
            '/open_api/v1.3/business/get/' => ['username' => 'shesoutdooor', 'display_name' => 'Dani', 'profile_image' => 'https://avatar.test/a.jpg'],
            '/open_api/v1.3/business/video/settings/' => [
                'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'], 'comment_disabled' => false,
                'duet_disabled' => false, 'stitch_disabled' => false, 'max_video_post_duration_sec' => 600,
            ],
            '/open_api/v1.3/business/property/list/' => ['url_property_info_list' => [
                ['property_type' => 2, 'property_status' => 1, 'url' => 'https://shoutrrr.test/provider-media/tiktok/'],
            ]],
            '/open_api/v1.3/business/video/publish/' => ['share_id' => 'v_pub_url~accounts-operation'],
            '/open_api/v1.3/business/publish/status/' => ['status' => 'PUBLISH_COMPLETE', 'post_ids' => ['7680000000000000001']],
            '/open_api/v1.3/business/video/list/' => ['videos' => [[
                'item_id' => '7680000000000000001',
                'share_url' => 'https://www.tiktok.com/@shesoutdooor/video/7680000000000000001?utm_source=accounts',
            ]]],
            default => throw new RuntimeException('Unexpected Accounts API request.'),
        };

        return Http::response(['code' => 0, 'data' => $data]);
    });
}

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('app.url', 'https://shoutrrr.test');
    config()->set('services.tiktok_accounts.client_id', '12345');
    config()->set('services.tiktok_accounts.client_secret', 'business-app-secret');
    config()->set('services.tiktok_accounts.verified_url_prefix', 'https://shoutrrr.test/provider-media/tiktok/');
});

test('accounts API publishes exact public controls and records a matching public identity', function () {
    $context = accountsApiContext([
        'disable_comment' => false, 'video_cover_timestamp_ms' => 1200, 'commercial_content' => true,
        'brand_organic_toggle' => true, 'brand_content_toggle' => true, 'branded_content_policy_confirmed' => true,
    ]);
    fakeAccountsApi(function (Request $request, string $path) use ($context) {
        if (str_ends_with($path, '/publish/status/')) {
            $state = $context->target->fresh()->media_upload_state['_tiktok_accounts'];
            expect($state['share_id'])->toBe('v_pub_url~accounts-operation')
                ->and($state['publish_id'])->toBe('v_pub_url~accounts-operation')
                ->and($state['create_outcome_unknown'])->toBeFalse();
        }

        return null;
    });
    $result = app(TikTokAccountsConnector::class)->publish($context);
    expect($result->remoteIds)->toBe(['7680000000000000001']);
    $saved = $context->target->fresh()->media_upload_state;
    expect($saved['_tiktok_provider'])->toBe('accounts_api')
        ->and($saved['_tiktok_accounts']['public_url'])->toBe('https://www.tiktok.com/@shesoutdooor/video/7680000000000000001')
        ->and(json_encode($saved))->not->toContain('accounts-secret', 'business-app-secret', 'signature=');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/business/video/publish/')
        && $request->hasHeader('Access-Token', 'accounts-secret')
        && $request['business_id'] === 'accounts-open-id'
        && str_starts_with($request['video_url'], 'https://shoutrrr.test/provider-media/tiktok/')
        && $request['post_info'] === [
            'caption' => 'The exact approved caption', 'disable_comment' => false, 'disable_duet' => true, 'disable_stitch' => true,
            'is_brand_organic' => true, 'is_branded_content' => true, 'is_ai_generated' => true,
            'thumbnail_offset' => 1200, 'upload_to_draft' => false, 'is_ads_only' => false,
        ]);
    Http::assertNotSent(fn (Request $request): bool => ! str_starts_with($request->url(), 'https://business-api.tiktok.com/'));
});

test('accounts API exposes only the supported public choice', function () {
    $context = accountsApiContext();
    fakeAccountsApi();
    expect(app(TikTokAccountsClient::class)->creatorInfo($context->account)['privacy_level_options'])->toBe(['PUBLIC_TO_EVERYONE']);
});

test('accounts API never creates another submission after an unknown create response', function (string $failure) {
    $context = accountsApiContext();
    $creates = 0;
    fakeAccountsApi(function (Request $request, string $path) use ($failure, $context, &$creates) {
        if (! str_ends_with($path, '/video/publish/')) {
            return null;
        }
        $creates++;
        expect($context->target->fresh()->media_upload_state['_tiktok_accounts']['create_outcome_unknown'])->toBeTrue();

        return match ($failure) {
            'timeout' => throw new ConnectionException('secret=never-log'),
            'server' => Http::response(['code' => 50000, 'message' => 'never-log'], 503),
            'service-code' => Http::response(['code' => 51003, 'message' => 'never-log']),
            'partial' => Http::response(['code' => 20001, 'data' => ['share_id' => 'maybe']]),
            'malformed' => Http::response(['code' => 0, 'data' => []]),
            'missing-code' => Http::response(['data' => ['share_id' => 'maybe']]),
        };
    });
    $connector = app(TikTokAccountsConnector::class);
    $first = $connector->publish($context);
    $second = $connector->publish($context);
    expect($first->errorKind)->toBe(ErrorKind::Unknown)->and($first->errorMessage)->not->toContain('never-log')
        ->and($second->errorKind)->toBe(ErrorKind::Unknown)
        ->and($context->target->fresh()->media_upload_state['_tiktok_accounts']['create_outcome_unknown'])->toBeTrue();
    expect($creates)->toBe(1);
})->with(['timeout', 'server', 'service-code', 'partial', 'malformed', 'missing-code']);

test('accounts API keeps a definite rejection distinct from an unknown submission', function () {
    $context = accountsApiContext();
    fakeAccountsApi(fn (Request $request, string $path) => str_ends_with($path, '/video/publish/')
        ? Http::response(['code' => 40002, 'message' => 'secret=never-log', 'request_id' => 'SAFE1234']) : null);
    $result = app(TikTokAccountsConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::Validation)->and($result->errorMessage)->not->toContain('never-log')
        ->and($context->target->fresh()->media_upload_state['_tiktok_accounts']['create_outcome_unknown'])->toBeFalse()
        ->and($context->target->fresh()->media_upload_state['_tiktok_accounts']['last_rejection']['code'])->toBe(40002);
});

test('accounts API resumes the same publishing task after a lost polling response', function () {
    $context = accountsApiContext();
    $polls = 0;
    fakeAccountsApi(function (Request $request, string $path) use (&$polls) {
        if (str_ends_with($path, '/publish/status/') && ++$polls === 1) {
            throw new ConnectionException('never-log');
        }

        return null;
    });
    $connector = app(TikTokAccountsConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::Network)
        ->and($connector->publish($context)->remoteIds)->toBe(['7680000000000000001']);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
});

test('accounts API does not mark pending public evidence as published', function (array $status, string $outcome, ?ErrorKind $kind) {
    $context = accountsApiContext();
    fakeAccountsApi(fn (Request $request, string $path) => str_ends_with($path, '/publish/status/')
        ? Http::response(['code' => 0, 'data' => $status]) : null);
    $connector = app(TikTokAccountsConnector::class);
    $result = $connector->publish($context);
    $connector->publish($context);
    expect($result->remoteIds)->toBe([])->and($result->errorKind)->toBe($kind)->and($result->outcome)->toBe($outcome);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
})->with([
    [['status' => 'PROCESSING_DOWNLOAD'], 'published', ErrorKind::MediaProcessing],
    [['status' => 'PUBLISH_COMPLETE', 'post_ids' => []], 'published', ErrorKind::MediaProcessing],
    [['status' => 'PUBLISH_COMPLETE', 'post_ids' => ['v_pub_url~operation']], 'published', ErrorKind::Unknown],
    [['status' => 'FAILED', 'reason' => 'never-log'], 'published', ErrorKind::Validation],
    [['status' => 'SEND_TO_USER_INBOX'], 'awaiting_action', null],
    [['status' => 'NEW_UNKNOWN_STATUS'], 'published', ErrorKind::Unknown],
]);

test('accounts API refuses mismatched or unsafe public URLs', function (string $url) {
    $context = accountsApiContext();
    fakeAccountsApi(fn (Request $request, string $path) => str_ends_with($path, '/video/list/')
        ? Http::response(['code' => 0, 'data' => ['videos' => [['item_id' => '7680000000000000001', 'share_url' => $url]]]]) : null);
    $result = app(TikTokAccountsConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::Unknown)->and($result->remoteIds)->toBe([]);
})->with([
    'https://www.tiktok.com/@another-account/video/7680000000000000001',
    'https://www.tiktok.com/@shesoutdooor/video/7680000000000000002',
    'https://www.tiktok.com.attacker.test/@shesoutdooor/video/7680000000000000001',
    'https://credentials@www.tiktok.com/@shesoutdooor/video/7680000000000000001',
    'http://www.tiktok.com/@shesoutdooor/video/7680000000000000001',
]);

test('accounts API never submits private settings or unsupported media', function (array $options, array $media) {
    $context = accountsApiContext($options, $media);
    fakeAccountsApi();
    expect(app(TikTokAccountsConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with([
    [['privacy_level' => 'SELF_ONLY'], []], [[], ['size_bytes' => 1073741825]],
    [[], ['duration_seconds' => 2]], [[], ['duration_seconds' => 601]], [[], ['width' => 359]],
    [[], ['height' => null]], [[], ['mime' => 'video/x-msvideo']],
    [['music_usage_confirmed' => false], []],
]);

test('accounts API blocks a changed identity or unavailable public visibility before submission', function (string $changed) {
    $context = accountsApiContext();
    fakeAccountsApi(function (Request $request, string $path) use ($changed) {
        if ($changed === 'identity' && str_ends_with($path, '/business/get/')) {
            return Http::response(['code' => 0, 'data' => ['username' => 'another-account']]);
        }
        if ($changed === 'private' && str_ends_with($path, '/video/settings/')) {
            return Http::response(['code' => 0, 'data' => [
                'privacy_level_options' => ['SELF_ONLY'], 'comment_disabled' => false,
                'duet_disabled' => false, 'stitch_disabled' => false, 'max_video_post_duration_sec' => 600,
            ]]);
        }

        return null;
    });
    expect(app(TikTokAccountsConnector::class)->publish($context)->isSuccessful())->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with(['identity', 'private']);

test('accounts API requires live URL ownership for the configured Business application', function (array $property) {
    $context = accountsApiContext();
    fakeAccountsApi(fn (Request $request, string $path) => str_ends_with($path, '/property/list/')
        ? Http::response(['code' => 0, 'data' => ['url_property_info_list' => [$property]]]) : null);
    expect(app(TikTokAccountsConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Unsupported);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with([
    [['property_type' => 2, 'property_status' => 0, 'url' => 'https://shoutrrr.test/provider-media/tiktok/']],
    [['property_type' => 2, 'property_status' => 1, 'url' => 'https://another.test/provider-media/tiktok/']],
    [['property_type' => 1, 'property_status' => 1, 'url' => 'rrr.test']],
]);

test('accounts API does not create after another worker crosses the mutation boundary', function () {
    $context = accountsApiContext();
    fakeAccountsApi(function (Request $request, string $path) use ($context) {
        if (str_ends_with($path, '/property/list/')) {
            $context->target->fresh()->forceFill(['media_upload_state' => [
                '_tiktok_provider' => 'accounts_api', '_tiktok_accounts' => ['create_outcome_unknown' => true],
            ]])->save();
        }

        return null;
    });
    expect(app(TikTokAccountsConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Unknown);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('accounts API refuses existing native state and never silently changes providers', function () {
    $context = accountsApiContext();
    $context->target->forceFill(['media_upload_state' => ['media-1' => ['metadata' => ['publish_mode' => 'direct']]]])->save();
    expect(app(TikTokAccountsConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Unknown);
    Http::assertNothingSent();
});

test('accounts API waits when the public video lookup does not match the status post', function () {
    $context = accountsApiContext();
    fakeAccountsApi(fn (Request $request, string $path) => str_ends_with($path, '/video/list/')
        ? Http::response(['code' => 0, 'data' => ['videos' => [['item_id' => '7680000000000000002', 'share_url' => 'https://www.tiktok.com/@shesoutdooor/video/7680000000000000002']]]]) : null);
    $result = app(TikTokAccountsConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)->and($result->retryAfter)->toBe(180)->and($result->remoteIds)->toBe([]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/video/list/')
        && $request['business_id'] === 'accounts-open-id'
        && json_decode($request['filters'], true) === ['video_ids' => ['7680000000000000001']]
        && json_decode($request['fields'], true) === ['item_id', 'share_url']);
});

test('accounts API requires the original caption cover and disclosures on a rejected retry', function () {
    $context = accountsApiContext(['video_cover_timestamp_ms' => 2000]);
    $creates = 0;
    fakeAccountsApi(function (Request $request, string $path) use (&$creates) {
        if (str_ends_with($path, '/video/publish/') && ++$creates === 1) {
            return Http::response(['code' => 40016, 'message' => 'rate limited']);
        }

        return null;
    });
    $connector = app(TikTokAccountsConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::RateLimited);
    $context->target->forceFill(['sections' => ['Edited caption'], 'content_override' => ['tiktok' => ['privacy_level' => 'SELF_ONLY']]])->save();
    $changed = new PublishContext($context->target, ['Edited caption'], $context->media, $context->account, []);
    expect($connector->publish($changed)->errorKind)->toBe(ErrorKind::Unknown)->and($creates)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['post_info']['caption'] === 'The exact approved caption'
        && $request['post_info']['thumbnail_offset'] === 2000
        && $request['post_info']['is_ai_generated'] === true);
});

test('accounts API blocks changed bindings for a saved operation without resubmitting', function (string $field, string $value) {
    $context = accountsApiContext();
    fakeAccountsApi(fn (Request $request, string $path) => str_ends_with($path, '/publish/status/')
        ? Http::response(['code' => 0, 'data' => ['status' => 'PROCESSING_DOWNLOAD']]) : null);
    $connector = app(TikTokAccountsConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);
    $state = $context->target->media_upload_state;
    $state['_tiktok_accounts'][$field] = $value;
    $context->target->forceFill(['media_upload_state' => $state])->save();
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::Unknown);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
})->with([['open_id', 'different-open-id'], ['expected_handle', 'someone-else'], ['workspace_id', 'different-workspace']]);

test('accounts API suppresses URL property request credentials in errors', function () {
    $context = accountsApiContext();
    fakeAccountsApi(function (Request $request, string $path) {
        if (str_ends_with($path, '/property/list/')) {
            expect($request['app_id'])->toBe('12345')->and($request['secret'])->toBe('business-app-secret');
            throw new ConnectionException('https://business-api.tiktok.com/?secret=business-app-secret');
        }

        return null;
    });
    $result = app(TikTokAccountsConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::Network)->and($result->errorMessage)->not->toContain('business-app-secret', '?secret=');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('accounts API accepts verified domain coverage with a hostname boundary', function () {
    $context = accountsApiContext();
    fakeAccountsApi(fn (Request $request, string $path) => str_ends_with($path, '/property/list/')
        ? Http::response(['code' => 0, 'data' => ['url_property_info_list' => [['property_type' => 1, 'property_status' => 1, 'url' => 'shoutrrr.test']]]]) : null);
    expect(app(TikTokAccountsConnector::class)->publish($context)->remoteIds)->toBe(['7680000000000000001']);
});

test('accounts API rejects incomplete saved operation identity before network access', function (array $saved) {
    $context = accountsApiContext();
    $context->target->forceFill(['media_upload_state' => ['_tiktok_provider' => 'accounts_api', '_tiktok_accounts' => $saved]])->save();
    expect(app(TikTokAccountsConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Unknown);
    Http::assertNothingSent();
})->with([
    [['share_id' => 'saved-share']], [['publish_id' => 'saved-publish']],
    [['share_id' => 'saved', 'publish_id' => 'saved']], [['create_outcome_unknown' => true]],
]);

test('accounts API refuses edits made while checking the provider before submission', function (string $change) {
    $context = accountsApiContext();
    fakeAccountsApi(function (Request $request, string $path) use ($context, $change) {
        if (str_ends_with($path, '/property/list/')) {
            match ($change) {
                'caption' => $context->target->fresh()->forceFill(['sections' => ['Changed while checking']])->save(),
                'options' => $context->target->fresh()->forceFill(['content_override' => ['tiktok' => ['privacy_level' => 'SELF_ONLY']]])->save(),
                'media' => $context->media[0]->fresh()->forceFill(['path' => 'replacement.mp4'])->save(),
                'selection' => $context->target->fresh()->forceFill(['placements_explicit' => true])->save(),
            };
        }

        return null;
    });
    expect(app(TikTokAccountsConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Unknown);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with(['caption', 'options', 'media', 'selection']);

test('accounts API rechecks authorization changed during property verification before any submission', function (string $change) {
    $context = accountsApiContext();
    fakeAccountsApi(function (Request $request, string $path) use ($context, $change) {
        if (str_ends_with($path, '/property/list/')) {
            $secret = $context->account->secret()->firstOrFail();
            if ($change === 'deleted') {
                $secret->delete();
            } else {
                $session = $secret->session;
                $session['tiktok_accounts'] = array_replace($session['tiktok_accounts'], match ($change) {
                    'revoked' => ['revoked_at' => now()->timestamp],
                    'reconnect' => ['reconnect_required' => true],
                    'rotated' => ['access_token' => 'replacement-secret'],
                    'expired' => ['expires_at' => now()->subSecond()->timestamp],
                    'scope' => ['scopes' => ['video.list']],
                    default => [$change => 'replacement-identity'],
                });
                $secret->forceFill(['session' => $session])->save();
            }
        }

        return null;
    });
    $result = app(TikTokAccountsConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::AuthExpired)
        ->and($result->errorMessage)->not->toContain('accounts-secret', 'replacement-secret')
        ->and($context->target->fresh()->media_upload_state['_tiktok_accounts']['create_outcome_unknown'] ?? false)->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    if ($change !== 'deleted') {
        expect($context->account->secret()->firstOrFail()->access_token)->toBe('native-secret');
    }
})->with(['deleted', 'revoked', 'reconnect', 'rotated', 'expired', 'scope', 'open_id', 'client_id', 'account_id', 'workspace_id', 'handle']);
