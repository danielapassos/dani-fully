<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\Workspace;
use App\Services\Posts\NextSlotResolver;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Schedule a post into the next open slot of the workspace posting schedule. TikTok inbox targets schedule a video upload for manual completion, not a public TikTok post; the saved caption must be pasted manually. Read each target manual_completion instructions.')]
class QueuePostTool extends WorkspaceTool
{
    public function handle(Request $request, NextSlotResolver $resolver): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate(['post_id' => ['required', 'string']]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        $workspace = Workspace::query()->whereKey($workspaceId)->firstOrFail();
        $slot = $resolver->resolve($workspace);

        if ($slot === null) {
            return Response::error('No open posting slot available. Add posting-schedule slots first (see get_posting_schedule).');
        }

        $post->scheduled_at = $slot;
        $post->status = PostStatus::Scheduled;
        $post->save();

        return Response::text(json_encode(PostView::make($post->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['post_id' => $schema->string()->description('Id of the post to queue.')->required()];
    }
}
