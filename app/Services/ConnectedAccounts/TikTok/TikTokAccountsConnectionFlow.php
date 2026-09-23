<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Services\Auth\TikTokAccountsOAuthProvider;
use App\Services\Publishing\TokenManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TikTokAccountsConnectionFlow
{
    public function __construct(private TikTokAccountsOAuthProvider $provider, private TokenManager $nativeTokens) {}

    public function redirect(Request $request, ConnectedAccount $account): string
    {
        $this->authorize($request, $account);
        $state = Str::random(64);
        $url = $this->provider->authorizationUrl($state);
        $callback = (string) config('services.tiktok_accounts.redirect');
        if ($callback !== rtrim(route('accounts.tiktok-accounts.callback'), '/').'/') {
            throw new TokenRefreshException('TikTok Accounts callback must use this Shoutrrr instance.');
        }
        Cache::put($this->key($request, $state), [
            'user_id' => $request->user()->id, 'workspace_id' => $account->workspace_id,
            'account_id' => $account->id, 'handle' => $account->handle,
            'native_id' => $account->remote_account_id,
            'configuration' => $this->configuration(),
        ], now()->addMinutes(10));

        return $url;
    }

    public function complete(Request $request): ConnectedAccount
    {
        $state = $request->query('state');
        if (! is_string($state) || ! preg_match('/\A[A-Za-z0-9]{64}\z/', $state)) {
            throw new TokenRefreshException('This TikTok Accounts connection expired. Start again from the account card.');
        }
        $key = $this->key($request, $state);
        $attempt = Cache::lock($key.':lock', 10)->block(5, fn (): mixed => Cache::pull($key));
        if (! is_array($attempt) || ! is_string($attempt['account_id'] ?? null) || ($attempt['user_id'] ?? null) !== $request->user()->id
            || ($attempt['workspace_id'] ?? null) !== $request->user()->current_workspace_id
            || ($attempt['configuration'] ?? null) !== $this->configuration()) {
            throw new TokenRefreshException('This TikTok Accounts connection expired. Start again from the account card.');
        }
        $account = ConnectedAccount::withoutGlobalScopes()->find($attempt['account_id']);
        if ($account === null || $account->workspace_id !== $attempt['workspace_id']
            || $account->handle !== $attempt['handle'] || $account->remote_account_id !== $attempt['native_id']) {
            throw new TokenRefreshException('The selected TikTok account changed. Start authorization again.');
        }
        $this->authorize($request, $account);
        $code = $request->query('auth_code');
        if ($request->has('error') || ! is_string($code) || $code === '' || strlen($code) > 8192) {
            throw new TokenRefreshException('TikTok Accounts authorization was not granted.');
        }
        $tokens = $this->provider->exchange($code);
        $handle = $this->provider->handle($tokens['access_token'], $tokens['open_id']);
        if ($handle !== mb_strtolower(ltrim($account->handle, '@'))) {
            throw new TokenRefreshException('TikTok authorized a different account. Reconnect using the selected account.');
        }
        $account->load('secret');
        $existing = $account->secret?->session['tiktok_accounts'] ?? null;
        if (is_array($existing)) {
            if (($existing['open_id'] ?? null) !== $tokens['open_id']
                || ($existing['client_id'] ?? null) !== config('services.tiktok_accounts.client_id')) {
                throw new TokenRefreshException('TikTok Accounts identity does not match the existing authorization.');
            }
        } else {
            $this->verifyNativeIdentity($account, $handle);
        }

        return Cache::lock('tiktok-accounts-credentials:'.$account->id, 60)->block(5, function () use ($request, $account, $tokens, $handle, $existing): ConnectedAccount {
            return DB::transaction(function () use ($request, $account, $tokens, $handle, $existing): ConnectedAccount {
                $locked = ConnectedAccount::withoutGlobalScopes()->lockForUpdate()->find($account->id);
                if ($locked === null || $locked->workspace_id !== $account->workspace_id
                    || $locked->handle !== $account->handle || $locked->remote_account_id !== $account->remote_account_id) {
                    throw new TokenRefreshException('The selected TikTok account changed. Start authorization again.');
                }
                $this->authorize($request, $locked);
                $secret = ConnectedAccountSecret::query()->lockForUpdate()->find($locked->id);
                if ($secret === null || ($secret->session['tiktok_accounts'] ?? null) !== $existing) {
                    throw new TokenRefreshException('TikTok Accounts authorization changed. Start authorization again.');
                }
                $binding = array_replace($tokens, [
                    'client_id' => config('services.tiktok_accounts.client_id'),
                    'workspace_id' => $locked->workspace_id, 'account_id' => $locked->id, 'handle' => $handle,
                    'authorized_by_user_id' => $request->user()->id, 'authorized_at' => now()->timestamp,
                    'reconnect_required' => false, 'failure_reason' => null,
                ]);
                $secret->forceFill(['session' => array_replace($secret->session ?? [], ['tiktok_accounts' => $binding])])->save();

                return $locked;
            });
        });
    }

    private function verifyNativeIdentity(ConnectedAccount $account, string $handle): void
    {
        try {
            $credentials = $this->nativeTokens->fresh($account);
            $token = $credentials['access_token'] ?? null;
            if (! is_string($token) || $token === '') {
                throw new TokenRefreshException('Original credentials are unavailable.');
            }
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->acceptJson()->withToken($token)
                ->get('https://open.tiktokapis.com/v2/user/info/', ['fields' => 'open_id,username']);
            $user = $response->json('data.user');
            if (! $response->successful() || ! is_array($user)
                || ($user['open_id'] ?? null) !== $account->remote_account_id
                || ! is_string($user['username'] ?? null) || mb_strtolower($user['username']) !== $handle) {
                throw new TokenRefreshException('Identity mismatch.');
            }
        } catch (Throwable) {
            throw new TokenRefreshException('Verify the original TikTok connection before linking Accounts API publishing.');
        }
    }

    private function authorize(Request $request, ConnectedAccount $account): void
    {
        abort_unless($account->platform === Platform::TikTok && $account->disabled_at === null, 422);
        abort_unless($request->user()?->can('update', $account), 403);
    }

    private function key(Request $request, string $state): string
    {
        return 'tiktok-accounts-oauth:'.hash('sha256', $request->session()->getId().':'.$state);
    }

    private function configuration(): string
    {
        return hash('sha256', json_encode([
            config('services.tiktok_accounts.client_id'), config('services.tiktok_accounts.client_secret'),
            config('services.tiktok_accounts.redirect'), config('services.tiktok_accounts.authorization_url'),
        ], JSON_THROW_ON_ERROR));
    }
}
