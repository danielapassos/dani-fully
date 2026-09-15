<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\PostListItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [
            'month.regex' => 'Provide the month as YYYY-MM (01–12), for example 2026-06.',
        ]);

        $anchor = CarbonImmutable::createFromFormat('Y-m-d', "{$validated['month']}-01")->startOfMonth();
        $start = $anchor->startOfWeek(CarbonImmutable::SUNDAY);
        $end = $start->addDays(41)->endOfDay();

        $posts = Post::query()
            ->with(['author:id,name', 'workspace:id,is_initial', 'targets.account', 'media'])
            ->whereIn('status', [
                PostStatus::Scheduled->value, PostStatus::Published->value,
                PostStatus::Partial->value, PostStatus::Failed->value,
                PostStatus::AwaitingAction->value, PostStatus::Completed->value,
            ])
            ->where(fn ($q) => $q
                ->whereBetween('scheduled_at', [$start, $end])
                ->orWhereBetween('published_at', [$start, $end])
                ->orWhere(fn ($completed) => $completed
                    ->whereIn('status', [PostStatus::AwaitingAction->value, PostStatus::Completed->value])
                    ->whereNull('scheduled_at')
                    ->whereNull('published_at')
                    ->whereBetween('updated_at', [$start, $end])))
            ->orderByRaw('COALESCE(scheduled_at, published_at, updated_at) ASC')
            ->get()
            ->map(fn (Post $post): array => PostListItem::make($post));

        return response()->json(['month' => $validated['month'], 'posts' => $posts]);
    }
}
