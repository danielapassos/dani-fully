import type { YouTubePostOptions } from '@/types/compose';

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
        Object.keys(youtubeCopyErrors(options)).length === 0
    );
}
