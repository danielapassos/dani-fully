<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Metrics\StoredAnalytics;
use App\Support\InstanceSettings;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, InstanceSettings $settings): Response
    {
        abort_unless($request->user()->can('viewAny', Post::class), 403);

        $days = max(7, min(365, (int) $request->integer('days', 90)));

        return Inertia::render('analytics/index', [
            ...$this->buildPayload($days),
            'rangeDays' => $days,
            'polling' => [
                'post_metrics_enabled' => collect(Platform::cases())
                    ->mapWithKeys(fn (Platform $platform): array => [
                        $platform->value => $settings->postMetricsPollingEnabled($platform),
                    ])
                    ->all(),
                'account_metrics_enabled' => collect(Platform::cases())
                    ->mapWithKeys(fn (Platform $platform): array => [
                        $platform->value => $settings->accountMetricsPollingEnabled($platform),
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array{accounts: array<int, array<string, mixed>>, posts: array<int, array<string, mixed>>, summary: array<string, mixed>, comparison: array{top: array<int, array<string, mixed>>, bottom: array<int, array<string, mixed>>}}
     */
    private function buildPayload(int $days): array
    {
        $from = Date::now()->subDays($days);
        $previousFrom = Date::now()->subDays($days * 2);

        // Discord webhooks have no follower/member metrics — omit them from the
        // follower growth chart and account cards entirely.
        $accounts = ConnectedAccount::query()
            ->where('platform', '!=', Platform::Discord->value)
            ->with(['metrics' => fn ($q) => $q
                ->where('captured_at', '>=', $from)
                ->orderBy('captured_at')
                // Drop the per-row `raw` API-response JSON blob — the series only
                // needs follower/following counts.
                ->select(['id', 'connected_account_id', 'captured_at', 'followers', 'following'])])
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->metrics_status?->value,
                'latest_followers' => $account->metrics->last()?->followers,
                'captured_at' => $account->metrics->last()?->captured_at->toIso8601String(),
                'last_attempt_at' => $account->metrics_captured_at?->toIso8601String(),
                'stale' => $this->accountMetricsStale($account),
                'followers_delta' => $this->followerDelta($account->metrics),
                'series' => $this->downsampleDaily($account->metrics),
            ])->all();

        $posts = Post::query()
            ->with('targets:id,post_id,connected_account_id,platform,likes,comments,reposts,metrics_status,status,media_upload_state')
            ->whereIn('status', [PostStatus::Published->value, PostStatus::Partial->value])
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $from)
            ->orderBy('published_at')
            ->get()
            ->each(fn (Post $post) => $post->setRelation('targets', $post->targets
                ->filter(fn (PostTarget $target): bool => $target->publicationStatus() === PostTargetStatus::Published)))
            ->filter(fn (Post $post): bool => $post->targets->isNotEmpty());

        $markers = $posts->map(fn (Post $post): array => [
            'id' => $post->id,
            'title' => $this->resolveTitle($post),
            'published_at' => $post->published_at?->toIso8601String(),
            'platforms' => $post->targets->pluck('platform')->map(fn ($p): string => $p->value)->unique()->values()->all(),
            'connected_account_ids' => $post->targets->pluck('connected_account_id')->unique()->values()->all(),
        ])->all();

        $ranked = $posts
            ->filter(fn (Post $post): bool => $post->targets->contains(
                fn (PostTarget $t): bool => $t->metrics_status === MetricsStatus::Ok,
            ))
            ->map(fn (Post $post): array => [
                'id' => $post->id,
                'title' => $this->resolveTitle($post),
                'published_at' => $post->published_at?->toIso8601String(),
                'platforms' => $post->targets->pluck('platform')->map(fn ($p): string => $p->value)->unique()->values()->all(),
                'connected_account_ids' => $post->targets->pluck('connected_account_id')->unique()->values()->all(),
                'engagement' => (int) $post->targets->filter(fn (PostTarget $t): bool => $t->metrics_status === MetricsStatus::Ok)
                    ->sum(fn (PostTarget $t): int => $t->likes + $t->comments + $t->reposts),
            ])
            ->sortByDesc('engagement')
            ->values();

        $comparisonTop = $ranked->count() < 10
            ? $ranked->values()->all()
            : $ranked->take(5)->values()->all();

        $comparisonBottom = $ranked->count() < 10
            ? []
            : $ranked->reverse()->take(5)->values()->all();

        return [
            'accounts' => $accounts,
            'posts' => $markers,
            'summary' => $this->buildSummary(
                $accounts,
                $ranked->isEmpty() ? null : (int) $ranked->sum('engagement'),
                $posts->count(),
                $previousFrom,
                $from,
            ),
            'comparison' => [
                'top' => $comparisonTop,
                'bottom' => $comparisonBottom,
            ],
        ];
    }

    /**
     * The headline numbers — total followers, engagement, and posts published.
     * The follower delta is growth across the selected window; engagement compares
     * latest lifetime totals for publication cohorts, not engagement accrued in
     * those periods. Posts compare against the previous equal-length window. Deltas
     * are null when there's no honest baseline to compare against.
     *
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array{
     *     account_count: int,
     *     followers: array{value: int|null, delta: int|null},
     *     engagement: array{value: int|null, delta: int|null},
     *     posts: array{value: int, delta: int|null},
     * }
     */
    private function buildSummary(array $accounts, ?int $totalEngagement, int $postsCount, CarbonInterface $previousFrom, CarbonInterface $from): array
    {
        $accountsCollection = collect($accounts);

        $measuredAccounts = $accountsCollection->filter(fn (array $a): bool => $a['latest_followers'] !== null);
        $totalFollowers = $measuredAccounts->isEmpty() ? null : (int) $measuredAccounts->sum('latest_followers');
        $trackedDeltas = $accountsCollection->pluck('followers_delta')->filter(fn ($d): bool => $d !== null);
        $followersDelta = $trackedDeltas->isEmpty() ? null : (int) $trackedDeltas->sum();

        // Previous window — only used as a baseline for the delta chips.
        $previousPosts = Post::query()
            ->with('targets:id,post_id,connected_account_id,platform,likes,comments,reposts,metrics_status,status,media_upload_state')
            ->whereIn('status', [PostStatus::Published->value, PostStatus::Partial->value])
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $previousFrom)
            ->where('published_at', '<', $from)
            ->get()
            ->each(fn (Post $post) => $post->setRelation('targets', $post->targets
                ->filter(fn (PostTarget $target): bool => $target->publicationStatus() === PostTargetStatus::Published)))
            ->filter(fn (Post $post): bool => $post->targets->isNotEmpty());

        // Engagement is only measurable on posts that captured Ok metrics, so the
        // baseline for the engagement delta must also require such a post — a
        // previous window of unmeasured posts is not a zero baseline.
        $previousMeasuredPosts = $previousPosts->filter(fn (Post $post): bool => $post->targets->contains(
            fn (PostTarget $t): bool => $t->metrics_status === MetricsStatus::Ok,
        ));
        $hasEngagementBaseline = $previousMeasuredPosts->isNotEmpty();
        $previousEngagement = (int) $previousMeasuredPosts->sum(
            fn (Post $post): int => (int) $post->targets->filter(fn (PostTarget $t): bool => $t->metrics_status === MetricsStatus::Ok)
                ->sum(fn (PostTarget $t): int => $t->likes + $t->comments + $t->reposts),
        );

        $hasPostBaseline = $previousPosts->isNotEmpty();

        return [
            'account_count' => $measuredAccounts->count(),
            'followers' => [
                'value' => $totalFollowers,
                'delta' => $followersDelta,
            ],
            'engagement' => [
                'value' => $totalEngagement,
                'delta' => $totalEngagement !== null && $hasEngagementBaseline ? $totalEngagement - $previousEngagement : null,
            ],
            'posts' => [
                'value' => $postsCount,
                'delta' => $hasPostBaseline ? $postsCount - $previousPosts->count() : null,
            ],
        ];
    }

    private function accountMetricsStale(ConnectedAccount $account): bool
    {
        $settings = app(InstanceSettings::class);
        $polling = $settings->metricsEnabled() && $settings->accountMetricsPollingEnabled($account->platform) && ! $account->isDisabled();

        return app(StoredAnalytics::class)->freshness(
            $account->metrics->last()?->captured_at,
            $account->metrics_status,
            $polling ? $settings->accountMetricsPollIntervalMinutes($account->platform) * 60 : null,
        ) !== 'current';
    }

    /**
     * Change in followers across the window: latest reading minus the earliest.
     * Null unless there are at least two comparable readings.
     *
     * @param  Collection<int, AccountMetric>  $metrics
     */
    private function followerDelta(Collection $metrics): ?int
    {
        if ($metrics->count() < 2) {
            return null;
        }

        $first = $metrics->first()?->followers;
        $last = $metrics->last()?->followers;

        if ($first === null || $last === null) {
            return null;
        }

        return $last - $first;
    }

    /**
     * Collapse a chronologically-ordered metric collection to one point per day
     * (the last reading of each day), bounding the series sent to the client
     * regardless of how often metrics are captured.
     *
     * @param  Collection<int, AccountMetric>  $metrics
     * @return array<int, array{at: string, followers: int|null, following: int|null}>
     */
    private function downsampleDaily($metrics): array
    {
        return $metrics
            ->groupBy(fn (AccountMetric $m): string => $m->captured_at->toDateString())
            ->map(fn ($dayMetrics): AccountMetric => $dayMetrics->last())
            ->map(fn (AccountMetric $m): array => [
                'at' => $m->captured_at->toIso8601String(),
                'followers' => $m->followers,
                'following' => $m->following,
            ])
            ->values()
            ->all();
    }

    private function resolveTitle(Post $post): string
    {
        $first = trim((string) Str::of($post->base_text)->explode("\n")->first());

        return Str::limit($first, 60) ?: 'Untitled post';
    }
}
