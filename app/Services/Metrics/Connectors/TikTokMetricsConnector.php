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

    private const int MAX_VIDEO_IDS_PER_REQUEST = 20;

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        $videoIds = array_values(array_unique($target->remote_ids ?? array_filter([$target->remote_id])));

        if ($videoIds === []) {
            return PostMetricsResult::failed('Target has no TikTok video ids.');
        }

        $likes = 0;
        $comments = 0;
        $reposts = 0;
        $impressions = 0;
        $allViewsAvailable = true;
        $videos = [];

        foreach (array_chunk($videoIds, self::MAX_VIDEO_IDS_PER_REQUEST) as $videoIdBatch) {
            try {
                $response = $this->http
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->withToken((string) ($credentials['access_token'] ?? ''))
                    ->acceptJson()
                    ->post(self::BASE_URL.'/video/query/?fields=id,like_count,comment_count,share_count,view_count', [
                        'filters' => ['video_ids' => $videoIdBatch],
                    ]);
            } catch (ConnectionException $exception) {
                return PostMetricsResult::failed($exception->getMessage());
            }

            $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

            if ($response->failed() || $response->json('error.code') !== 'ok') {
                return $this->postFailure($response);
            }

            $batchVideos = $response->json('data.videos');
            if (! is_array($batchVideos)) {
                return PostMetricsResult::failed('TikTok did not return the requested videos.');
            }

            $returnedIds = [];

            foreach ($batchVideos as $video) {
                if (! is_array($video) || ! is_string($video['id'] ?? null)
                    || ! in_array($video['id'], $videoIdBatch, true)
                    || in_array($video['id'], $returnedIds, true)) {
                    return PostMetricsResult::failed('TikTok returned an unexpected or duplicate video id.');
                }

                foreach (['like_count', 'comment_count', 'share_count'] as $field) {
                    if (! $this->isCounter($video[$field] ?? null)) {
                        return PostMetricsResult::failed('TikTok did not return complete engagement counters for every video.');
                    }
                }

                $returnedIds[] = $video['id'];
                $likes += (int) $video['like_count'];
                $comments += (int) $video['comment_count'];
                $reposts += (int) $video['share_count'];

                if (array_key_exists('view_count', $video)) {
                    if (! $this->isCounter($video['view_count'])) {
                        return PostMetricsResult::failed('TikTok returned an invalid video view count.');
                    }
                    $impressions += (int) $video['view_count'];
                } else {
                    $allViewsAvailable = false;
                }

                $videos[] = $video;
            }

            if (array_diff($videoIdBatch, $returnedIds) !== []) {
                return PostMetricsResult::failed('TikTok did not return every requested video.');
            }
        }

        return PostMetricsResult::ok(
            likes: $likes,
            comments: $comments,
            reposts: $reposts,
            impressions: $allViewsAvailable ? $impressions : null,
            raw: ['videos' => $videos],
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
            return $this->isRateLimited($response)
                ? AccountMetricsResult::rateLimited($this->excerpt($response))
                : AccountMetricsResult::failed($this->excerpt($response));
        }

        $user = $response->json('data.user');
        if (! is_array($user)) {
            return AccountMetricsResult::failed('TikTok did not return account metrics.');
        }

        if ((isset($user['open_id']) && $user['open_id'] !== $account->remote_account_id)
            || ! $this->isCounter($user['follower_count'] ?? null)) {
            return AccountMetricsResult::failed('TikTok did not return metrics for the expected account.');
        }

        foreach (['following_count', 'video_count'] as $field) {
            if (array_key_exists($field, $user) && ! $this->isCounter($user[$field])) {
                return AccountMetricsResult::failed('TikTok returned an invalid account metric.');
            }
        }

        return AccountMetricsResult::ok(
            followers: (int) $user['follower_count'],
            following: isset($user['following_count']) ? (int) $user['following_count'] : null,
            postsCount: isset($user['video_count']) ? (int) $user['video_count'] : null,
            raw: $response->json(),
        );
    }

    private function isCounter(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false;
    }

    private function isRateLimited(Response $response): bool
    {
        return $response->status() === 429 || $response->json('error.code') === 'rate_limit_exceeded';
    }

    private function postFailure(Response $response): PostMetricsResult
    {
        return $this->isRateLimited($response)
            ? PostMetricsResult::rateLimited($this->excerpt($response))
            : PostMetricsResult::failed($this->excerpt($response));
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200));
    }
}
