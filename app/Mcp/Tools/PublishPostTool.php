<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Publishing\PublishDispatcher;
use App\Services\Publishing\TikTokInboxHandoff;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Submit a post to its connected accounts now using their selected publishing settings. Requires confirm=true. TikTok inbox targets upload only the video for manual completion; the caption is not transferred and must be pasted in TikTok. Other targets may publish publicly. Submission is asynchronous: poll get_post for each target result and manual_completion instructions. Queued or awaiting_action is not proof of a live post.')]
class PublishPostTool extends WorkspaceTool
{
    public function handle(Request $request, PublishDispatcher $dispatcher, WorkspaceSubscriptionGate $subscriptions, TikTokInboxHandoff $handoff): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'confirm' => ['boolean'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        if ($unconfirmed = $this->requireConfirmation($request, $handoff->confirmation($post))) {
            return $unconfirmed;
        }

        if (! $subscriptions->canPublish($post->workspace()->firstOrFail())) {
            return Response::error('This workspace requires an active subscription before publishing.');
        }

        if (! $dispatcher->hasRunnableTargets($post)) {
            return Response::error(PublishDispatcher::NO_RUNNABLE_MESSAGE);
        }

        $blocked = $dispatcher->blockingTargets($post);
        if ($blocked !== []) {
            return Response::error("Some accounts can't be published yet: ".json_encode($blocked, JSON_THROW_ON_ERROR));
        }

        $message = $handoff->submissionMessage($post);
        $post->forceFill(['status' => PostStatus::Publishing->value])->save();
        $dispatcher->dispatchForPost($post);

        return Response::text(json_encode([
            'status' => 'queued',
            'message' => $message.' Poll get_post for per-target status.',
            'post' => PostView::make($post->fresh(['targets.account', 'media'])),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to submit using its selected publishing settings.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to submit. TikTok inbox uploads still require manual posting and caption entry in TikTok.'),
        ];
    }
}
