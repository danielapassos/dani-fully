<?php

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Auth\TikTokAccountsOAuthProvider;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\Publishing\TikTokAccountsTokenManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    [$this->owner, $this->workspace] = ownerActingIn();
    URL::forceScheme('https');
    URL::forceRootUrl('https://localhost');
    $this->withCookie(config('session.cookie'), Str::random(40));
    config()->set('services.tiktok_accounts', [
        'client_id' => 'business-app', 'client_secret' => 'business-secret', 'approved' => true,
        'redirect' => 'https://localhost/accounts/callback/tiktok-accounts/',
        'authorization_url' => 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query([
            'client_key' => 'business-app', 'redirect_uri' => 'https://localhost/accounts/callback/tiktok-accounts/',
            'response_type' => 'code', 'scope' => implode(',', TikTokAccountsOAuthProvider::REQUIRED_SCOPES),
        ]),
    ]);
    $this->account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id, 'connected_by_user_id' => $this->owner->id,
        'platform' => Platform::TikTok, 'handle' => 'danicreator', 'remote_account_id' => 'native-open-id',
        'token_expires_at' => now()->addDay(),
    ]);
    $this->account->secret()->create(['access_token' => 'native-token', 'refresh_token' => 'native-refresh', 'session' => ['keep' => 'native-session']]);
    Http::preventStrayRequests();
});

function accountsApiTokenResponse(array $overrides = []): array
{
    return ['code' => 0, 'data' => array_replace([
        'access_token' => 'business-token', 'refresh_token' => 'business-refresh', 'open_id' => 'business-open-id',
        'scope' => implode(',', TikTokAccountsOAuthProvider::REQUIRED_SCOPES), 'token_type' => 'Bearer',
        'expires_in' => 86400, 'refresh_token_expires_in' => 31536000,
    ], $overrides)];
}

function fakeAccountsAuthorization(array $overrides = [], string $businessHandle = 'danicreator', string $nativeId = 'native-open-id'): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'business-api.tiktok.com/open_api/v1.3/tt_user/oauth2/token/' => Http::response(accountsApiTokenResponse($overrides)),
        'business-api.tiktok.com/open_api/v1.3/tt_user/oauth2/refresh_token/' => Http::response(accountsApiTokenResponse($overrides)),
        'business-api.tiktok.com/open_api/v1.3/tt_user/oauth2/revoke/' => Http::response(['code' => 0, 'data' => []]),
        'business-api.tiktok.com/open_api/v1.3/business/get/*' => Http::response(['code' => 0, 'data' => ['username' => $businessHandle]]),
        'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['open_id' => $nativeId, 'username' => 'danicreator']]]),
    ]);
}

function startAccountsAuthorization(): string
{
    $response = test()->get(route('accounts.tiktok-accounts.connect', test()->account))->assertRedirect();
    $response->assertSessionMissing('error');
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toHaveKeys(['state', 'client_key', 'redirect_uri', 'disable_auto_auth']);

    return $query['state'];
}

function completeAccountsAuthorization(string $state): TestResponse
{
    return test()->get(route('accounts.tiktok-accounts.callback').'?'.http_build_query(['state' => $state, 'auth_code' => 'one-use-code']));
}

function savedAccountsBinding(): array
{
    return test()->account->secret()->first()->session['tiktok_accounts'];
}

function authorizeAccounts(): void
{
    fakeAccountsAuthorization();
    completeAccountsAuthorization(startAccountsAuthorization())->assertSessionHas('success');
}

test('links a verified business identity while retaining native credentials and account id', function () {
    $expiry = $this->account->token_expires_at;
    authorizeAccounts();
    $secret = $this->account->secret()->first();
    $binding = savedAccountsBinding();
    expect($secret->access_token)->toBe('native-token')->and($secret->refresh_token)->toBe('native-refresh')
        ->and($secret->session['keep'])->toBe('native-session')
        ->and($binding)->toMatchArray(['open_id' => 'business-open-id', 'client_id' => 'business-app', 'account_id' => $this->account->id, 'workspace_id' => $this->workspace->id, 'handle' => 'danicreator'])
        ->and($binding['expires_at'])->toBeInt()
        ->and($this->account->fresh()->remote_account_id)->toBe('native-open-id')
        ->and($this->account->fresh()->token_expires_at->equalTo($expiry))->toBeTrue()
        ->and($secret->getRawOriginal('session'))->not->toContain('business-token');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/tt_user/oauth2/token/')
        && $request['auth_code'] === 'one-use-code' && $request['client_id'] === 'business-app'
        && $request['redirect_uri'] === 'https://localhost/accounts/callback/tiktok-accounts/');
});

test('callback is one use and cannot exchange its code twice', function () {
    fakeAccountsAuthorization();
    $state = startAccountsAuthorization();
    completeAccountsAuthorization($state)->assertSessionHas('success');
    completeAccountsAuthorization($state)->assertSessionHas('error');
    Http::assertSentCount(3);
});

