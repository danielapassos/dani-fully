<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Dto\Post\DraftData;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\User;
use App\Services\ConnectedAccounts\TikTok\TikTokPostOptions;
use App\Services\Posts\DraftService;
use App\Services\Publishing\InstagramReelCover;
use App\Services\Publishing\YouTubePostOptions;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a new draft post in the bound workspace. Destination kind is all (every connected account), set (an account set id), or account (a single connected account id).')]
class CreatePostTool extends WorkspaceTool
{
    public function handle(Request $request, DraftService $drafts): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'create', Post::class)) {
            return $denied;
        }

        $validated = $request->validate([
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
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.content_override' => ['nullable', 'array'],
            'targets.*.content_override.segments' => ['array'],
            'targets.*.content_override.segments.*' => ['nullable', 'string'],
            'targets.*.content_override.media_ids' => ['array'],
            'targets.*.content_override.media_ids.*' => ['string'],
            ...TikTokPostOptions::draftRules(),
            ...YouTubePostOptions::draftRules(),
            ...InstagramReelCover::draftRules(),
        ]);

        /** @var User $user */
        $user = $request->user();

        $segments = isset($validated['segments']) && $validated['segments'] !== []
            ? array_values($validated['segments'])
            : [(string) ($validated['base_text'] ?? '')];

        $post = $drafts->createDraft(
            $workspaceId,
            $user,
            $validated['destination'],
            $segments,
            $validated['mentions'] ?? [],
            data: DraftData::fromArray($validated),
        );

        return Response::text(json_encode(PostView::make($post->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'base_text' => $schema->string()->description('The post body text. Use {{mention:id}} tokens for platform-specific mentions.'),
            'mentions' => $schema->array()->description('Mention placeholders with per-platform handles.'),
            'destination' => $schema->object([
                'kind' => $schema->string()->enum(['all', 'set', 'account'])->required(),
                'id' => $schema->string()->description('Account set id (kind=set) or connected account id (kind=account).'),
            ])->description('Where to post.')->required(),
            'targets' => $schema->array()->description('Per-account declarations: connected_account_id and content_override.tiktok or content_override.youtube. An Instagram Reel can set content_override.instagram.cover_media_id to a workspace image id; the cover stays separate from video media. YouTube can set content_override.youtube.thumbnail_media_id to a workspace JPEG/PNG image id up to 8 MB; YouTube checks cover eligibility and the upload stays private if its cover fails. Supply the explicit publishing choices for each selected account; missing choices remain incomplete drafts.'),
        ];
    }
}
