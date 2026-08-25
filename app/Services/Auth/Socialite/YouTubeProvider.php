<?php

declare(strict_types=1);

namespace App\Services\Auth\Socialite;

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use Override;
use RuntimeException;

/** Google OAuth driver that resolves the authorization to one owned channel. */
class YouTubeProvider extends AbstractProvider
{
    #[Override]
    protected $scopeSeparator = ' ';

    #[Override]
    protected $usesPKCE = true;

    /** @var array<int, string> */
    #[Override]
    protected $scopes = [
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
        'https://www.googleapis.com/auth/youtube.upload',
    ];

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase('https://accounts.google.com/o/oauth2/v2/auth', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getCodeFields($state = null): array
    {
        return [
            ...parent::getCodeFields($state),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            // A durable refresh token is required for scheduled publishing.
            'prompt' => 'consent',
        ];
    }

    /** @return array<string, mixed> */
    #[Override]
    public function getAccessTokenResponse($code): array
    {
        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->post($this->getTokenUrl(), $this->getTokenFields($code))
            ->throw()
            ->json();

        if (! is_array($response) || blank($response['refresh_token'] ?? null)) {
            throw new RuntimeException('Google did not return a durable YouTube refresh token.');
        }

        return $response;
    }

    /** @return array<string, mixed> */
    protected function getUserByToken($token): array
    {
        $items = Http::timeout(10)
            ->connectTimeout(5)
            ->withToken($token)
            ->acceptJson()
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet,statistics',
                'mine' => 'true',
                'maxResults' => 2,
            ])
            ->throw()
            ->json('items');

        if (! is_array($items) || count($items) !== 1 || blank($items[0]['id'] ?? null)) {
            throw new RuntimeException('YouTube authorization must resolve to exactly one owned channel.');
        }

        return $items[0];
    }

    /** @param array<string, mixed> $user */
    protected function mapUserToObject(array $user): User
    {
        $snippet = is_array($user['snippet'] ?? null) ? $user['snippet'] : [];
        $thumbnails = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
        $customUrl = (string) ($snippet['customUrl'] ?? '');
        $title = (string) ($snippet['title'] ?? 'YouTube channel');

        return (new User)->setRaw($user)->map([
            'id' => $user['id'] ?? null,
            'nickname' => $customUrl !== '' ? $customUrl : $title,
            'name' => $title,
            'avatar' => $thumbnails['high']['url'] ?? $thumbnails['default']['url'] ?? null,
        ]);
    }
}
