<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Publishing\InstagramReelCover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstagramCoverController extends Controller
{
    public function index(Request $request, Post $post, InstagramReelCover $covers): JsonResponse
    {
        $this->authorize('update', $post);
        $validated = $request->validate(['selected' => ['nullable', 'uuid'], 'cursor' => ['nullable', 'string', 'max:1024']]);
        $page = $covers->available($post->workspace_id)->orderByDesc('id')->cursorPaginate(24);
        $media = $page->getCollection();
        if (isset($validated['selected']) && ! $media->contains('id', $validated['selected'])) {
            $selected = $covers->available($post->workspace_id)->whereKey($validated['selected'])->first();
            if ($selected !== null) {
                $media->prepend($selected);
            }
        }

        return response()->json([
            'media' => $media->map(fn (PostMedia $item): array => $item->toView())->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
