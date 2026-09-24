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
    /** Latest stored account measurements. This request does not poll providers. */
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

    /** Latest lifetime counters for workspace targets in the publication window. */
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
