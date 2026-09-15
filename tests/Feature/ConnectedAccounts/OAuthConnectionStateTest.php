<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ConnectedAccounts\OAuthConnectionFlow;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    foreach (['tiktok', 'youtube'] as $platform) {
        config()->set("services.{$platform}.client_id", 'test-id');
        config()->set("services.{$platform}.client_secret", 'test-secret');
    }
    config()->set('services.tiktok.inbox_enabled', false);
    config()->set('services.youtube.publishing_enabled', false);
    ownerActingIn();
    $this->withCookie(config('session.cookie'), Str::random(40));
    Http::preventStrayRequests();
});

/** @return array<string, string> */
function startAccountOAuth(string $platform): array
{
    $response = test()->get('/accounts/connect/'.$platform)->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toMatch('/\A[A-Za-z0-9]{40}\z/');

    return $query;
}

function accountOAuthCallback(string $platform, string $state, string $code): string
{
    return '/accounts/callback/'.$platform.'?'.http_build_query(['state' => $state, 'code' => $code]);
}

function fakeStatefulAccountOAuth(): void
{
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => fn (ClientRequest $request) => Http::response([
            'access_token' => 'token-'.$request['code'], 'refresh_token' => 'refresh-'.$request['code'],
            'expires_in' => 3600, 'scope' => 'user.info.basic,user.info.profile,user.info.stats,video.list',
        ]),
        'open.tiktokapis.com/v2/user/info/*' => function (ClientRequest $request) {
            $token = $request->header('Authorization')[0];

            return Http::response(['data' => ['user' => [
                'open_id' => $token, 'username' => $token, 'display_name' => $token,
            ]]]);
        },
        'oauth2.googleapis.com/token' => fn (ClientRequest $request) => Http::response([
            'access_token' => 'token-'.$request['code'], 'refresh_token' => 'refresh-'.$request['code'],
            'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/youtube.readonly',
        ]),
        'www.googleapis.com/youtube/v3/channels*' => function (ClientRequest $request) {
            $token = $request->header('Authorization')[0];

            return Http::response(['items' => [['id' => $token, 'snippet' => ['title' => $token]]]]);
        },
    ]);
}

test('multiple providers and same-provider attempts finish out of order with matching PKCE', function () {
    fakeStatefulAccountOAuth();
    $this->withSession(['state' => 'google-login-state', 'code_verifier' => 'google-login-verifier']);
    $tikA = startAccountOAuth('tiktok');
    $youtubeA = startAccountOAuth('youtube');
    $tikB = startAccountOAuth('tiktok');
    $youtubeB = startAccountOAuth('youtube');

    foreach ([['youtube', $youtubeB, 'youtube-b'], ['tiktok', $tikA, 'tiktok-a'], ['youtube', $youtubeA, 'youtube-a'], ['tiktok', $tikB, 'tiktok-b']] as [$platform, $query, $code]) {
        $this->get(accountOAuthCallback($platform, $query['state'], $code))
            ->assertRedirect(route('accounts.index'))->assertSessionHas('success')
            ->assertSessionHas('state', 'google-login-state')->assertSessionHas('code_verifier', 'google-login-verifier');
        if ($platform === 'youtube') {
            Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['code'] === $code
                && rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge']);
        }
    }
    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(4);
});

test('an invalid TikTok callback cannot consume a pending YouTube connection', function () {
    fakeStatefulAccountOAuth();
    $youtube = startAccountOAuth('youtube');
    $this->get(accountOAuthCallback('tiktok', $youtube['state'], 'wrong-provider'))
        ->assertSessionHas('error', 'This TikTok connection link has expired or was already used. Start again from Connect account.');
    Http::assertNothingSent();
    $this->get(accountOAuthCallback('youtube', $youtube['state'], 'youtube-valid'))
        ->assertSessionHas('success', 'YouTube account connected.');
});

