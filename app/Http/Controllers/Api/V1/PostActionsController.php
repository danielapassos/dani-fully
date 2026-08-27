<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PostStatus;
use App\Exceptions\PostTargetRetryRejected;
use App\Http\Controllers\Api\V1\Concerns\ResolvesWorkspacePost;
use App\Http\Controllers\Controller;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Services\Posts\NextSlotResolver;
use App\Services\Publishing\ManualPostTargetRetry;
use App\Services\Publishing\PublishDispatcher;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class PostActionsController extends Controller
{
    use ResolvesWorkspacePost;

    public function schedule(Request $request, string $id): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ], [
            'scheduled_at.after' => 'Choose a time in the future — a post cannot be scheduled in the past.',
        ]);

        if (($validated['scheduled_at'] ?? null) !== null) {
            $model->scheduled_at = $validated['scheduled_at'];
            $model->status = PostStatus::Scheduled;
        } else {
            $model->scheduled_at = null;
            $model->status = PostStatus::Draft;
        }
        $model->save();

        return response()->json(['post' => PostView::make($model->fresh(['targets.account', 'media']))]);
    }

    public function queue(string $id, NextSlotResolver $resolver): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);
        $workspace = Workspace::query()->whereKey(Context::get('workspace_id'))->firstOrFail();

        $slot = $resolver->resolve($workspace);

        if ($slot === null) {
            abort(422, 'No open posting slot available. Add posting-schedule slots first.');
        }

        $model->scheduled_at = $slot;
        $model->status = PostStatus::Scheduled;
        $model->save();

        return response()->json(['post' => PostView::make($model->fresh(['targets.account', 'media']))]);
    }

    public function publish(string $id, PublishDispatcher $dispatcher): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);

        if (! $dispatcher->hasRunnableTargets($model)) {
            return response()->json(['message' => PublishDispatcher::NO_RUNNABLE_MESSAGE], 422);
        }

        $blocked = $dispatcher->blockingTargets($model);
        if ($blocked !== []) {
            return response()->json([
                'message' => "Some accounts can't be published yet.",
                'blocked' => $blocked,
            ], 422);
        }

        $model->forceFill(['status' => PostStatus::Publishing->value])->save();
        $dispatcher->dispatchForPost($model);

        return response()->json([
            'status' => 'queued',
            'message' => 'Publishing started. Poll GET /posts/{id} for per-target status.',
            'post' => PostView::make($model->fresh(['targets.account', 'media'])),
        ], 202);
    }

    public function retry(string $id, string $targetId, ManualPostTargetRetry $retry): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);

        $postTarget = PostTarget::query()->whereKey($targetId)->where('post_id', $model->id)->first();

        if ($postTarget === null) {
            abort(404, 'No such target on that post.');
        }

        try {
            $retry->dispatch($postTarget);
        } catch (PostTargetRetryRejected $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'status' => 'queued',
            'post' => PostView::make($model->fresh(['targets.account', 'media'])),
        ], 202);
    }
}
