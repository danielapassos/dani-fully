<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\TikTokInboxReconciliation;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TikTokInboxRefreshController extends Controller
{
    public function store(Request $request, Post $post, PostTarget $target, TikTokInboxReconciliation $reconciliation): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $post);
        abort_unless($target->post_id === $post->id, 404);
        abort_unless($reconciliation->eligible($target), 409, 'This target has no existing TikTok inbox upload to check.');
        $reconciliation->reconcile($target, manual: true);
        $tracking = $reconciliation->view($target->fresh());

        if ($request->headers->has('X-Inertia')) {
            return back();
        }

        return response()->json(['tracking' => $tracking, 'message' => $tracking['message'], 'post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
