import type { MediaView, TargetView } from '@/types/compose';

/** Media shown for each published section, matching the server resolver. */
export function resolveTargetMediaBySection(
    target: TargetView,
    media: MediaView[],
): MediaView[][] {
    const placements = target.placements ?? [];
    const usesPlacements =
        target.placements_explicit === true || placements.length > 0;
    const sectionCount = Math.max(1, target.sections.length);

    if (usesPlacements) {
        const authoredByRef = new Map<string, number>([['__head__', 0]]);
        (target.segment_breaks ?? []).forEach((ref, index) => {
            authoredByRef.set(ref, index + 1);
        });
        const firstSectionForAuthored = new Map<number, number>();
        (target.section_sources ?? []).forEach((authored, section) => {
            if (!firstSectionForAuthored.has(authored)) {
                firstSectionForAuthored.set(authored, section);
            }
        });
        const sectionForAuthored = (authored: number): number => {
            const direct = firstSectionForAuthored.get(authored);
            if (direct !== undefined) {
                return direct;
            }
            for (let previous = authored - 1; previous >= 0; previous -= 1) {
                const fallback = firstSectionForAuthored.get(previous);
                if (fallback !== undefined) {
                    return fallback;
                }
            }

            return 0;
        };

        const byId = new Map(media.map((item) => [item.id, item]));
        const seen = new Set<string>();
        const out = Array.from(
            { length: sectionCount },
            () => [] as MediaView[],
        );
        for (const placement of [...placements].sort(
            (left, right) => left.position - right.position,
        )) {
            if (seen.has(placement.media_id)) {
                continue;
            }
            const item = byId.get(placement.media_id);
            if (!item) {
                continue;
            }
            seen.add(placement.media_id);
            const authored = authoredByRef.get(placement.segment_ref) ?? 0;
            const section = Math.min(
                sectionCount - 1,
                sectionForAuthored(authored),
            );
            out[section].push(item);
        }

        return out;
    }

    const legacyIds = target.content_override?.media_ids;
    if (legacyIds !== undefined) {
        const selected = legacyIds
            .map((id) => media.find((item) => item.id === id))
            .filter((item): item is MediaView => item !== undefined);

        return [
            selected,
            ...Array.from({ length: sectionCount - 1 }, () => []),
        ];
    }

    return [media, ...Array.from({ length: sectionCount - 1 }, () => [])];
}

/** Flattened selection for grids that do not render individual thread parts. */
export function resolveTargetMedia(
    target: TargetView,
    media: MediaView[],
): MediaView[] {
    return resolveTargetMediaBySection(target, media).flat();
}
