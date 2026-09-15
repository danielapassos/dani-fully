import type { VariantProps } from 'class-variance-authority';

import type { badgeVariants } from '@/components/ui/badge';
import type { PostStatus, PostView } from '@/types/compose';

type BadgeVariant = NonNullable<VariantProps<typeof badgeVariants>['variant']>;

/**
 * Single source of truth for how a post status renders: its display label and
 * the semantic Badge variant. Consumed by the posts list, post preview, and
 * calendar chips so status styling stays consistent everywhere.
 */
export const postStatusMeta: Record<
    PostStatus,
    { variant: BadgeVariant; label: string }
> = {
    draft: { variant: 'secondary', label: 'Draft' },
    scheduled: { variant: 'info', label: 'Scheduled' },
    publishing: { variant: 'info', label: 'Publishing' },
    awaiting_action: { variant: 'warning', label: 'Action needed' },
    completed: { variant: 'secondary', label: 'Upload complete' },
    published: { variant: 'success', label: 'Published' },
    partial: { variant: 'warning', label: 'Partially completed' },
    failed: { variant: 'destructive', label: 'Failed' },
    missed: { variant: 'warning', label: 'Missed' },
    deleted: { variant: 'secondary', label: 'Deleted' },
};

/** Place non-public terminal uploads on the calendar without inventing a publish time. */
export function postCalendarTimestamp(
    post: Pick<
        PostView,
        'status' | 'scheduled_at' | 'published_at' | 'updated_at'
    >,
): string | null {
    return (
        post.scheduled_at ??
        post.published_at ??
        (post.status === 'awaiting_action' || post.status === 'completed'
            ? post.updated_at
            : null)
    );
}
