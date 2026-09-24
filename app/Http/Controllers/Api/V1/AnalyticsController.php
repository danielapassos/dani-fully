<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Metrics\StoredAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * Read the latest stored account measurements.
     *
     * Includes captured_at, last_attempt_at, metrics_status, polling_enabled,
     * freshness and stored permission_evidence. Missing measurements are null,
     * not zero. An unknown OAuth grant is not itself a reconnect requirement.
     * This request does not poll providers. Follow pagination.next_cursor for
     * remaining accounts in the bound workspace.
     */
    public function accounts(Request $request, StoredAnalytics $analytics): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:1000'],
            'connected_account_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($analytics->accounts(
            (int) ($validated['per_page'] ?? 25),
            $validated['cursor'] ?? null,
            $validated['connected_account_id'] ?? null,
        ));
    }

    /**
     * Read stored lifetime counters for published workspace targets.
     *
     * The days filter selects publication dates, not engagement accrued during
     * that period. remote_ids and metric_scope identify whether counters cover
     * one remote post or an aggregate across the target's remote posts. Captions
     * are descriptive context, never proof of identity. Inbox delivery and private
     * uploads without public evidence are excluded. Read captured_at, freshness,
     * metrics_status and polling_enabled before interpreting measurements.
     * Missing or unsupported measurements are null; paid_context is unknown.
     * This request does not poll providers. Follow pagination.next_cursor.
     */
    public function posts(Request $request, StoredAnalytics $analytics): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'connected_account_id' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($analytics->posts(
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['days'] ?? 90),
            $validated['connected_account_id'] ?? null,
            $validated['cursor'] ?? null,
        ));
    }
}
