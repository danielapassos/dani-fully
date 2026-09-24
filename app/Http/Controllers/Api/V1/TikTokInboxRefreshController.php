<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesWorkspacePost;
use App\Http\Controllers\Controller;
use App\Services\Publishing\TikTokInboxReconciliation;
use App\Support\PostView;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class TikTokInboxRefreshController extends Controller
{
    use ResolvesWorkspacePost;

    /**
     * Refresh completion of an existing TikTok inbox upload.
     *
     * Requires write scope and access to this exact workspace post and target.
     * Reads the original saved TikTok upload, then verifies every returned public
     * video ID on the same connected account. Never uploads, retries, publishes,
     * or matches a post by caption. Repeated checks within one minute reuse saved
     * tracking. A delivered inbox upload is not a public post.
     *
     * tracking.checked_at is the latest attempt; tracking.verified_at is the last
     * successful public verification. Previously verified public_posts remain
     * historical evidence after an inconclusive check; read message and error.
     * public_posts contains every verified video with its ID, URL, actual caption,
     * and Unix created_at timestamp. The response also contains the refreshed post.
     */
    #[PathParameter('id', description: 'Existing Shoutrrr post UUID in the bound workspace.', type: 'string', format: 'uuid')]
    #[PathParameter('targetId', description: 'Existing TikTok target UUID belonging to this post.', type: 'string', format: 'uuid')]
    #[Response(409, description: 'The target has no eligible existing TikTok inbox upload to check.')]
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
