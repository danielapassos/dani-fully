import { dayjs } from '@/lib/datetime/dayjs';
import type { PostView } from '@/types/compose';

/**
 * A short, human-relative status line for a post's detail header — chiefly the
 * "going live in …" countdown for a scheduled post. Returns null for statuses
 * with no time worth surfacing (drafts, deleted), where the editor itself is
 * the relevant context.
 */
export function postLiveStatus(
    post: Pick<PostView, 'status' | 'scheduled_at' | 'published_at'>,
): string | null {
    switch (post.status) {
        case 'scheduled':
            return post.scheduled_at
                ? `Going live ${dayjs(post.scheduled_at).fromNow()}`
                : 'Scheduled';
        case 'publishing':
            return 'Publishing now…';
        case 'awaiting_action':
            return 'Action needed on the connected platform';
        case 'completed':
            return 'Upload complete';
        case 'published':
            return post.published_at
                ? `Published ${dayjs(post.published_at).fromNow()}`
                : 'Published';
        case 'partial':
            return 'Partially completed';
        case 'failed':
            return 'Failed to publish';
        case 'missed':
            return post.scheduled_at
                ? `Missed · was due ${dayjs(post.scheduled_at).fromNow()}`
                : 'Missed';
        default:
            return null;
    }
}
