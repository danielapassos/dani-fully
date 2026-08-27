<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\PostMediaPlacement;
use App\Models\PostTarget;

final class TargetMediaSelection
{
    /**
     * Normalize current placement rows and the retired content-override media
     * list into one explicit selection. Real rows win; a configured empty set
     * wins over legacy data; the old override is read only for pre-marker posts.
     *
     * @param  iterable<PostMediaPlacement>  $placementModels
     * @return array{explicit: bool, placements: list<array{post_media_id: string, segment_ref: string, position: int}>}
     */
    public function resolve(PostTarget $target, iterable $placementModels): array
    {
        $placements = [];
        foreach ($placementModels as $placement) {
            $placements[] = [
                'post_media_id' => $placement->post_media_id,
                'segment_ref' => $placement->segment_ref,
                'position' => $placement->position,
            ];
        }

        if ($placements !== []) {
            return ['explicit' => true, 'placements' => $placements];
        }

        if ($target->placements_explicit) {
            return ['explicit' => true, 'placements' => []];
        }

        $override = $target->content_override;
        if (! is_array($override) || ! array_key_exists('media_ids', $override)) {
            return ['explicit' => false, 'placements' => []];
        }

        $ids = is_array($override['media_ids']) ? $override['media_ids'] : [];
        $seen = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $placements[] = [
                'post_media_id' => $id,
                'segment_ref' => SegmentMediaResolver::HEAD,
                'position' => count($placements),
            ];
        }

        return ['explicit' => true, 'placements' => $placements];
    }
}
