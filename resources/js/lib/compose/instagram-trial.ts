import type { InstagramPostOptions } from '@/types/compose';

export function normalizeInstagramPostOptions(
    options: InstagramPostOptions,
): InstagramPostOptions {
    return {
        cover_media_id: options.cover_media_id ?? null,
        ...(options.trial_params !== undefined
            ? { trial_params: options.trial_params }
            : {}),
    };
}

export function hasInstagramPostOptions(
    options: InstagramPostOptions,
): boolean {
    return options.cover_media_id != null || options.trial_params != null;
}

export function instagramPostOptionsEqual(
    left: InstagramPostOptions,
    right: InstagramPostOptions,
): boolean {
    return (
        (left.cover_media_id ?? null) === (right.cover_media_id ?? null) &&
        (left.trial_params?.graduation_strategy ?? null) ===
            (right.trial_params?.graduation_strategy ?? null)
    );
}
