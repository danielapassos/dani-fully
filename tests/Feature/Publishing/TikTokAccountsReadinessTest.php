<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\PostTarget;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TikTokAccounts\TikTokAccountsReadiness;
use App\Services\Publishing\TikTokPublishingRoute;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function accountsApiReadyAccount(array $overrides = []): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok, 'handle' => '@creator']);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'native-unchanged',
        'session' => ['tiktok_accounts' => array_replace([
            'account_id' => $account->id, 'workspace_id' => $account->workspace_id, 'handle' => $account->handle,
            'client_id' => 'business-app', 'open_id' => 'app-specific-open-id',
            'scopes' => TikTokAccountsReadiness::REQUIRED_SCOPES,
            'access_token' => 'business-private-token', 'refresh_token' => 'business-private-refresh',
            'expires_at' => now()->addHour()->timestamp, 'refresh_expires_at' => now()->addYear()->timestamp,
        ], $overrides)],
    ]);

    return $account;
}

beforeEach(function () {
    config()->set('services.tiktok.publishing_provider', 'accounts_api');
    config()->set('services.tiktok.direct_post_enabled', false);
    config()->set('services.tiktok.inbox_enabled', false);
    config()->set('services.tiktok_accounts', [
        'approved' => true, 'client_id' => 'business-app', 'client_secret' => 'private-app-secret',
        'authorization_url' => 'https://www.tiktok.com/v2/auth/authorize/',
        'redirect' => 'https://example.test/accounts/callback/tiktok-accounts/',
        'verified_url_prefix' => 'https://example.test/',
    ]);
    Http::preventStrayRequests();
});

test('Accounts API app approval blocks before account authorization and never calls native TikTok', function () {
    $account = accountsApiReadyAccount();
    config()->set('services.tiktok_accounts.approved', false);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain('app approval', 'Signing in again will not')
        ->and($account->publishingRecoveryKind())->toBe('operator_configuration');
    Http::assertNothingSent();
});

test('Accounts API authorization is separate from stale native tokens and status', function () {
    $account = accountsApiReadyAccount();
    $account->forceFill(['status' => ConnectedAccountStatus::NeedsAttention])->save();

    expect($account->canPublish())->toBeTrue()
        ->and($account->publishingUnavailableReason())->toBeNull()
        ->and($account->publishingRecoveryKind())->toBeNull()
        ->and($account->secret->access_token)->toBe('native-unchanged');
    Http::assertNothingSent();
});

test('Accounts API readiness requires the exact app workspace account and grants', function (array $overrides) {
    $account = accountsApiReadyAccount($overrides);
    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingRecoveryKind())->toBe('reconnect');
    Http::assertNothingSent();
})->with([
    'wrong app' => [['client_id' => 'another-app']],
    'wrong workspace' => [['workspace_id' => 'another-workspace']],
    'wrong account' => [['account_id' => 'another-account']],
    'wrong handle' => [['handle' => '@other']],
    'missing publishing scope' => [['scopes' => ['video.list', 'user.info.username']]],
    'missing public evidence scope' => [['scopes' => ['video.publish', 'user.info.username']]],
    'rejected refresh' => [['reconnect_required' => true]],
    'missing token' => [['access_token' => '']],
    'expired tokens' => [['expires_at' => 1, 'refresh_expires_at' => 1]],
]);

test('Accounts API refreshable expiry is not a request for another sign in', function () {
    $account = accountsApiReadyAccount(['expires_at' => now()->subMinute()->timestamp]);
    expect($account->canPublish())->toBeTrue();
    Http::assertNothingSent();
});

