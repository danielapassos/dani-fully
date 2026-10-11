<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Models\PostMedia;
use App\Models\PostTarget;

final class PhotoMusic
{
    /** @return array<string, list<string>> */
    public static function draftRules(): array
    {
        return [
            'targets.*.content_override.music' => ['nullable', 'array:track'],
            'targets.*.content_override.music.track' => ['nullable', 'string', 'max:200'],
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
        if ($this->track($target) === null) {
            return [];
        }
        if ($media === [] || collect($media)->contains(fn (PostMedia $item): bool => $item->kind !== 'image')
            || ! in_array($target->platform, [Platform::Instagram, Platform::TikTok], true)) {
            return ['music_requires_photos'];
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
