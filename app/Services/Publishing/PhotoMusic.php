<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostMedia;
use App\Models\PostTarget;

final class PhotoMusic
{
    public const array FIELDS = [
        'track', 'mode', 'required', 'artist', 'version', 'native_audio_id', 'source_url',
        'start_seconds', 'duration_seconds', 'music_volume', 'original_volume', 'sync_notes',
        'availability_checked_at', 'availability_note',
    ];

    /** @return array<string, list<string>> */
    public static function draftRules(): array
    {
        $rules = [
            'targets.*.content_override.music' => ['nullable', 'array:'.implode(',', self::FIELDS)],
            'targets.*.content_override.music.track' => ['nullable', 'string', 'max:200'],
            'targets.*.content_override.music.mode' => ['sometimes', 'in:native,existing_audio,none'],
            'targets.*.content_override.music.required' => ['sometimes', 'boolean', 'declined_if:targets.*.content_override.music.mode,existing_audio,none'],
            'targets.*.content_override.music.artist' => ['nullable', 'string', 'max:200'],
            'targets.*.content_override.music.version' => ['nullable', 'string', 'max:200'],
            'targets.*.content_override.music.native_audio_id' => ['nullable', 'string', 'max:200'],
            'targets.*.content_override.music.source_url' => ['nullable', 'url:https', 'max:2048'],
            'targets.*.content_override.music.start_seconds' => ['nullable', 'numeric', 'min:0', 'max:86400'],
            'targets.*.content_override.music.duration_seconds' => ['nullable', 'numeric', 'gt:0', 'max:86400'],
            'targets.*.content_override.music.music_volume' => ['nullable', 'integer', 'between:0,100'],
            'targets.*.content_override.music.original_volume' => ['nullable', 'integer', 'between:0,100'],
            'targets.*.content_override.music.sync_notes' => ['nullable', 'string', 'max:2000'],
            'targets.*.content_override.music.availability_checked_at' => ['nullable', 'date', 'before_or_equal:now'],
            'targets.*.content_override.music.availability_note' => ['nullable', 'string', 'max:1000'],
        ];
        foreach (array_diff(self::FIELDS, ['mode', 'required']) as $field) {
            $rules['targets.*.content_override.music.'.$field][] = 'prohibited_if:targets.*.content_override.music.mode,existing_audio,none';
        }

        return $rules;
    }

    public function requiresNativeMusic(PostTarget $target): bool
    {
        $plan = $this->plan($target);

        return $plan !== null && (! in_array($plan['mode'] ?? null, ['none', 'existing_audio'], true)
            || ($plan['required'] ?? false) === true || $this->track($target) !== null);
    }

    /** @return array<string, mixed>|null */
    public function plan(PostTarget $target): ?array
    {
        $plan = data_get($target->content_override, 'music');
        if (! is_array($plan)) {
            return null;
        }
        $plan = array_map(static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $plan);
        if (array_filter($plan, static fn (mixed $value): bool => $value !== null && $value !== false && $value !== '') === []) {
            return null;
        }

        return $plan;
    }

    /** @return array<string, mixed>|null */
    public function handoff(PostTarget $target): ?array
    {
        $plan = $this->plan($target);
        if (! $this->requiresNativeMusic($target) || ! in_array($target->platform, [Platform::Instagram, Platform::TikTok], true)
            || in_array($target->publicationStatus(), [PostTargetStatus::Published, PostTargetStatus::Completed, PostTargetStatus::Deleting, PostTargetStatus::Deleted], true)) {
            return null;
        }

        return [
            'kind' => 'native_music',
            'connected_account_id' => $target->connected_account_id,
            'platform' => $target->platform->value,
            'format' => $target->format->value,
            'plan' => $plan,
            'audio_attached' => false,
            'catalog_verified' => false,
            'availability_source' => 'manual_note',
            'instructions' => 'Select and preview this exact track/version in the destination app. Confirm availability for this account, region and use; apply the excerpt and mix notes before posting. Shoutrrr has not attached or verified this music. Keep the post unpublished if required music is unavailable.',
        ];
    }

    public function track(PostTarget $target): ?string
    {
        $track = data_get($target->content_override, 'music.track');

        return is_string($track) && trim($track) !== '' ? trim($track) : null;
    }

    /** @param list<PostMedia> $media
     * @return list<string>
     */
    public function issues(PostTarget $target, array $media): array
    {
        $plan = $this->plan($target);
        if (! $this->requiresNativeMusic($target)) {
            return [];
        }
        if (app(TikTokPublishingRoute::class)->hasAcceptedInboxTransfer($target)) {
            return [];
        }
        $native = ($plan['mode'] ?? null) === 'native';
        $photos = $media !== [] && collect($media)->every(fn (PostMedia $item): bool => $item->kind === 'image');
        $video = count($media) === 1 && $media[0]->kind === 'video';
        if ((! $photos && ! ($native && $video))
            || ! in_array($target->platform, [Platform::Instagram, Platform::TikTok], true)) {
            return [$native ? 'music_requires_supported_media' : 'music_requires_photos'];
        }
        if ($target->platform === Platform::Instagram) {
            return ['music_requires_native_completion'];
        }
        if (app(TikTokPublishingRoute::class)->forTarget($target) !== 'native'
            || config('services.tiktok.inbox_enabled') !== true
            || config('services.tiktok.direct_post_enabled') === true) {
            return ['music_requires_native_completion'];
        }

        return [];
    }
}
