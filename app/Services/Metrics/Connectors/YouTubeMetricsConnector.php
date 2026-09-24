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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class YouTubeMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    private const string BASE_URL = 'https://www.googleapis.com/youtube/v3';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        if ($target->remote_id === null) {
            return PostMetricsResult::failed('Target has no YouTube video id.');
        }

        try {
            $response = $this->request($credentials)->get(self::BASE_URL.'/videos', [
                'part' => 'statistics',
                'id' => $target->remote_id,
                'maxResults' => 1,
            ]);
        } catch (ConnectionException $exception) {
            return PostMetricsResult::failed($exception->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

        if ($response->failed()) {
            return $this->isRateLimited($response)
                ? PostMetricsResult::rateLimited($this->excerpt($response))
                : PostMetricsResult::failed($this->excerpt($response));
        }

        $statistics = $response->json('items.0.statistics');
        if (! is_array($statistics)) {
            return PostMetricsResult::failed('YouTube did not return the requested video.');
        }

        if (! is_numeric($statistics['likeCount'] ?? null) || ! is_numeric($statistics['commentCount'] ?? null)) {
            return PostMetricsResult::failed('YouTube did not return all requested engagement metrics.');
        }

        return PostMetricsResult::ok(
            likes: (int) $statistics['likeCount'],
            comments: (int) $statistics['commentCount'],
            reposts: 0,
            impressions: isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null,
            raw: $response->json(),
        );
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        try {
            $response = $this->request($credentials)->get(self::BASE_URL.'/channels', [
                'part' => 'statistics',
                'id' => $account->remote_account_id,
                'maxResults' => 1,
            ]);
        } catch (ConnectionException $exception) {
            return AccountMetricsResult::failed($exception->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_ACCOUNT, $account, $response);

        if ($response->failed()) {
            return $this->isRateLimited($response)
                ? AccountMetricsResult::rateLimited($this->excerpt($response))
                : AccountMetricsResult::failed($this->excerpt($response));
        }

        $statistics = $response->json('items.0.statistics');
        if (! is_array($statistics)) {
            return AccountMetricsResult::failed('YouTube did not return channel metrics.');
        }

        if (($statistics['hiddenSubscriberCount'] ?? false) === true || ! is_numeric($statistics['subscriberCount'] ?? null)) {
            return AccountMetricsResult::failed('YouTube did not expose a subscriber count for this channel.');
        }

        return AccountMetricsResult::ok(
            followers: (int) $statistics['subscriberCount'],
            postsCount: isset($statistics['videoCount']) ? (int) $statistics['videoCount'] : null,
            raw: $response->json(),
        );
    }

    /** @param array<string, mixed> $credentials */
    private function request(array $credentials): PendingRequest
    {
        return $this->http
            ->timeout(10)
            ->connectTimeout(5)
            ->withToken((string) ($credentials['access_token'] ?? ''))
            ->acceptJson();
    }

    private function isRateLimited(Response $response): bool
    {
        return $response->status() === 429 || in_array($response->json('error.errors.0.reason'), [
            'quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded',
        ], true);
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200));
    }
}
