<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\TokenRefreshException;
use Illuminate\Support\Facades\Http;
use Throwable;

/** TikTok for Business Accounts API, deliberately separate from Login Kit. */
class TikTokAccountsOAuthProvider
{
    public const array REQUIRED_SCOPES = ['video.publish', 'user.info.username', 'video.list'];

    private const string BASE_URL = 'https://business-api.tiktok.com/open_api/v1.3/';

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();
        $url = (string) config('services.tiktok_accounts.authorization_url');
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'www.tiktok.com'
            || ! isset($parts['path']) || rtrim($parts['path'], '/') !== '/v2/auth/authorize'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new TokenRefreshException('Configure the TikTok Accounts authorization URL supplied by TikTok for Business.');
        }

        parse_str($parts['query'] ?? '', $query);
        foreach (['client_id', 'client_key'] as $key) {
            if (isset($query[$key]) && $query[$key] !== config('services.tiktok_accounts.client_id')) {
                throw new TokenRefreshException('The TikTok Accounts authorization URL belongs to a different app.');
            }
        }
        if (! isset($query['client_id']) && ! isset($query['client_key'])) {
            throw new TokenRefreshException('The TikTok Accounts authorization URL is missing its app identifier.');
        }
        if (($query['redirect_uri'] ?? null) !== config('services.tiktok_accounts.redirect')) {
            throw new TokenRefreshException('The TikTok Accounts authorization URL has a different callback.');
        }
        $query['state'] = $state;
        $query['disable_auto_auth'] = '1';

        return 'https://www.tiktok.com'.$parts['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function assertConfigured(): void
    {
        if (config('services.tiktok_accounts.approved') !== true
            || ! is_string(config('services.tiktok_accounts.client_id')) || config('services.tiktok_accounts.client_id') === ''
            || ! is_string(config('services.tiktok_accounts.client_secret')) || config('services.tiktok_accounts.client_secret') === '') {
            throw new TokenRefreshException('TikTok Accounts API approval and app credentials are required.');
        }
        $redirect = config('services.tiktok_accounts.redirect');
        $parts = is_string($redirect) ? parse_url($redirect) : false;
        if (! is_string($redirect) || strlen($redirect) < 10 || strlen($redirect) > 512
            || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || ! str_ends_with($redirect, '/') || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new TokenRefreshException('Configure the HTTPS TikTok Accounts callback URL with a trailing slash.');
        }
    }

    /** @return array<string, mixed> */
    public function exchange(string $code): array
    {
        $this->assertConfigured();

        return $this->tokens($this->post('tt_user/oauth2/token/', [
            'grant_type' => 'authorization_code', 'auth_code' => $code,
            'redirect_uri' => config('services.tiktok_accounts.redirect'),
        ]));
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken): array
    {
        $this->assertConfigured();

        return $this->tokens($this->post('tt_user/oauth2/refresh_token/', [
            'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
        ]));
    }

    public function revoke(string $accessToken): void
    {
        $this->assertConfigured();
        $this->post('tt_user/oauth2/revoke/', ['access_token' => $accessToken]);
    }

    public function handle(string $accessToken, string $openId): string
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->acceptJson()
                ->withHeaders(['Access-Token' => $accessToken])
                ->get(self::BASE_URL.'business/get/', [
                    'business_id' => $openId, 'fields' => json_encode(['username']),
                ]);
        } catch (Throwable) {
            throw new TokenRefreshException('TikTok Accounts identity verification is unavailable. Try reconnecting later.');
        }
        $handle = $response->json('data.username');
        if (! $response->successful() || $response->json('code') !== 0 || ! is_string($handle)
            || ! preg_match('/\A[A-Za-z0-9_.]{1,64}\z/', $handle)) {
            throw new TokenRefreshException('TikTok Accounts could not verify the selected account username.');
        }

        return mb_strtolower($handle);
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function post(string $path, array $parameters): array
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->acceptJson()->asJson()
                ->post(self::BASE_URL.$path, [
                    'client_id' => config('services.tiktok_accounts.client_id'),
                    'client_secret' => config('services.tiktok_accounts.client_secret'),
                    ...$parameters,
                ]);
        } catch (Throwable) {
            // OAuth codes and rotating refresh tokens must not be retried automatically.
            throw new TokenRefreshException('TikTok Accounts authorization could not be completed. Reconnect this account.');
        }
        $data = $response->json('data');
        if (! $response->successful() || $response->json('code') !== 0 || ! is_array($data)) {
            throw new TokenRefreshException('TikTok Accounts rejected the authorization request. Reconnect this account.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function tokens(array $data): array
    {
        foreach (['access_token', 'refresh_token', 'open_id', 'scope'] as $key) {
            if (! is_string($data[$key] ?? null) || $data[$key] === '' || strlen($data[$key]) > 16384) {
                throw new TokenRefreshException('TikTok Accounts returned incomplete authorization data. Reconnect this account.');
            }
        }
        foreach (['expires_in', 'refresh_token_expires_in'] as $key) {
            if (! is_int($data[$key] ?? null) || $data[$key] < 1 || $data[$key] > 366 * 86400) {
                throw new TokenRefreshException('TikTok Accounts returned an invalid token expiry. Reconnect this account.');
            }
        }
        $scopes = array_values(array_unique(explode(',', $data['scope'])));
        if (array_diff(self::REQUIRED_SCOPES, $scopes) !== [] || ($data['token_type'] ?? null) !== 'Bearer') {
            throw new TokenRefreshException('TikTok Accounts publishing, username, and video list permissions must all be granted.');
        }

        return [
            'access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'],
            'open_id' => $data['open_id'], 'scopes' => $scopes,
            'expires_at' => now()->addSeconds($data['expires_in'])->getTimestamp(),
            'refresh_expires_at' => now()->addSeconds($data['refresh_token_expires_in'])->getTimestamp(),
        ];
    }
}
