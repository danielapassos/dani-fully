<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Services\Auth\TikTokAccountsOAuthProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TikTokAccountsTokenManager
{
    public function __construct(private TikTokAccountsOAuthProvider $provider) {}

    /** @return array{access_token: string, open_id: string, client_id: string} */
    public function fresh(ConnectedAccount $account, bool $force = false): array
    {
        $this->provider->assertConfigured();

        $result = Cache::lock('tiktok-accounts-credentials:'.$account->id, 60)->block(5, function () use ($account, $force): array {
            return DB::transaction(function () use ($account, $force): array {
                $locked = ConnectedAccount::withoutGlobalScopes()->lockForUpdate()->find($account->id);
                $secret = ConnectedAccountSecret::query()->lockForUpdate()->find($account->id);
                $binding = $this->binding($locked, $secret, $account);
                if ($force || $binding['expires_at'] <= now()->addSeconds(120)->timestamp) {
                    if (! is_int($binding['refresh_expires_at'] ?? null) || $binding['refresh_expires_at'] <= now()->timestamp) {
                        return $this->failed($secret, $binding, 'TikTok Accounts authorization has expired. Reconnect this account.');
                    }
                    try {
                        $tokens = $this->provider->refresh($binding['refresh_token']);
                        if ($tokens['open_id'] !== $binding['open_id']
                            || $this->provider->handle($tokens['access_token'], $tokens['open_id']) !== $binding['handle']) {
                            throw new TokenRefreshException('TikTok Accounts identity changed. Reconnect the original account.');
                        }
                    } catch (TokenRefreshException $exception) {
                        return $this->failed($secret, $binding, $exception->getMessage());
                    }
                    $binding = array_replace($binding, $tokens, ['reconnect_required' => false, 'failure_reason' => null]);
                    $secret->forceFill(['session' => array_replace($secret->session ?? [], ['tiktok_accounts' => $binding])])->save();
                }

                return ['access_token' => $binding['access_token'], 'open_id' => $binding['open_id'], 'client_id' => $binding['client_id']];
            });
        });
        if (isset($result['error'])) {
            throw new TokenRefreshException($result['error']);
        }

        return $result;
    }

    /** Revoke only Accounts API authorization, retaining the native Login Kit credentials. */
    public function revoke(ConnectedAccount $account): void
    {
        $this->provider->assertConfigured();
        Cache::lock('tiktok-accounts-credentials:'.$account->id, 60)->block(5, function () use ($account): void {
            $binding = DB::transaction(function () use ($account): array {
                $locked = ConnectedAccount::withoutGlobalScopes()->lockForUpdate()->find($account->id);
                $secret = ConnectedAccountSecret::query()->lockForUpdate()->find($account->id);
                $binding = $this->binding($locked, $secret, $account);
                $secret->forceFill(['session' => array_replace($secret->session ?? [], ['tiktok_accounts' => array_replace($binding, [
                    'reconnect_required' => true, 'failure_reason' => 'TikTok Accounts authorization was disconnected.',
                    'revoked_at' => now()->timestamp,
                ])])])->save();

                return $binding;
            });
            // Commit the local revocation before the request: an uncertain remote result stays disabled.
            $this->provider->revoke($binding['access_token']);
        });
    }

    /** @return array<string, mixed> */
    private function binding(?ConnectedAccount $locked, ?ConnectedAccountSecret $secret, ConnectedAccount $expected): array
    {
        $binding = $secret?->session['tiktok_accounts'] ?? null;
        if ($locked === null || $secret === null || $locked->platform !== Platform::TikTok
            || $locked->workspace_id !== $expected->workspace_id || ! is_array($binding)
            || ($binding['workspace_id'] ?? null) !== $locked->workspace_id
            || ($binding['account_id'] ?? null) !== $locked->id
            || ($binding['client_id'] ?? null) !== config('services.tiktok_accounts.client_id')
            || ($binding['handle'] ?? null) !== mb_strtolower(ltrim($locked->handle, '@'))
            || ($binding['reconnect_required'] ?? false) !== false || isset($binding['revoked_at'])
            || ! is_int($binding['expires_at'] ?? null)) {
            throw new TokenRefreshException('Reconnect this account with the configured TikTok Accounts app.');
        }
        foreach (['access_token', 'refresh_token', 'open_id', 'client_id'] as $key) {
            if (! is_string($binding[$key] ?? null) || $binding[$key] === '') {
                throw new TokenRefreshException('Reconnect this account with the configured TikTok Accounts app.');
            }
        }
        if (! is_array($binding['scopes'] ?? null) || array_diff(TikTokAccountsOAuthProvider::REQUIRED_SCOPES, $binding['scopes']) !== []) {
            throw new TokenRefreshException('Reconnect this account and grant TikTok Accounts publishing permissions.');
        }

        return $binding;
    }

    /** @param array<string, mixed> $binding
     * @return array{error: string}
     */
    private function failed(ConnectedAccountSecret $secret, array $binding, string $reason): array
    {
        $secret->forceFill(['session' => array_replace($secret->session ?? [], ['tiktok_accounts' => array_replace($binding, [
            'reconnect_required' => true, 'failure_reason' => $reason,
        ])])])->save();

        return ['error' => $reason];
    }
}
