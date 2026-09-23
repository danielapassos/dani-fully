<?php

declare(strict_types=1);

namespace App\Services\Publishing\TikTokAccounts;

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Exceptions\TikTokCreatorInfoException;
use App\Models\ConnectedAccount;
use App\Services\Publishing\TikTokAccountsTokenManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * @phpstan-type AccountsCreator array{creator_username: string, creator_nickname: string, creator_avatar_url: ?string, privacy_level_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_video_post_duration_sec: int}
 * @phpstan-type AccountsBinding array{access_token: string, open_id: string, client_id: string, expected_handle: string, creator: AccountsCreator}
 */
class TikTokAccountsClient
{
    private const string BASE_URL = 'https://business-api.tiktok.com/open_api/v1.3';

    public function __construct(private readonly HttpFactory $http, private readonly TikTokAccountsTokenManager $tokens) {}

    /** @return AccountsBinding */
    public function binding(ConnectedAccount $account): array
    {
        $handle = strtolower(ltrim($account->handle, '@'));
        if ($account->platform !== Platform::TikTok || preg_match('/\A[a-z0-9._]{1,24}\z/', $handle) !== 1) {
            throw new TikTokCreatorInfoException('Choose a valid TikTok account for Accounts API publishing.', ErrorKind::Unsupported);
        }
        $credentials = $this->tokens->fresh($account);
        $token = $credentials['access_token'];
        $openId = $credentials['open_id'];
        if ($token === '' || $openId === '') {
            throw new TikTokCreatorInfoException('Reconnect this account to the TikTok Accounts API.', ErrorKind::AuthExpired);
        }
        $binding = ['access_token' => $token, 'open_id' => $openId, 'client_id' => $credentials['client_id'], 'expected_handle' => $handle];
        $profile = $this->request($token, 'GET', '/business/get/', [
            'business_id' => $openId,
            'fields' => json_encode(['username'], JSON_THROW_ON_ERROR),
        ]);
        if (! is_string($profile['username'] ?? null) || strtolower(ltrim($profile['username'], '@')) !== $handle) {
            throw new TikTokCreatorInfoException('The TikTok Accounts API authorization belongs to a different account. Reconnect the intended account.', ErrorKind::Unsupported);
        }
        $settings = $this->request($token, 'GET', '/business/video/settings/', ['business_id' => $openId]);
        if (! is_bool($settings['comment_disabled'] ?? null) || ! is_bool($settings['duet_disabled'] ?? null) || ! is_bool($settings['stitch_disabled'] ?? null)) {
            throw new TikTokCreatorInfoException('TikTok returned incomplete account publishing settings. Refresh before publishing.');
        }
        $privacy = $settings['privacy_level_options'] ?? null;
        $duration = $settings['max_video_post_duration_sec'] ?? null;
        if (! is_array($privacy) || ! is_int($duration) || $duration < 1) {
            throw new TikTokCreatorInfoException('TikTok returned incomplete account publishing settings. Refresh before publishing.');
        }
        $avatar = $profile['profile_image'] ?? null;
        $creator = [
            'creator_username' => $handle,
            'creator_nickname' => is_string($profile['display_name'] ?? null) ? $profile['display_name'] : $handle,
            'creator_avatar_url' => is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
            'privacy_level_options' => in_array('PUBLIC_TO_EVERYONE', $privacy, true) ? ['PUBLIC_TO_EVERYONE'] : [],
            'comment_disabled' => $settings['comment_disabled'],
            'duet_disabled' => $settings['duet_disabled'],
            'stitch_disabled' => $settings['stitch_disabled'],
            'max_video_post_duration_sec' => min(600, $duration),
        ];

        return [...$binding, 'creator' => $creator];
    }

    /** @return AccountsCreator */
    public function creatorInfo(ConnectedAccount $account): array
    {
        return $this->binding($account)['creator'];
    }

    public function verifyMediaUrl(string $url): void
    {
        $prefix = config('services.tiktok_accounts.verified_url_prefix');
        if (! is_string($prefix) || ! $this->httpsPrefix($prefix) || ! str_starts_with($url, $prefix)) {
            throw new TikTokCreatorInfoException('Configure the verified TikTok Accounts API media URL prefix before publishing.', ErrorKind::Unsupported);
        }
        $appId = config('services.tiktok_accounts.client_id');
        $secret = config('services.tiktok_accounts.client_secret');
        if (! is_string($appId) || $appId === '' || ! is_string($secret) || $secret === '') {
            throw new TikTokCreatorInfoException('Configure the TikTok Accounts API application credentials before verifying media ownership.', ErrorKind::Unsupported);
        }
        $data = $this->request(null, 'GET', '/business/property/list/', ['app_id' => $appId, 'secret' => $secret]);
        foreach (is_array($data['url_property_info_list'] ?? null) ? $data['url_property_info_list'] : [] as $property) {
            if (! is_array($property) || ($property['property_status'] ?? null) !== 1 || ! is_string($property['url'] ?? null)) {
                continue;
            }
            if (($property['property_type'] ?? null) === 2 && $property['url'] === $prefix) {
                return;
            }
            $domain = strtolower($property['url']);
            $host = strtolower((string) parse_url($prefix, PHP_URL_HOST));
            if (($property['property_type'] ?? null) === 1 && preg_match('/\A[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?\z/', $domain) === 1
                && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return;
            }
        }

        throw new TikTokCreatorInfoException('This media URL property is not verified for the TikTok Accounts API application.', ErrorKind::Unsupported);
    }