test('a successful callback is idempotent only for its exact state code provider and existing connection', function () {
    fakeStatefulAccountOAuth();
    $query = startAccountOAuth('youtube');
    $url = accountOAuthCallback('youtube', $query['state'], 'once');
    $this->get($url)->assertSessionHas('success');
    Http::assertSentCount(2);
    $this->get($url)->assertSessionHas('success')->assertSessionMissing('error');
    Http::assertSentCount(2);
    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(1);
    $this->get(accountOAuthCallback('youtube', $query['state'], 'different-code'))->assertSessionHas('error');
    $this->get(accountOAuthCallback('youtube', Str::random(40), 'once'))->assertSessionHas('error');
    ConnectedAccount::withoutGlobalScopes()->sole()->delete();
    $this->get($url)->assertSessionHas('error');
    Http::assertSentCount(2);
});

test('provider denial consumes only its matching attempt and preserves Google login state', function () {
    fakeStatefulAccountOAuth();
    $this->withSession(['state' => 'google-state', 'code_verifier' => 'google-verifier']);
    $first = startAccountOAuth('youtube');
    $second = startAccountOAuth('youtube');
    $this->get('/accounts/callback/youtube?'.http_build_query(['state' => $first['state'], 'error' => 'access_denied']))
        ->assertSessionHas('error', 'You declined to connect your YouTube account.')
        ->assertSessionHas('state', 'google-state')->assertSessionHas('code_verifier', 'google-verifier');
    Http::assertNothingSent();
    $this->get(accountOAuthCallback('youtube', $first['state'], 'replay'))->assertSessionHas('error');
    $this->get(accountOAuthCallback('youtube', $second['state'], 'second'))->assertSessionHas('success');
    Http::assertSentCount(2);
});

test('expired attempts reject without exchange even when newer attempts extend the cache bucket lifetime', function () {
    fakeStatefulAccountOAuth();
    $first = startAccountOAuth('youtube');
    $this->travel(29)->minutes();
    $second = startAccountOAuth('youtube');
    $this->travel(2)->minutes();
    $this->get(accountOAuthCallback('youtube', $first['state'], 'expired'))->assertSessionHas('error');
    Http::assertNothingSent();
    $this->get(accountOAuthCallback('youtube', $second['state'], 'current'))->assertSessionHas('success');
});

test('attempt storage is capped and PKCE is encrypted then erased when claimed', function () {
    $this->withSession(['state' => 'google-state', 'code_verifier' => 'google-verifier']);
    $first = startAccountOAuth('youtube');
    for ($index = 0; $index < 12; $index++) {
        $this->travel(7)->seconds();
        $latest = startAccountOAuth('youtube');
    }
    $key = 'connected-account-oauth:'.hash('sha256', session()->getId());
    $records = Cache::get($key);
    expect($records)->toHaveCount(12)->not->toHaveKey(hash('sha256', $first['state']));
    $encrypted = $records[hash('sha256', $latest['state'])]['verifier'];
    expect($encrypted)->not->toMatch('/\A[A-Za-z0-9]{96}\z/');

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'denied'], 400)]);
    $this->get(accountOAuthCallback('youtube', $latest['state'], 'failure'))->assertSessionHas('error')
        ->assertSessionHas('state', 'google-state')->assertSessionHas('code_verifier', 'google-verifier');
    $record = Cache::get($key)[hash('sha256', $latest['state'])];
    expect($record['status'])->toBe('failed')->and($record)->not->toHaveKey('verifier');
    $this->get(accountOAuthCallback('youtube', $latest['state'], 'failure'))->assertSessionHas('error');
    Http::assertSentCount(1);
});

