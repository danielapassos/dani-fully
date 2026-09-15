<?php

declare(strict_types=1);

namespace App\Services\Auth\Socialite;

use App\Support\OAuthGrantedScopes;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use Override;
use RuntimeException;

/** Instagram Login driver for professional Business and Creator accounts. */
class InstagramProvider extends AbstractProvider
{
    #[Override]
    protected $scopeSeparator = ',';

    /** @var array<int, string> */
    #[Override]
    protected $scopes = [
        'instagram_business_basic',
        'instagram_business_manage_insights',
        'instagram_business_content_publish',
        'instagram_business_manage_comments',
    ];

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase('https://www.instagram.com/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://api.instagram.com/oauth/access_token';
    }

    /**
     * Exchange the callback code for a short token, then immediately exchange
     * that token for the long-lived token required by scheduled publishing.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getAccessTokenResponse($code): array
    {
        $shortResponse = Http::asForm()
            ->timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->post($this->getTokenUrl(), $this->getTokenFields($code))
            ->throw()
            ->json();

        $short = $this->shortToken(is_array($shortResponse) ? $shortResponse : []);

        $long = Http::timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->get('https://graph.instagram.com/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->clientSecret,
                'access_token' => $short['access_token'],
            ])
            ->throw()
            ->json();

        if (! is_array($long) || blank($long['access_token'] ?? null)) {
            throw new RuntimeException('Instagram did not return a long-lived access token.');
        }

        $permissions = OAuthGrantedScopes::normalize($short['permissions'] ?? null);

        return [
            ...$long,
            'user_id' => $short['user_id'] ?? null,
            'scope' => implode(',', $permissions),
        ];
    }

    /** @return array<string, mixed> */
    protected function getUserByToken($token): array
    {
        $version = (string) config('services.instagram.graph_version', 'v25.0');
        $version = str_starts_with($version, 'v') ? $version : 'v'.$version;

        $profile = Http::timeout(10)
            ->connectTimeout(5)
            ->withToken($token)
            ->acceptJson()
            ->get("https://graph.instagram.com/{$version}/me", [
                'fields' => 'user_id,id,username,name,account_type,profile_picture_url',
            ])
            ->throw()
            ->json();

        if (! is_array($profile)) {
            return [];
        }

        if (is_array($profile['data'] ?? null)) {
            $records = array_values($profile['data']);
            if (count($records) !== 1 || ! is_array($records[0])) {
                throw new RuntimeException('Instagram returned an ambiguous profile response.');
            }

            return $records[0];
        }

        return $profile;
    }

    /** @param array<string, mixed> $user */
    protected function mapUserToObject(array $user): User
    {
        $id = $user['user_id'] ?? $user['id'] ?? null;
        $username = (string) ($user['username'] ?? '');
        $name = (string) ($user['name'] ?? '');

        return (new User)->setRaw($user)->map([
            'id' => $id,
            'nickname' => $username !== '' ? $username : $id,
            'name' => $name !== '' ? $name : ($username !== '' ? $username : $id),
            'avatar' => $user['profile_picture_url'] ?? null,
        ]);
    }

    /**
     * Instagram has returned both a flat token object and a one-record `data`
     * envelope. Accept exactly one authorization record and fail closed on an
     * ambiguous response.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function shortToken(array $response): array
    {
        if (is_array($response['data'] ?? null)) {
            $records = array_values($response['data']);
            if (count($records) !== 1 || ! is_array($records[0])) {
                throw new RuntimeException('Instagram did not return one authorization record.');
            }

            $response = $records[0];
        }

        if (blank($response['access_token'] ?? null)) {
            throw new RuntimeException('Instagram did not return one authorization record.');
        }

        return $response;
    }
}
