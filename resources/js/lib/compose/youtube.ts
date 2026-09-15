import type { YouTubePostOptions } from '@/types/compose';

export const YOUTUBE_DEFAULT_OPTIONS: YouTubePostOptions = {
    category_id: '22',
    notify_subscribers: false,
};

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
        typeof options.notify_subscribers === 'boolean'
    );
}