test('a different user or workspace cannot consume a pending connection', function (string $changed) {
    fakeStatefulAccountOAuth();
    $query = startAccountOAuth('youtube');
    $user = auth()->user();
    $workspaceId = $user->current_workspace_id;
    if ($changed === 'user') {
        $other = User::factory()->create(['current_workspace_id' => $workspaceId]);
        Workspace::findOrFail($workspaceId)->members()->create(['user_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($other);
    } else {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->members()->create(['user_id' => $user->id, 'role' => 'owner']);
        $user->update(['current_workspace_id' => $workspace->id]);
    }
    $this->get(accountOAuthCallback('youtube', $query['state'], 'wrong-context'))->assertSessionHas('error');
    Http::assertNothingSent();
})->with(['user', 'workspace']);

test('fresh connection providers cannot return a cached Socialite user before state validation', function () {
    fakeStatefulAccountOAuth();
    $cached = Socialite::driver('youtube')->userFromToken('cached-token');
    expect($cached->getId())->toBe('Bearer cached-token');
    $fresh = app(OAuthConnectionFlow::class)->driver(Platform::YouTube);
    expect($fresh)->not->toBe(Socialite::driver('youtube'));
    $query = startAccountOAuth('youtube');
    $this->get(accountOAuthCallback('youtube', $query['state'], 'fresh-code'))->assertSessionHas('success');
    expect(ConnectedAccount::withoutGlobalScopes()->sole()->remote_account_id)->toBe('Bearer token-fresh-code');
});

test('missing malformed and replayed state never reaches the provider', function () {
    Http::fake();
    foreach (['', '?state[]=bad&code=bad', '?state=bad&code=bad', '?state='.Str::random(40).'&code=bad'] as $query) {
        $this->get('/accounts/callback/youtube'.$query)->assertSessionHas('error',
            'This YouTube connection link has expired or was already used. Start again from Connect account.');
    }
    Http::assertNothingSent();
});

test('a valid state without a code or denial remains pending without contacting the provider', function () {
    fakeStatefulAccountOAuth();
    $query = startAccountOAuth('youtube');
    $this->get('/accounts/callback/youtube?state='.$query['state'])->assertSessionHas('error');
    Http::assertNothingSent();
    $this->get(accountOAuthCallback('youtube', $query['state'], 'valid'))->assertSessionHas('success');
});

test('a different callback host cannot consume an authorization attempt', function () {
    fakeStatefulAccountOAuth();
    $query = startAccountOAuth('youtube');
    $this->get('https://another-host.test'.accountOAuthCallback('youtube', $query['state'], 'wrong-host'))
        ->assertSessionHas('error');
    Http::assertNothingSent();
    $this->get($query['redirect_uri'].'?'.http_build_query(['state' => $query['state'], 'code' => 'right-host']))
        ->assertSessionHas('success');
});

test('a callback already being processed cannot exchange its code twice', function () {
    fakeStatefulAccountOAuth();
    $query = startAccountOAuth('youtube');
    $request = Request::create(accountOAuthCallback('youtube', $query['state'], 'processing'));
    $request->setLaravelSession(session()->driver());
    $request->setUserResolver(fn () => auth()->user());
    app(OAuthConnectionFlow::class)->claim($request, Platform::YouTube);

    $this->get(accountOAuthCallback('youtube', $query['state'], 'processing'))->assertSessionHas('error');
    Http::assertNothingSent();
    $records = Cache::get('connected-account-oauth:'.hash('sha256', session()->getId()));
    expect($records[hash('sha256', $query['state'])]['status'])->toBe('processing')
        ->and($records[hash('sha256', $query['state'])])->not->toHaveKey('verifier');
});

test('a completed callback cannot claim a revoked or disabled account is still connected', function (string $change) {
    fakeStatefulAccountOAuth();
    $query = startAccountOAuth('youtube');
    $url = accountOAuthCallback('youtube', $query['state'], 'once');
    $this->get($url)->assertSessionHas('success');
    $account = ConnectedAccount::withoutGlobalScopes()->sole();
    match ($change) {
        'revoked' => $account->update(['status' => ConnectedAccountStatus::NeedsAttention]),
        'disabled' => $account->update(['disabled_at' => now()]),
        'secret-removed' => $account->secret()->delete(),
    };

    $this->get($url)->assertSessionHas('error');
    Http::assertSentCount(2);
})->with(['revoked', 'disabled', 'secret-removed']);