test('authorization fails closed for unapproved configuration', function () {
    config()->set('services.tiktok_accounts.approved', false);
    $this->get(route('accounts.tiktok-accounts.connect', $this->account))->assertSessionHas('error');
    Http::assertNothingSent();
});

test('authorization rejects changed app callback or expired attempt before token exchange', function (string $change) {
    $state = startAccountsAuthorization();
    match ($change) {
        'client' => config()->set('services.tiktok_accounts.client_id', 'other-app'),
        'secret' => config()->set('services.tiktok_accounts.client_secret', 'rotated-secret'),
        'identity' => $this->account->update(['remote_account_id' => 'replaced-native-id']),
        'expiry' => $this->travel(11)->minutes(),
        'workspace' => $this->owner->update(['current_workspace_id' => Workspace::factory()->create()->id]),
    };
    completeAccountsAuthorization($state)->assertSessionHas('error');
    Http::assertNothingSent();
})->with(['client', 'secret', 'identity', 'expiry', 'workspace']);

test('does not link a different TikTok username or stale native identity', function (string $case) {
    fakeAccountsAuthorization([], $case === 'handle' ? 'someoneelse' : 'danicreator', $case === 'native' ? 'someoneelse' : 'native-open-id');
    completeAccountsAuthorization(startAccountsAuthorization())->assertSessionHas('error');
    expect($this->account->secret()->first()->session)->not->toHaveKey('tiktok_accounts');
})->with(['handle', 'native']);

test('requires all publishing scopes and hides provider error details', function () {
    fakeAccountsAuthorization(['scope' => 'user.info.username']);
    completeAccountsAuthorization(startAccountsAuthorization())->assertSessionHas('error');
    expect($this->account->secret()->first()->session)->not->toHaveKey('tiktok_accounts');
    Http::assertSentCount(1);
});

test('reauthorization cannot replace the pinned Accounts open id', function () {
    authorizeAccounts();
    fakeAccountsAuthorization(['open_id' => 'other-business-identity']);
    completeAccountsAuthorization(startAccountsAuthorization())->assertSessionHas('error');
    expect(savedAccountsBinding()['open_id'])->toBe('business-open-id');
});

test('native reconnect preserves separate authorization only for the same native identity', function (bool $sameIdentity) {
    authorizeAccounts();
    $data = new ConnectedAccountData(Platform::TikTok, $sameIdentity ? 'native-open-id' : 'new-native-id', 'danicreator', null, null, 'oauth', 'new-native-token');
    app(AccountConnectionService::class)->reconnect($this->account, $data, $this->owner);
    expect($this->account->secret()->first()->access_token)->toBe('new-native-token');
    if ($sameIdentity) {
        expect(savedAccountsBinding()['open_id'])->toBe('business-open-id');
    } else {
        expect($this->account->secret()->first()->session)->toBeNull();
    }
})->with([true, false]);

test('fresh Accounts tokens are read without a native or provider refresh', function () {
    authorizeAccounts();
    Http::fake();
    expect(app(TikTokAccountsTokenManager::class)->fresh($this->account))->toBe([
        'access_token' => 'business-token', 'open_id' => 'business-open-id', 'client_id' => 'business-app',
    ]);
    Http::assertNothingSent();
});

test('refresh rotates only the isolated Accounts token and rechecks identity', function () {
    authorizeAccounts();
    fakeAccountsAuthorization(['access_token' => 'rotated-business-token']);
    $tokens = app(TikTokAccountsTokenManager::class)->fresh($this->account, true);
    expect($tokens['access_token'])->toBe('rotated-business-token')
        ->and($this->account->secret()->first()->access_token)->toBe('native-token');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/tt_user/oauth2/refresh_token/')
        && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'business-refresh');
});

test('failed or changed-identity refresh blocks only Accounts authorization without replay', function (string $failure) {
    authorizeAccounts();
    if ($failure === 'identity') {
        fakeAccountsAuthorization(['open_id' => 'wrong-open-id']);
    } else {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40001, 'message' => 'secret-sensitive-value'], 401)]);
    }
    expect(fn () => app(TikTokAccountsTokenManager::class)->fresh($this->account, true))->toThrow(TokenRefreshException::class);
    expect(savedAccountsBinding()['reconnect_required'])->toBeTrue()
        ->and(savedAccountsBinding()['failure_reason'])->not->toContain('secret-sensitive-value')
        ->and($this->account->fresh()->status)->toBe(ConnectedAccountStatus::Active)
        ->and($this->account->secret()->first()->access_token)->toBe('native-token');
    Http::fake();
    expect(fn () => app(TikTokAccountsTokenManager::class)->fresh($this->account))->toThrow(TokenRefreshException::class);
    Http::assertNothingSent();
})->with(['identity', 'rejected']);

test('revoking Accounts authorization retains native credentials and prevents future use', function () {
    authorizeAccounts();
    app(TikTokAccountsTokenManager::class)->revoke($this->account);
    expect(savedAccountsBinding()['revoked_at'])->toBeInt()
        ->and($this->account->secret()->first()->access_token)->toBe('native-token');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/tt_user/oauth2/revoke/') && $request['access_token'] === 'business-token');
    expect(fn () => app(TikTokAccountsTokenManager::class)->fresh($this->account))->toThrow(TokenRefreshException::class);
});

