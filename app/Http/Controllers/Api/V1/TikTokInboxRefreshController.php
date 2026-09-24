<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesWorkspacePost;
use App\Http\Controllers\Controller;
use App\Services\Publishing\TikTokInboxReconciliation;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;

class TikTokInboxRefreshController extends Controller
{
    use ResolvesWorkspacePost;

    public function store(string $id, string $targetId, TikTokInboxReconciliation $reconciliation): JsonResponse
    {
        $post = $this->findPostOrFail($id);
        $this->authorize('update', $post);
        $target = $post->targets()->whereKey($targetId)->firstOrFail();
        abort_unless($reconciliation->eligible($target), 409, 'This target has no existing TikTok inbox upload to check.');
        $reconciliation->reconcile($target, manual: true);
        $tracking = $reconciliation->view($target->fresh());

        return response()->json(['tracking' => $tracking, 'message' => $tracking['message'], 'post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
