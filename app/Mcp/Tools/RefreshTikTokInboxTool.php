<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Publishing\TikTokInboxReconciliation;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('refresh_tiktok_inbox')]
#[Description('Refresh the status of an existing TikTok inbox upload by its exact post and target IDs. Verifies public video IDs on the connected account and saves completion evidence. Never uploads, retries, or publishes content. Requires write access to update tracking, but no publish confirmation. Caption matching is never used as proof. Checks are limited to once per minute per target.')]
class RefreshTikTokInboxTool extends WorkspaceTool
{
    public function handle(Request $request, TikTokInboxReconciliation $reconciliation): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            'target_id' => ['required', 'uuid'],
        ]);
        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }
        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }
        $target = $post->targets()->whereKey($validated['target_id'])->first();
        if ($target === null) {
            return Response::error('No such target on that post.');
        }
        if (! $reconciliation->eligible($target)) {
            return Response::error('This target has no existing TikTok inbox upload to check.');
        }
        $reconciliation->reconcile($target, manual: true);

        return Response::text(json_encode([
            'tracking' => $reconciliation->view($target->fresh()),
            'post' => PostView::make($post->fresh(['targets.account', 'media'])),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Exact existing Shoutrrr post UUID.')->required(),
            'target_id' => $schema->string()->description('Exact TikTok target UUID on that post.')->required(),
        ];
    }
}
