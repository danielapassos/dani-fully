<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Post\BeginVideoUploadRequest;
use App\Models\Post;
use App\Services\Posts\VideoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class VideoUploadsController extends Controller
{
    /**
     * Begin an original-quality MP4 upload.
     *
     * PUT the original file bytes to the returned temporary URL with its headers,
     * then complete the upload. Treat the URL and headers as temporary secrets.
     * No transcoding or resizing occurs. Platform publishing limits still apply.
     * This operation creates no post and does not publish or schedule content.
     */
    public function store(BeginVideoUploadRequest $request, VideoUploadService $uploads): JsonResponse
    {
        return response()->json($uploads->begin(
            (string) Context::get('workspace_id'),
            (string) $request->user()->getAuthIdentifier(),
            $request->validated(),
        ), 201)->header('Cache-Control', 'no-store');
    }

    /**
     * Finalize an MP4 upload in the authenticated user's workspace.
     *
     * Call after the signed PUT succeeds. Completion is idempotent and returns
     * library media metadata. Attach its id with the existing post update API;
     * this endpoint never creates, schedules, or publishes a post.
     */
    public function complete(Request $request, string $uploadId, VideoUploadService $uploads): JsonResponse
    {
        $this->authorize('create', Post::class);

        return response()->json($uploads->complete(
            (string) Context::get('workspace_id'),
            (string) $request->user()->getAuthIdentifier(),
            $uploadId,
        ))->header('Cache-Control', 'no-store');
    }
}
