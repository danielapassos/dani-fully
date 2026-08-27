<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\SegmentMediaResolver;
use App\Services\Publishing\TargetMediaSelection;

final class PublicPostView
{
    /** @return array<string, mixed> */
    public static function make(Post $post): array
    {
        $post->loadMissing(['targets.account', 'targets.placements', 'media']);
        $selectionResolver = app(TargetMediaSelection::class);
        $segmentResolver = app(SegmentMediaResolver::class);
        $allMedia = array_values($post->media->all());

        return [
            'base_text' => $post->base_text,
            'status' => $post->status->value,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'created_at' => $post->created_at->toIso8601String(),
            'targets' => $post->targets->map(function (PostTarget $target) use ($allMedia, $segmentResolver, $selectionResolver): array {
                $selection = $selectionResolver->resolve($target, $target->placements);
                $mediaBySection = $segmentResolver->resolve(
                    sections: $target->sections,
                    sectionSources: $target->section_sources ?? [],
                    segmentBreaks: $target->segment_breaks ?? [],
                    placements: $selection['placements'],
                    allMedia: $allMedia,
                    placementsExplicit: $selection['explicit'],
                );

                return [
                    'platform' => $target->platform->value,
                    'sections' => $target->sections,
                    'status' => $target->status->value,
                    'handle' => $target->account?->handle,
                    'display_name' => $target->account?->display_name,
                    'avatar_url' => $target->account?->avatar_url,
                    'media_by_section' => array_map(
                        static fn (int $index): array => array_map(
                            self::mediaView(...),
                            $mediaBySection[$index] ?? [],
                        ),
                        array_keys($target->sections),
                    ),
                ];
            })->all(),
            // Target-aware shares expose only the media actually present in each
            // section above. The top-level list exists solely for legacy/no-target
            // drafts; keeping excluded attachments out also withholds their URLs.
            'media' => $post->targets->isEmpty()
                ? array_map(self::mediaView(...), $allMedia)
                : [],
        ];
    }

    /** @return array{id: string, url: string, mime: string, alt_text: string|null} */
    private static function mediaView(PostMedia $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->url(),
            'mime' => $media->mime,
            'alt_text' => $media->alt_text,
        ];
    }
}
