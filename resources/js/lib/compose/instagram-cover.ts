import type { ComposerState } from '@/lib/compose/composer-state';
import type { Account } from '@/types/compose';

export const INSTAGRAM_COVER_MAX_BYTES = 8 * 1024 * 1024;

/** A cover is separate from placements and only belongs to a single-video Reel. */
export function canChooseInstagramCover(
    state: Pick<
        ComposerState,
        'formatByAccount' | 'media' | 'placements' | 'placementsByAccount'
    >,
    account: Pick<Account, 'id' | 'platform'>,
): boolean {
    if (
        account.platform !== 'instagram' ||
        state.formatByAccount[account.id] === 'story'
    ) {
        return false;
    }
    const placements =
        state.placementsByAccount[account.id] ?? state.placements;
    const ids = new Set(Object.values(placements).flat());
    const effectiveMedia = state.media.filter((item) => ids.has(item.id));

    return effectiveMedia.length === 1 && effectiveMedia[0].kind === 'video';
}

export function instagramCoverFileError(file: File): string | null {
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
        return 'Choose a JPEG or PNG cover image.';
    }
    if (file.size === 0 || file.size > INSTAGRAM_COVER_MAX_BYTES) {
        return 'Choose a cover image larger than 0 bytes and no larger than 8 MB.';
    }

    return null;
}