    private function httpsPrefix(string $prefix): bool
    {
        $parts = parse_url($prefix);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']) && (! isset($parts['port']) || $parts['port'] === 443)
            && str_ends_with($prefix, '/') && ! str_contains($prefix, '\\');
    }

    /** @param array<string, mixed> $binding
     * @param  array<string, mixed>  $postInfo
     * @return array<string, mixed>
     */
    public function create(array $binding, string $videoUrl, array $postInfo): array
    {
        return $this->request($binding['access_token'], 'POST', '/business/video/publish/', [
            'business_id' => $binding['open_id'], 'video_url' => $videoUrl, 'post_info' => $postInfo,
        ]);
    }

    /** @param array<string, mixed> $binding
     * @return array<string, mixed>
     */
    public function status(array $binding, string $publishId): array
    {
        return $this->request($binding['access_token'], 'GET', '/business/publish/status/', [
            'business_id' => $binding['open_id'], 'publish_id' => $publishId,
        ]);
    }

    /** @param array<string, mixed> $binding
     * @return list<array<string, mixed>>
     */
    public function publicVideos(array $binding, string $postId): array
    {
        $data = $this->request($binding['access_token'], 'GET', '/business/video/list/', [
            'business_id' => $binding['open_id'],
            'fields' => json_encode(['item_id', 'share_url'], JSON_THROW_ON_ERROR),
            'filters' => json_encode(['video_ids' => [$postId]], JSON_THROW_ON_ERROR),
        ]);

        return is_array($data['videos'] ?? null) ? array_values(array_filter($data['videos'], is_array(...))) : [];
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function request(?string $token, string $method, string $path, array $parameters): array
    {
        try {
            $request = $this->http->timeout(45)->connectTimeout(10)->withoutRedirecting()->acceptJson();
            if ($token !== null) {
                $request = $request->withHeaders(['Access-Token' => $token]);
            }
            $response = $request->send($method, self::BASE_URL.$path, [$method === 'GET' ? 'query' : 'json' => $parameters]);
        } catch (ConnectionException) {
            throw new TikTokAccountsRequestException('The TikTok Accounts API connection was interrupted. The saved publishing state must be checked before another submission.', ErrorKind::Network);
        }
        $code = $response->json('code');
        if ($response->successful() && $code === 0 && is_array($response->json('data'))) {
            return $response->json('data');
        }
        $requestId = $response->json('request_id');
        $requestId = is_string($requestId) && preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $requestId) === 1 ? $requestId : null;
        $kind = match (true) {
            $response->status() === 429 || in_array($code, [40016, 40100], true) => ErrorKind::RateLimited,
            $response->status() === 401 || in_array($code, [40102, 40104, 40105], true) => ErrorKind::AuthExpired,
            $response->status() >= 500 || (is_int($code) && str_starts_with((string) $code, '5')) => ErrorKind::ServerError,
            $response->status() === 403 || in_array($code, [40118, 40124, 40125], true) => ErrorKind::Unsupported,
            is_int($code) && $code !== 0 && $code !== 20001 => ErrorKind::Validation,
            default => ErrorKind::Unknown,
        };
        $definite = $code !== 20001 && $kind !== ErrorKind::ServerError && ! $response->serverError()
            && ((is_int($code) && $code >= 40000 && $code < 50000)
                || in_array($response->status(), [400, 401, 403, 422, 429], true));
        $message = match ($kind) {
            ErrorKind::AuthExpired => 'The TikTok Accounts API authorization needs attention. Reconnect this account.',
            ErrorKind::Unsupported => 'TikTok denied Accounts API access. Check application approval and granted account permissions.',
            ErrorKind::RateLimited => 'The TikTok Accounts API request limit was reached. Wait before trying again.',
            default => 'TikTok Accounts API did not confirm the request. Review the saved publishing state before retrying.',
        };

        throw new TikTokAccountsRequestException($message, $kind, $response->status(), $definite, is_int($code) ? $code : null, $requestId);
    }
}
