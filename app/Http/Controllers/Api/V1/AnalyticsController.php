<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PostTargetStatus;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\CursorPage;
use App\Support\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /** Latest stored account measurements. This request does not poll providers. */
    public function accounts(Request $request, InstanceSettings $settings): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = ConnectedAccount::query()
            ->select(['id', 'platform', 'handle', 'display_name', 'status', 'metrics_status', 'metrics_captured_at', 'disabled_at'])
            ->with(['metrics' => fn ($query) => $query
                ->select(['id', 'connected_account_id', 'captured_at', 'followers', 'following', 'posts_count'])
                ->orderByDesc('captured_at')->orderByDesc('id')->limit(1)])
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(function (ConnectedAccount $account) use ($settings): array {
                $metric = $account->metrics->first();

                return [
                    'id' => $account->id,
                    'platform' => $account->platform->value,
                    'handle' => $account->handle,
                    'display_name' => $account->display_name,
                    'connection_status' => $account->status->value,
                    'enabled' => $account->disabled_at === null,
                    'metrics_status' => $account->metrics_status?->value,
                    'last_attempt_at' => $account->metrics_captured_at?->toIso8601String(),
                    'polling_enabled' => $settings->accountMetricsPollingEnabled($account->platform),
                    'captured_at' => $metric?->captured_at->toIso8601String(),
                    'followers' => $metric?->followers,
                    'following' => $metric?->following,
                    'posts_count' => $metric?->posts_count,
                ];
            });

        return response()->json([
            ...CursorPage::make($paginator),
            'meta' => ['source' => 'stored_provider_metrics', 'generated_at' => now()->toIso8601String()],
        ]);
    }

    /** Latest lifetime counters for published Petali-managed targets in the publication window. */
    public function posts(Request $request, InstanceSettings $settings): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'connected_account_id' => ['nullable', 'uuid'],
        ]);
        $days = $validated['days'] ?? 90;

        $paginator = PostTarget::query()
            ->whereHas('post')
            ->whereHas('account')
            ->where('status', PostTargetStatus::Published)
            ->where('posted_at', '>=', now()->subDays($days))
            ->when($validated['connected_account_id'] ?? null,
                fn ($query, $id) => $query->where('connected_account_id', $id))
            ->select(['id', 'post_id', 'connected_account_id', 'platform', 'remote_id', 'posted_at',
                'metrics_status', 'metrics_captured_at'])
            ->with(['account:id,handle,display_name', 'metrics' => fn ($query) => $query
                ->select(['id', 'post_target_id', 'captured_at', 'likes', 'comments', 'reposts', 'impressions'])
                ->orderByDesc('captured_at')->orderByDesc('id')->limit(1)])
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(function (PostTarget $target) use ($settings): array {
                $metric = $target->metrics->first();

                return [
                    'id' => $target->id,
                    'post_id' => $target->post_id,
                    'connected_account_id' => $target->connected_account_id,
                    'platform' => $target->platform->value,
                    'handle' => $target->account?->handle,
                    'remote_id' => $target->remote_id,
                    'posted_at' => $target->posted_at?->toIso8601String(),
                    'metrics_status' => $target->metrics_status?->value,
                    'last_attempt_at' => $target->metrics_captured_at?->toIso8601String(),
                    'polling_enabled' => $settings->postMetricsPollingEnabled($target->platform),
                    'captured_at' => $metric?->captured_at->toIso8601String(),
                    'likes' => $metric?->likes,
                    'comments' => $metric?->comments,
                    'reposts' => $metric?->reposts,
                    'impressions' => $metric?->impressions,
                    'paid_context' => 'unknown',
                ];
            });

        return response()->json([
            ...CursorPage::make($paginator),
            'meta' => [
                'source' => 'stored_provider_metrics',
                'coverage' => 'petali_managed_published_targets',
                'measurement' => 'latest_lifetime_counters',
                'publication_window_days' => $days,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
