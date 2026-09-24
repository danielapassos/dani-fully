<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Services\Metrics\MetricsCaptureCadence;
use App\Services\Metrics\StoredAnalytics;
use Carbon\CarbonImmutable;

final class MetricsPresenter
{
    /**
     * @return array{supported: bool, captured_at: string|null, totals: array{likes: int|null, comments: int|null, reposts: int|null}, targets: list<array<string, mixed>>}
     */
    public static function forPost(Post $post): array
    {
        $post->loadMissing(['targets.account', 'targets.metrics']);

        $targets = $post->targets
            ->filter(fn (PostTarget $t): bool => $t->publicationStatus() === PostTargetStatus::Published)
            ->values();

        $totals = ['likes' => null, 'comments' => null, 'reposts' => null];
        $capturedAt = null;
        $supported = false;
        $rows = [];

        foreach ($targets as $target) {
            $isOk = $target->metrics_status === MetricsStatus::Ok;
            $supported = $supported || $target->metrics_status !== MetricsStatus::Unsupported;
            $latest = $target->metrics->sortByDesc('captured_at')->first();
            $sample = $isOk ? $target : $latest;
            $sampleAt = $isOk ? $target->metrics_captured_at : $latest?->captured_at;
            $likes = $sample?->likes;
            $comments = $target->platform === Platform::Discord ? null : $sample?->comments;
            $reposts = in_array($target->platform, [Platform::YouTube, Platform::Discord], true) ? null : $sample?->reposts;
            $interval = app(InstanceSettings::class)->metricsEnabled() && $target->account !== null && ! $target->account->isDisabled()
                ? app(MetricsCaptureCadence::class)->effectiveIntervalSeconds($target, CarbonImmutable::now()) : null;

            if ($isOk) {
                foreach (['likes' => $likes, 'comments' => $comments, 'reposts' => $reposts] as $key => $value) {
                    if ($value !== null) {
                        $totals[$key] = ($totals[$key] ?? 0) + $value;
                    }
                }

                $at = $target->metrics_captured_at?->toIso8601String();
                if ($at !== null && ($capturedAt === null || $at > $capturedAt)) {
                    $capturedAt = $at;
                }
            }

            $rows[] = [
                'id' => $target->id,
                'platform' => $target->platform->value,
                'handle' => $target->account?->handle,
                'display_name' => $target->account?->display_name,
                'avatar_url' => $target->account?->avatar_url,
                'status' => $target->metrics_status?->value,
                'likes' => $likes,
                'comments' => $comments,
                'reposts' => $reposts,
                'impressions' => $sample?->impressions,
                'captured_at' => $sampleAt?->toIso8601String(),
                'last_attempt_at' => $target->metrics_captured_at?->toIso8601String(),
                'stale' => app(StoredAnalytics::class)->freshness($sampleAt, $target->metrics_status, $interval) !== 'current',
                'series' => $target->metrics
                    ->sortBy('captured_at')
                    ->map(fn (PostTargetMetric $m): array => [
                        'at' => $m->captured_at->toIso8601String(),
                        'likes' => $m->likes,
                        'comments' => $m->comments,
                        'reposts' => $m->reposts,
                        'impressions' => $m->impressions,
                    ])->values()->all(),
            ];
        }

        return [
            'supported' => $supported,
            'captured_at' => $capturedAt,
            'totals' => $totals,
            'targets' => $rows,
        ];
    }
}
