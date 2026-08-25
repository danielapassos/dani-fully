<?php

declare(strict_types=1);

namespace App\Services\Metrics\Connectors;

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Contracts\MetricsConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

class TikTokMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    private const string BASE_URL = 'https://open.tiktokapis.com/v2';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        if ($target->remote_id === null) {
            return PostMetricsResult::failed('Target has no TikTok video id.');
        }

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->post(self::BASE_URL.'/video/query/?fields=id,like_count,comment_count,share_count,view_count', [
                    'filters' => ['video_ids' => [$target->remote_id]],
                ]);
        } catch (ConnectionException $exception) {
            return PostMetricsResult::failed($exception->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

        if ($response->failed() || $response->json('error.code') !== 'ok') {
            return $this->postFailure($response);
        }

        $video = $response->json('data.videos.0');
        if (! is_array($video)) {
            return PostMetricsResult::failed('TikTok did not return the requested video.');
        }

        return PostMetricsResult::ok(
            likes: (int) ($video['like_count'] ?? 0),
            comments: (int) ($video['comment_count'] ?? 0),
            reposts: (int) ($video['share_count'] ?? 0),
            impressions: isset($video['view_count']) ? (int) $video['view_count'] : null,
            raw: $response->json(),
        );
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->get(self::BASE_URL.'/user/info/', [
                    'fields' => 'open_id,follower_count,following_count,video_count',
                ]);
        } catch (ConnectionException $exception) {
            return AccountMetricsResult::failed($exception->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_ACCOUNT, $account, $response);

        if ($response->failed() || $response->json('error.code') !== 'ok') {
            return $response->status() === 429
                ? AccountMetricsResult::rateLimited($this->excerpt($response))
                : AccountMetricsResult::failed($this->excerpt($response));
        }

        $user = $response->json('data.user');
        if (! is_array($user)) {
            return AccountMetricsResult::failed('TikTok did not return account metrics.');
        }

        return AccountMetricsResult::ok(
            followers: (int) ($user['follower_count'] ?? 0),
            following: isset($user['following_count']) ? (int) $user['following_count'] : null,
            postsCount: isset($user['video_count']) ? (int) $user['video_count'] : null,
            raw: $response->json(),
        );
    }

    private function postFailure(Response $response): PostMetricsResult
    {
        return $response->status() === 429
            ? PostMetricsResult::rateLimited($this->excerpt($response))
            : PostMetricsResult::failed($this->excerpt($response));
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200));
    }
}
