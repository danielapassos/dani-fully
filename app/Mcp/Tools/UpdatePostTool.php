<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Dto\Post\DraftData;
use App\Enums\PostFormat;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\ConnectedAccounts\TikTok\TikTokPostOptions;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostStaleWriteException;
use App\Services\Publishing\YouTubePostOptions;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update a draft post: text, destination, per-account overrides, and attached media. Pass expected_updated_at (from get_post) for optimistic concurrency; a mismatch returns a stale-write error.')]
class UpdatePostTool extends WorkspaceTool
{
    public function handle(Request $request, DraftService $drafts): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'base_text' => ['present', 'nullable', 'string'],
            'segments' => ['array'],
            'segments.*' => ['string'],
            'mentions' => ['array'],
            'mentions.*.id' => ['required', 'string'],
            'mentions.*.label' => ['required', 'string'],
            'mentions.*.handles' => ['array'],
            'mentions.*.handles.x' => ['nullable', 'string'],
            'mentions.*.handles.bluesky' => ['nullable', 'string'],
            'mentions.*.handles.linkedin' => ['nullable', 'string'],
            'mentions.*.handles.linkedin_urn' => ['nullable', 'string', 'max:255'],
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'none', 'set', 'account', 'accounts'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            'destination.ids' => ['array', 'required_if:destination.kind,accounts'],
            'destination.ids.*' => ['string'],
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.auto_split' => ['boolean'],
            'targets.*.format' => ['nullable', Rule::enum(PostFormat::class)],
            'targets.*.content_override' => ['nullable', 'array'],
            'targets.*.content_override.text' => ['nullable', 'string'],
            'targets.*.content_override.segments' => ['array'],
            'targets.*.content_override.segments.*' => ['nullable', 'string'],
            'targets.*.content_override.media_ids' => ['array'],
            'targets.*.content_override.media_ids.*' => ['string'],
            ...TikTokPostOptions::draftRules(),
            ...YouTubePostOptions::draftRules(),
            'targets.*.segment_breaks' => ['nullable', 'array'],
            'targets.*.segment_breaks.*' => ['string'],
            'targets.*.placements' => ['nullable', 'array'],
            'targets.*.placements.*.media_id' => ['required', 'string'],
            'targets.*.placements.*.segment_ref' => ['required', 'string'],
            'targets.*.placements.*.position' => ['required', 'integer'],
            'media_ids' => ['array'],
            'media_ids.*' => ['string'],
            'segment_breaks' => ['array'],
            'segment_breaks.*' => ['string'],
            'placements' => ['array'],
            'placements.*.media_id' => ['required', 'string'],
            'placements.*.segment_ref' => ['required', 'string'],
            'placements.*.position' => ['required', 'integer'],
            'auto_repost' => ['sometimes', 'nullable', 'boolean'],
            'expected_updated_at' => ['nullable', 'string'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        try {
            $updated = $drafts->updateDraft($post, DraftData::fromArray($validated));
        } catch (PostStaleWriteException $e) {
            return Response::error($e->getMessage().' Re-fetch the post with get_post for the current expected_updated_at, then retry.');
        }

        return Response::text(json_encode(PostView::make($updated->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the draft to update.')->required(),
            'base_text' => $schema->string()->description('New post body text. Use {{mention:id}} tokens for platform-specific mentions.'),
            'mentions' => $schema->array()->description('Mention placeholders with per-platform handles.'),
            'destination' => $schema->object([
                'kind' => $schema->string()->enum(['all', 'none', 'set', 'account', 'accounts'])->required(),
                'id' => $schema->string(),
                'ids' => $schema->array(),
            ])->description('Where to post.')->required(),
            'media_ids' => $schema->array()->description('Ordered media ids to attach (from add_post_media).'),
            'segment_breaks' => $schema->array()->description('Ordered authored-segment break ids used by media placements.'),
            'placements' => $schema->array()->description('Canonical media placements. Each item has media_id, segment_ref, and zero-based position.'),
            'targets' => $schema->array()->description('Optional per-account settings. A target may carry connected_account_id, format, content_override (including explicit tiktok or youtube publishing choices), segment_breaks, and placements; an explicit empty placements array excludes all attached media for that account.'),
            'auto_repost' => $schema->boolean()->description('Per-post auto-boost override: true always reshares, false never does, omit to leave the current setting unchanged (null = each account\'s automatic performance gate).'),
            'expected_updated_at' => $schema->string()->description('The post updated_at you last saw, for conflict detection.'),
        ];
    }
}
