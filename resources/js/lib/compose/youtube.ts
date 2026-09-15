import type { ComposerState } from '@/lib/compose/composer-state';
import type { Account, YouTubePostOptions } from '@/types/compose';

export const YOUTUBE_DEFAULT_OPTIONS: YouTubePostOptions = {
    category_id: '22',
    notify_subscribers: false,
};

/** Laravel can return an explicit empty description as null; omission inherits. */
export function normalizeYouTubePostOptions(
    options: YouTubePostOptions,
): YouTubePostOptions {
    return options.description === null
        ? { ...options, description: '' }
        : options;
}

export function youtubePostOptionsEqual(
    left: YouTubePostOptions,
    right: YouTubePostOptions,
): boolean {
    const local = { ...normalizeYouTubePostOptions(left) };
    const server = { ...normalizeYouTubePostOptions(right) };
    // A cleared thumbnail must stay explicit in save payloads, but is equivalent
    // to no reference when reconciling the saved result.
    if (local.thumbnail_media_id == null) delete local.thumbnail_media_id;
    if (server.thumbnail_media_id == null) delete server.thumbnail_media_id;
    const keys = new Set([
        ...Object.keys(local),
        ...Object.keys(server),
    ]) as Set<keyof YouTubePostOptions>;

    return [...keys].every((key) => local[key] === server[key]);
}

export function canChooseYouTubeThumbnail(
    state: Pick<ComposerState, 'media' | 'placements' | 'placementsByAccount'>,
    account: Pick<Account, 'id' | 'platform'>,
): boolean {
    if (account.platform !== 'youtube') return false;
    const placements =
        state.placementsByAccount[account.id] ?? state.placements;
    const ids = new Set(Object.values(placements).flat());
    const media = state.media.filter((item) => ids.has(item.id));

    return media.length === 1 && media[0].kind === 'video';
}

export function youtubeThumbnailFileError(file: File): string | null {
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
        return 'Choose a JPEG or PNG cover image.';
    }
    if (file.size === 0 || file.size > 8 * 1024 * 1024) {
        return 'Choose a cover image larger than 0 bytes and no larger than 8 MB.';
    }

    return null;
}

export function youtubeCopyErrors(options: YouTubePostOptions | undefined): {
    title?: string;
    description?: string;
} {
    const errors: { title?: string; description?: string } = {};
    if (
        options?.title !== undefined &&
        (typeof options.title !== 'string' ||
            options.title.trim().length === 0 ||
            Array.from(options.title).length > 100 ||
            /[<>]/.test(options.title))
    ) {
        errors.title = 'Use a title of 1–100 characters without < or >.';
    }
    if (
        options?.description !== undefined &&
        (typeof options.description !== 'string' ||
            new TextEncoder().encode(options.description).length > 5000 ||
            /[<>]/.test(options.description))
    ) {
        errors.description =
            'Keep the description within 5,000 bytes, without < or >.';
    }

    return errors;
}

export function youtubeOptionsComplete(
    options: YouTubePostOptions | undefined,
): boolean {
    return (
        !!options &&
        ['private', 'unlisted', 'public'].includes(
            options.privacy_status ?? '',
        ) &&
        ['video', 'short'].includes(options.format_intent ?? '') &&
        /^\d{1,3}$/.test(options.category_id ?? '') &&
        typeof options.made_for_kids === 'boolean' &&
        typeof options.contains_synthetic_media === 'boolean' &&
        typeof options.has_paid_product_placement === 'boolean' &&
        typeof options.notify_subscribers === 'boolean' &&
        (options.thumbnail_media_id == null ||
            (typeof options.thumbnail_media_id === 'string' &&
                options.thumbnail_media_id.length > 0)) &&
        Object.keys(youtubeCopyErrors(options)).length === 0
    );
}
