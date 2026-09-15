<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Exceptions\TikTokCreatorInfoException;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Services\Publishing\TokenManager;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

class TikTokCreatorInfo
{
    use TracksUsage;

    public function __construct(private readonly HttpFactory $http, private readonly TokenManager $tokens) {}

    /** @return array{creator_username: string, creator_nickname: string, creator_avatar_url: ?string, privacy_level_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_video_post_duration_sec: int} */
    public function query(ConnectedAccount $account, ?string $token = null): array
    {
        if ($account->platform !== Platform::TikTok || $account->disabled_at !== null) {
            throw new TikTokCreatorInfoException('Choose an enabled TikTok account before publishing.');
        }

        try {
            $token ??= (string) ($this->tokens->fresh($account)['access_token'] ?? '');
        } catch (TokenRefreshException) {
            throw new TikTokCreatorInfoException('Reconnect TikTok to refresh access before publishing.', ErrorKind::AuthExpired);
        }
        if ($token === '') {
            throw new TikTokCreatorInfoException('Reconnect TikTok and approve direct publishing.', ErrorKind::AuthExpired);
        }

        try {
            $response = $this->http->timeout(15)->connectTimeout(5)->withoutRedirecting()
                ->withToken($token)->acceptJson()->withBody('{}', 'application/json; charset=UTF-8')
                ->post('https://open.tiktokapis.com/v2/post/publish/creator_info/query/');
        } catch (ConnectionException) {
            throw new TikTokCreatorInfoException('TikTok could not load the current publishing settings. Try again shortly.', ErrorKind::Network);
        }

        $this->meter(UsageCategory::Publish, UsageOperation::CREATOR_INFO, $account, $response, succeeded: $response->successful() && $response->json('error.code') === 'ok');
        $code = $response->json('error.code');
        if (! $response->successful() || $code !== 'ok') {
            [$message, $kind] = match (true) {
                $response->status() === 401 || in_array($code, ['access_token_invalid', 'scope_not_authorized'], true) => ['Reconnect TikTok and approve direct publishing.', ErrorKind::AuthExpired],
                $response->status() === 429 || $code === 'rate_limit_exceeded' => ['TikTok is limiting requests. Wait a minute, then refresh the publishing settings.', ErrorKind::RateLimited],
                in_array($code, ['spam_risk_too_many_posts', 'reached_active_user_cap'], true) => ['TikTok has reached its posting limit for now. Try again later.', ErrorKind::Validation],
                $code === 'spam_risk_user_banned_from_posting' => ['TikTok has restricted posting for this account.', ErrorKind::Validation],
                default => ['TikTok could not confirm this account can publish. Refresh the settings before trying again.', ErrorKind::Validation],
            };
            throw new TikTokCreatorInfoException($message, $kind, $response->status());
        }

        $data = $response->json('data');
        if (! is_array($data) || ! is_string($data['creator_username'] ?? null) || $data['creator_username'] === ''
            || ! is_string($data['creator_nickname'] ?? null) || ! is_array($data['privacy_level_options'] ?? null)
            || $data['privacy_level_options'] === [] || ! is_int($data['max_video_post_duration_sec'] ?? null)
            || $data['max_video_post_duration_sec'] < 1) {
            throw new TikTokCreatorInfoException('TikTok returned incomplete publishing settings. Refresh before posting.');
        }
        foreach (['comment_disabled', 'duet_disabled', 'stitch_disabled'] as $field) {
            if (! is_bool($data[$field] ?? null)) {
                throw new TikTokCreatorInfoException('TikTok returned incomplete interaction settings. Refresh before posting.');
            }
        }
        foreach ($data['privacy_level_options'] as $privacy) {
            if (! is_string($privacy) || ! in_array($privacy, TikTokPostOptions::PRIVACY_LEVELS, true)) {
                throw new TikTokCreatorInfoException('TikTok returned an unsupported privacy choice. Refresh before posting.');
            }
        }

        $avatar = $data['creator_avatar_url'] ?? null;

        return [
            'creator_username' => $data['creator_username'],
            'creator_nickname' => $data['creator_nickname'],
            'creator_avatar_url' => is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
            'privacy_level_options' => array_values(array_unique($data['privacy_level_options'])),
            'comment_disabled' => $data['comment_disabled'],
            'duet_disabled' => $data['duet_disabled'],
            'stitch_disabled' => $data['stitch_disabled'],
            'max_video_post_duration_sec' => $data['max_video_post_duration_sec'],
        ];
    }
}
