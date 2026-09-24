<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use App\Enums\PostFormat;
use App\Http\Requests\Post\Concerns\DerivesMentionHandleRules;
use App\Models\Post;
use App\Services\ConnectedAccounts\TikTok\TikTokPostOptions;
use App\Services\Publishing\InstagramReelCover;
use App\Services\Publishing\InstagramTrialReel;
use App\Services\Publishing\YouTubePostOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    use DerivesMentionHandleRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', Post::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_text' => ['sometimes', 'nullable', 'string'],
            'segments' => ['present', 'array'],
            'segments.*' => ['nullable', 'string'],
            'mentions' => ['array'],
            'mentions.*.id' => ['required', 'string'],
            'mentions.*.label' => ['required', 'string'],
            'mentions.*.handles' => ['array'],
            ...$this->mentionHandleRules(),
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'none', 'set', 'account', 'accounts'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            'destination.ids' => ['array', 'required_if:destination.kind,accounts'],
            'destination.ids.*' => ['string'],
            'auto_repost' => ['sometimes', 'nullable', 'boolean'],
            'skip_sync' => ['sometimes', 'boolean'],
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.format' => ['nullable', Rule::enum(PostFormat::class)],
            'targets.*.content_override' => ['nullable', 'array'],
            'targets.*.content_override.segments' => ['array'],
            'targets.*.content_override.segments.*' => ['nullable', 'string'],
            'targets.*.content_override.media_ids' => ['array'],
            'targets.*.content_override.media_ids.*' => ['string'],
            ...TikTokPostOptions::draftRules(),
            ...YouTubePostOptions::draftRules(),
            ...InstagramReelCover::draftRules(),
            ...InstagramTrialReel::draftRules(),
            'segment_breaks' => ['array'],
            'segment_breaks.*' => ['string'],
            'placements' => ['array'],
            'placements.*.media_id' => ['required', 'string'],
            'placements.*.segment_ref' => ['required', 'string'],
            'placements.*.position' => ['required', 'integer'],
        ];
    }
}