test('Accounts API publishing jobs never refresh or invalidate the native connection', function (bool $authorizationFails) {
    Bus::fake();
    Notification::fake();
    $account = accountsApiReadyAccount();
    $account->forceFill(['status' => ConnectedAccountStatus::NeedsAttention])->save();
    $target = publishTarget();
    $target->forceFill(['platform' => Platform::TikTok, 'connected_account_id' => $account->id])->save();
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    $calls = 0;
    bindConnector(function (PublishContext $context) use (&$calls, $authorizationFails): PublishResult {
        $calls++;
        expect($context->credentials)->toBe([]);

        return $authorizationFails
            ? PublishResult::failure(ErrorKind::AuthExpired, 'Renew the Accounts API authorization.', 401)
            : PublishResult::success(['123456789']);
    });

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($calls)->toBe(1)
        ->and($target->fresh()->status)->toBe($authorizationFails ? PostTargetStatus::Failed : PostTargetStatus::Published)
        ->and($account->fresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention)
        ->and($account->fresh()->secret->access_token)->toBe('native-unchanged');
    if ($authorizationFails) {
        expect($target->fresh()->error_kind)->toBe(ErrorKind::Unsupported);
    }
    Bus::assertNotDispatched(PublishPostTarget::class);
    Http::assertNothingSent();
})->with([true, false]);

test('a saved native job reports its original route blocker after the Accounts API is selected', function () {
    $account = accountsApiReadyAccount();
    $target = publishTarget();
    $target->forceFill([
        'platform' => Platform::TikTok, 'connected_account_id' => $account->id,
        'media_upload_state' => ['_tiktok_provider' => 'native'],
    ])->save();
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    bindConnector(fn () => throw new RuntimeException('The saved native route is unavailable.'));

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($target->fresh()->status)->toBe(PostTargetStatus::Skipped)
        ->and($target->fresh()->error_message)->toContain('original TikTok publishing route is disabled');
    Http::assertNothingSent();
});

test('Accounts API rejects incomplete app configuration without recommending sign in', function (string $key, mixed $value) {
    $account = accountsApiReadyAccount();
    config()->set('services.tiktok_accounts.'.$key, $value);
    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingRecoveryKind())->toBe('operator_configuration');
})->with([
    ['client_secret', ''], ['authorization_url', ''],
    ['verified_url_prefix', 'http://example.test/'],
    ['verified_url_prefix', 'https://user@example.test/'],
    ['verified_url_prefix', 'https://example.test/prefix'],
]);

test('provider selection preserves accepted native transfers and Accounts API operations', function () {
    $route = app(TikTokPublishingRoute::class);
    $native = new PostTarget(['platform' => Platform::TikTok, 'media_upload_state' => ['media' => ['remote_ref' => 'original-native-id']]]);
    expect($route->forTarget($native))->toBe('native');
    $business = new PostTarget(['platform' => Platform::TikTok, 'media_upload_state' => ['_tiktok_accounts' => ['publish_id' => 'share-id']]]);
    config()->set('services.tiktok.publishing_provider', 'native');
    expect($route->forTarget($business))->toBe('accounts_api')
        ->and($route->hasAcceptedOperation($business))->toBeTrue()
        ->and($route->requiresProviderDeletion($business))->toBeTrue();
});

test('conflicting provider state is blocked instead of silently choosing a route', function (array $state) {
    $target = new PostTarget(['platform' => Platform::TikTok, 'media_upload_state' => $state]);
    expect(fn () => app(TikTokPublishingRoute::class)->forTarget($target))->toThrow(RuntimeException::class, 'inconsistent');
})->with([
    [['_tiktok_accounts' => [], '_metricool' => []]],
    [['_tiktok_provider' => 'native', '_tiktok_accounts' => []]],
    [['_tiktok_provider' => 'accounts_api', 'media' => ['remote_ref' => 'native-id']]],
]);

test('pinning a stale target cannot erase an accepted or uncertain operation', function (array $operation) {
    $account = accountsApiReadyAccount();
    $target = PostTarget::factory()->create(['platform' => Platform::TikTok, 'connected_account_id' => $account->id, 'media_upload_state' => []]);
    $stale = $target->fresh();
    $target->forceFill(['media_upload_state' => ['_tiktok_provider' => 'accounts_api', '_tiktok_accounts' => $operation]])->save();
    config()->set('services.tiktok.publishing_provider', 'native');

    expect(app(TikTokPublishingRoute::class)->pin($stale))->toBe('accounts_api')
        ->and($stale->media_upload_state['_tiktok_accounts'])->toBe($operation)
        ->and($target->fresh()->media_upload_state['_tiktok_accounts'])->toBe($operation);
})->with([
    [['create_outcome_unknown' => true]],
    [['publish_id' => 'share-id', 'share_id' => 'share-id']],
]);
