<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Support\CursorPage;
use App\Support\InstanceSettings;
use App\Support\OAuthGrantedScopes;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

/** Stored measurements only; callers install and authorize the workspace context. */
class StoredAnalytics
{
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly MetricsCaptureCadence $cadence,
    ) {}

    /** @return array<string, mixed> */
    public function accounts(int $perPage = 25, ?string $cursor = null, ?string $accountId = null): array
    {
        $paginator = ConnectedAccount::query()
            ->select(['id', 'platform', 'handle', 'display_name', 'status', 'metrics_status', 'metrics_captured_at', 'disabled_at', 'capabilities'])
            ->when($accountId, fn ($query, $id) => $query->whereKey($id))
            ->with(['metrics' => fn ($query) => $query
                ->select(['id', 'connected_account_id', 'captured_at', 'followers', 'following', 'posts_count'])
                ->orderByDesc('captured_at')->orderByDesc('id')->limit(1)])
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage, cursor: $this->cursor($cursor))
            ->through(function (ConnectedAccount $account): array {
                $metric = $account->metrics->first();
                $polling = $this->settings->accountMetricsPollingEnabled($account->platform) && ! $account->isDisabled();

                return [
                    'id' => $account->id,
                    'platform' => $account->platform->value,
                    'handle' => $account->handle,
                    'display_name' => $account->display_name,
                    'connection_status' => $account->status->value,
                    'enabled' => ! $account->isDisabled(),
                    'metrics_status' => $account->metrics_status?->value,
                    'last_attempt_at' => $account->metrics_captured_at?->toIso8601String(),
                    'polling_enabled' => $polling,
                    'captured_at' => $metric?->captured_at->toIso8601String(),
                    'freshness' => $this->freshness($metric?->captured_at, $account->metrics_status, $polling
                        ? $this->settings->accountMetricsPollIntervalMinutes($account->platform) * 60 : null),
                    'permission_evidence' => $this->permissionEvidence($account),
                    'diagnostic' => $this->diagnostic($account->metrics_status),
                    'followers' => $metric?->followers,
                    'following' => $metric?->following,
                    'posts_count' => $metric?->posts_count,
                ];
            });

        return [
            ...CursorPage::make($paginator),
            'meta' => [
                'source' => 'stored_provider_metrics',
                'coverage' => 'connected_workspace_accounts',
                'measurement' => 'latest_successful_capture',
                'generated_at' => now()->toIso8601String(),
                'notes' => 'No live provider request is made. Missing measurements are null, not zero. Follower history starts when collection is enabled.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function posts(int $perPage = 25, int $days = 90, ?string $accountId = null, ?string $cursor = null): array
    {
        $paginator = PostTarget::query()
            ->whereHas('post')
            ->whereHas('account')
            ->where('status', PostTargetStatus::Published)
            ->where('posted_at', '>=', now()->subDays($days))
            ->when($accountId, fn ($query, $id) => $query->where('connected_account_id', $id))
            ->select(['id', 'post_id', 'connected_account_id', 'platform', 'remote_id', 'remote_ids', 'posted_at', 'sections',
                'metrics_status', 'metrics_captured_at', 'metrics_unchanged_streak', 'status', 'media_upload_state'])
            ->with(['post:id,origin', 'account:id,handle,display_name,disabled_at', 'metrics' => fn ($query) => $query
                ->select(['id', 'post_target_id', 'captured_at', 'likes', 'comments', 'reposts', 'impressions'])
                ->orderByDesc('captured_at')->orderByDesc('id')->limit(1)])
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage, cursor: $this->cursor($cursor));

        $pagination = CursorPage::make($paginator)['pagination'];
        $rows = collect($paginator->items())
            ->filter(fn (PostTarget $target): bool => $target->publicationStatus() === PostTargetStatus::Published)
            ->map(function (PostTarget $target): array {
                $metric = $target->metrics->first();
                $polling = $this->settings->postMetricsPollingEnabled($target->platform) && ! $target->account?->isDisabled();
                $interval = $polling ? $this->cadence->effectiveIntervalSeconds($target, CarbonImmutable::now()) : null;

                return [
                    'id' => $target->id,
                    'post_id' => $target->post_id,
                    'connected_account_id' => $target->connected_account_id,
                    'platform' => $target->platform->value,
                    'handle' => $target->account?->handle,
                    'remote_id' => $target->remote_id,
                    'remote_ids' => $target->remote_ids ?: array_filter([$target->remote_id]),
                    'metric_scope' => count($target->remote_ids ?? []) > 1 ? 'combined_remote_post_ids' : 'single_remote_post_id',
                    'caption' => implode("\n\n", $target->sections ?? []),
                    'origin' => $target->post?->origin->value,
                    'posted_at' => $target->posted_at?->toIso8601String(),
                    'metrics_status' => $target->metrics_status?->value,
                    'last_attempt_at' => $target->metrics_captured_at?->toIso8601String(),
                    'polling_enabled' => $polling,
                    'captured_at' => $metric?->captured_at->toIso8601String(),
                    'freshness' => $this->freshness($metric?->captured_at, $target->metrics_status, $interval),
                    'likes' => $metric?->likes,
                    'comments' => $target->platform === Platform::Discord ? null : $metric?->comments,
                    'reposts' => in_array($target->platform, [Platform::YouTube, Platform::Discord], true) ? null : $metric?->reposts,
                    'impressions' => $metric?->impressions,
                    'impressions_definition' => match ($target->platform) {
                        Platform::YouTube, Platform::TikTok, Platform::Threads => 'video_or_post_views',
                        Platform::Instagram => 'views_or_reach',
                        default => 'provider_impressions_when_available',
                    },
                    'paid_context' => 'unknown',
                ];
            });

        return [
            'data' => $rows->values()->all(),
            'pagination' => $pagination,
            'meta' => [
                'source' => 'stored_provider_metrics',
                'coverage' => 'workspace_published_targets_including_synced_posts',
                'measurement' => 'latest_lifetime_counters',
                'publication_window_days' => $days,
                'generated_at' => now()->toIso8601String(),
                'notes' => 'The publication window filters post dates, not metric accrual dates. Missing or unsupported measurements are null, not zero. Inbox uploads awaiting completion are excluded. Captions help identify posts but do not prove identity or publication. Paid and organic engagement are not separated.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function permissionEvidence(ConnectedAccount $account): array
    {
        $capabilities = $account->capabilities ?? [];
        $verified = ($capabilities['oauth_scopes_verified'] ?? false) === true;
        $expected = match ($account->platform) {
            Platform::Instagram => ($capabilities['instagram_login'] ?? false) === true
                ? ['instagram_business_basic', 'instagram_business_manage_insights']
                : ['instagram_basic', 'instagram_manage_insights'],
            Platform::YouTube => ['https://www.googleapis.com/auth/youtube.readonly'],
            Platform::TikTok => ['user.info.stats', 'video.list'],
            Platform::Threads => ['threads_basic', 'threads_manage_insights'],
            default => [],
        };
        $granted = OAuthGrantedScopes::normalize($capabilities['oauth_scopes'] ?? null);
        $missing = $verified ? array_values(array_diff($expected, $granted)) : [];

        if ($account->platform === Platform::YouTube && array_intersect($granted, [
            'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl',
        ]) !== []) {
            $missing = [];
        }

        return [
            'status' => ! $verified || $expected === [] ? 'unknown' : ($missing === [] ? 'present' : 'missing'),
            'expected_scopes' => $expected,
            'missing_scopes' => $missing,
            'note' => 'Stored OAuth grant evidence only; a successful metrics capture verifies actual provider access. Unknown evidence does not require another sign-in by itself.',
        ];
    }

    private function diagnostic(?MetricsStatus $status): string
    {
        return match ($status) {
            MetricsStatus::Ok => 'The latest capture succeeded. Check captured_at and freshness before using these stored counters.',
            MetricsStatus::Failed => 'The latest capture failed or returned incomplete metrics. Earlier measurements, if any, are retained; missing data is not zero.',
            MetricsStatus::RateLimited => 'The provider rate or quota limit prevented the latest capture. Earlier measurements, if any, are retained.',
            MetricsStatus::Unsupported => 'This provider or account does not support the requested metrics.',
            null => 'Metrics have not been captured for this account yet.',
        };
    }

    public function freshness(?CarbonImmutable $capturedAt, ?MetricsStatus $status, ?int $interval): string
    {
        if ($capturedAt === null) {
            return 'missing';
        }

        return $status === MetricsStatus::Ok && $interval !== null && $capturedAt->addSeconds($interval)->isFuture()
            ? 'current'
            : 'stale';
    }

    private function cursor(?string $encoded): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }

        $cursor = Cursor::fromEncoded($encoded);

        if ($cursor === null || validator($cursor->toArray(), [
            'id' => ['required', 'uuid'],
            '_pointsToNextItems' => ['required', 'boolean'],
        ])->fails()) {
            throw ValidationException::withMessages(['cursor' => 'Use a cursor returned by this analytics endpoint.']);
        }

        return $cursor;
    }
}
