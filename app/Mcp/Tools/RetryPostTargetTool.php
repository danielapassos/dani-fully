<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\PostTargetRetryRejected;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\ManualPostTargetRetry;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Retry a safely retryable failed or skipped publish target. An unconfirmed provider outcome requires manual review and cannot be retried. Outward-facing (re-attempts a live post). Requires confirm=true.')]
class RetryPostTargetTool extends WorkspaceTool
{
    public function handle(Request $request, ManualPostTargetRetry $retry): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'target_id' => ['required', 'string'],
            'confirm' => ['boolean'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        // Scope the target to this post (and thus this workspace).
        $target = PostTarget::query()->whereKey($validated['target_id'])->where('post_id', $post->id)->first();
        if ($target === null) {
            return Response::error('No such target on that post.');
        }

        if ($unconfirmed = $this->requireConfirmation($request, 'This will re-attempt publishing to the connected account.')) {
            return $unconfirmed;
        }

        try {
            $retry->dispatch($target);
        } catch (PostTargetRetryRejected $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::text(json_encode([
            'status' => 'queued',
            'post' => PostView::make($post->fresh(['targets.account', 'media'])),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Post id.')->required(),
            'target_id' => $schema->string()->description('Safely retryable failed or skipped target id. Unconfirmed provider outcomes require manual review.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to retry.'),
        ];
    }
}
