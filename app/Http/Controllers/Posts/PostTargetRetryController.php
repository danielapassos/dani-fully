<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Exceptions\PostTargetRetryRejected;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\ManualPostTargetRetry;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PostTargetRetryController extends Controller
{
    public function store(
        Request $request,
        Post $post,
        PostTarget $target,
        ManualPostTargetRetry $retry,
    ): JsonResponse|RedirectResponse {
        abort_unless($request->user()->can('update', $post), 403);
        abort_unless($target->post_id === $post->id, 404);

        try {
            $retry->dispatch($target);
        } catch (PostTargetRetryRejected $exception) {
            abort(409, $exception->getMessage());
        }

        if ($request->headers->has('X-Inertia')) {
            return back();
        }

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
