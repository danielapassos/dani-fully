<?php

declare(strict_types=1);

namespace App\Services\Publishing\TikTokAccounts;

use App\Enums\Platform;
use App\Models\ConnectedAccount;

final class TikTokAccountsReadiness
{
    public const array REQUIRED_SCOPES = ['video.publish', 'user.info.username', 'video.list'];

    public function configurationReason(): ?string
    {
        if (config('services.tiktok_accounts.approved') !== true) {
            return 'TikTok Accounts API app approval is required. Signing in again will not resolve app approval.';
        }
        foreach (['client_id', 'client_secret', 'authorization_url', 'redirect'] as $key) {
            $value = config('services.tiktok_accounts.'.$key);
            if (! is_string($value) || trim($value) === '') {
                return 'Configure the approved TikTok Accounts API app and authorization URL on the server.';
            }
        }
        $prefix = config('services.tiktok_accounts.verified_url_prefix');
        if (! is_string($prefix) || ! $this->httpsPrefix($prefix)) {
            return 'Configure a media URL prefix verified for this TikTok Accounts API app.';
        }

        return null;
    }

    public function reason(ConnectedAccount $account): ?string
    {
        if ($account->platform !== Platform::TikTok) {
            return 'Choose a TikTok account for Accounts API publishing.';
        }
        if ($account->isDisabled()) {
            return 'This account is disabled. Re-enable it before posting.';
        }
        if (($reason = $this->configurationReason()) !== null) {
            return $reason;
        }
        $session = $account->secret?->session['tiktok_accounts'] ?? null;
        if (! is_array($session) || ! $this->bound($account, $session)) {
            return "Authorize {$account->handle} through the approved TikTok Accounts API connection. Your existing TikTok connection will be preserved.";
        }
        if (($session['reconnect_required'] ?? false) === true) {
            return "Renew {$account->handle}'s TikTok Accounts API authorization.";
        }
        $scopes = $session['scopes'] ?? null;
        if (! is_array($scopes) || array_diff(self::REQUIRED_SCOPES, $scopes) !== []) {
            return "Authorize {$account->handle} with TikTok Accounts API publishing, username, and video-list permissions.";
        }
        if (! is_string($session['access_token'] ?? null) || trim($session['access_token']) === '') {
            return "Renew {$account->handle}'s TikTok Accounts API authorization.";
        }
        $expires = $session['expires_at'] ?? null;
        $refreshExpires = $session['refresh_expires_at'] ?? null;
        if (! is_int($expires) || ($expires <= now()->timestamp
            && (! is_string($session['refresh_token'] ?? null) || $session['refresh_token'] === ''
                || ! is_int($refreshExpires) || $refreshExpires <= now()->timestamp))) {
            return "Renew {$account->handle}'s TikTok Accounts API authorization.";
        }

        return null;
    }

    /** @return 'enable_account'|'reconnect'|'operator_configuration'|null */
    public function recoveryKind(ConnectedAccount $account): ?string
    {
        if ($account->isDisabled()) {
            return 'enable_account';
        }
        if ($this->configurationReason() !== null) {
            return 'operator_configuration';
        }

        return $this->reason($account) === null ? null : 'reconnect';
    }

    /** @param array<string, mixed> $session */
    private function bound(ConnectedAccount $account, array $session): bool
    {
        return ($session['client_id'] ?? null) === config('services.tiktok_accounts.client_id')
            && ($session['workspace_id'] ?? null) === $account->workspace_id
            && ($session['account_id'] ?? null) === $account->id
            && is_string($session['open_id'] ?? null) && $session['open_id'] !== ''
            && is_string($session['handle'] ?? null)
            && strtolower(ltrim($session['handle'], '@')) === strtolower(ltrim($account->handle, '@'));
    }

    private function httpsPrefix(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && str_ends_with($value, '/');
    }
}