test('actor switch cannot consume another users authorization', function () {
    $state = startAccountsAuthorization();
    $other = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->actingAs($other);
    completeAccountsAuthorization($state)->assertSessionHas('error');
    Http::assertNothingSent();
});

test('callback rechecks permission after workspace membership is revoked', function () {
    $state = startAccountsAuthorization();
    WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->owner->id)->delete();
    completeAccountsAuthorization($state)->assertSessionHas('error');
    Http::assertNothingSent();
});

test('provider denial consumes the attempt without token exchange', function () {
    $state = startAccountsAuthorization();
    $this->get(route('accounts.tiktok-accounts.callback').'?'.http_build_query(['state' => $state, 'error' => 'access_denied']))->assertSessionHas('error');
    completeAccountsAuthorization($state)->assertSessionHas('error');
    Http::assertNothingSent();
});

test('provider failures are sanitized and exchange is never retried', function (string $failure) {
    Http::fake(['business-api.tiktok.com/*' => match ($failure) {
        'redirect' => Http::response([], 302, ['Location' => 'https://attacker.invalid/secret']),
        'provider' => Http::response(['code' => 40001, 'message' => 'provider-secret-token-value'], 401),
        'connection' => Http::failedConnection('provider-secret-token-value'),
    }]);
    completeAccountsAuthorization(startAccountsAuthorization())->assertSessionHas('error', function (string $message) {
        return ! str_contains($message, 'provider-secret-token-value') && ! str_contains($message, 'attacker');
    });
    expect($this->account->secret()->first()->session)->not->toHaveKey('tiktok_accounts');
    if ($failure !== 'connection') {
        Http::assertSentCount(1);
    }
})->with(['redirect', 'provider', 'connection']);

test('refresh requires matching bound app account workspace and handle before any requests', function (string $field) {
    authorizeAccounts();
    $secret = $this->account->secret()->first();
    $session = $secret->session;
    $session['tiktok_accounts'][$field] = 'mismatched';
    $secret->update(['session' => $session]);
    Http::fake();
    expect(fn () => app(TikTokAccountsTokenManager::class)->fresh($this->account))->toThrow(TokenRefreshException::class);
    Http::assertNothingSent();
})->with(['client_id', 'account_id', 'workspace_id', 'handle']);

test('an expired refresh token sets only the isolated reconnect marker without network traffic', function () {
    authorizeAccounts();
    $secret = $this->account->secret()->first();
    $session = $secret->session;
    $session['tiktok_accounts']['expires_at'] = now()->subDay()->timestamp;
    $session['tiktok_accounts']['refresh_expires_at'] = now()->subSecond()->timestamp;
    $secret->update(['session' => $session]);
    Http::fake();
    expect(fn () => app(TikTokAccountsTokenManager::class)->fresh($this->account))->toThrow(TokenRefreshException::class);
    expect(savedAccountsBinding()['reconnect_required'])->toBeTrue();
    Http::assertNothingSent();
});

test('native store reconnect also preserves the isolated Accounts authorization', function () {
    authorizeAccounts();
    app(AccountConnectionService::class)->store(
        new ConnectedAccountData(Platform::TikTok, 'native-open-id', 'danicreator', null, null, 'oauth', 'renewed-native-token'),
        $this->owner,
        $this->workspace->id,
    );
    expect(savedAccountsBinding()['open_id'])->toBe('business-open-id')
        ->and($this->account->secret()->first()->access_token)->toBe('renewed-native-token');
});

test('callback rejects lost account-management permission', function () {
    $state = startAccountsAuthorization();
    config()->set('kit.workspaces.roles.owner.permissions', ['workspace.read']);
    completeAccountsAuthorization($state)->assertForbidden();
    Http::assertNothingSent();
});

test('uncertain remote revocation keeps Accounts blocked and retains native credentials', function () {
    authorizeAccounts();
    Http::swap(new Factory);
    Http::fake(['business-api.tiktok.com/*' => Http::failedConnection()]);
    expect(fn () => app(TikTokAccountsTokenManager::class)->revoke($this->account))->toThrow(TokenRefreshException::class);
    expect(savedAccountsBinding()['revoked_at'])->toBeInt()
        ->and($this->account->secret()->first()->access_token)->toBe('native-token');
});

test('rejects an authorization URL pointing at another host or application', function (string $url) {
    config()->set('services.tiktok_accounts.authorization_url', $url);
    $this->get(route('accounts.tiktok-accounts.connect', $this->account))->assertSessionHas('error');
    Http::assertNothingSent();
})->with([
    'http://www.tiktok.com/v2/auth/authorize/?client_key=business-app',
    'https://attacker.invalid/v2/auth/authorize/?client_key=business-app',
    'https://www.tiktok.com/v2/auth/authorize/?client_key=other-app',
    'https://www.tiktok.com:443/v2/auth/authorize/?client_key=business-app',
    'https://user@www.tiktok.com/v2/auth/authorize/?client_key=business-app',
]);
