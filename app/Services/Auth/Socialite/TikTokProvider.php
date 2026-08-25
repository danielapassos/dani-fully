<?php

declare(strict_types=1);

namespace App\Services\Auth\Socialite;

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use Override;

/**
 * TikTok Login Kit driver for workspace-owned creator accounts.
 *
 * TikTok calls the OAuth client identifier `client_key` and wraps profile data
 * in `data.user`, so the stock Socialite providers cannot model this flow.
 */
class TikTokProvider extends AbstractProvider
{
    #[Override]
    protected $scopeSeparator = ',';

    /** @var array<int, string> */
    #[Override]
    protected $scopes = ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.list', 'video.upload'];

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase('https://www.tiktok.com/v2/auth/authorize/', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://open.tiktokapis.com/v2/oauth/token/';
    }

    /** @return array<string, string|null> */
    #[Override]
    protected function getCodeFields($state = null): array
    {
        return array_filter([
            'client_key' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator),
            'response_type' => 'code',
            'state' => $state,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, string> */
    #[Override]
    protected function getTokenFields($code): array
    {
        return [
            'client_key' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUrl,
        ];
    }

    /** @return array<string, mixed> */
    #[Override]
    public function getAccessTokenResponse($code): array
    {
        return Http::asForm()
            ->timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->post($this->getTokenUrl(), $this->getTokenFields($code))
            ->throw()
            ->json();
    }

    /** @return array<string, mixed> */
    protected function getUserByToken($token): array
    {
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withToken($token)
            ->acceptJson()
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,union_id,avatar_url,display_name,username',
            ])
            ->throw()
            ->json('data.user');

        return is_array($response) ? $response : [];
    }

    /** @param array<string, mixed> $user */
    protected function mapUserToObject(array $user): User
    {
        $displayName = (string) ($user['display_name'] ?? '');
        $username = (string) ($user['username'] ?? '');

        return (new User)->setRaw($user)->map([
            'id' => $user['open_id'] ?? null,
            'nickname' => $username !== '' ? $username : $displayName,
            'name' => $displayName !== '' ? $displayName : $username,
            'avatar' => $user['avatar_url'] ?? null,
        ]);
    }
}
