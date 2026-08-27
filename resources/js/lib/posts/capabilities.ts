import type { PostView } from '@/types/compose';

type RetryTarget = Pick<PostView['targets'][number], 'status'> &
    Partial<Pick<PostView['targets'][number], 'can_retry' | 'error_kind'>>;

/**
 * Prefer the server-authoritative retry gate. The error-kind fallback keeps a
 * stale/partial Inertia payload fail-closed for an unconfirmed provider result.
 */
export function targetCanRetry(target: RetryTarget): boolean {
    if (target.status !== 'failed' && target.status !== 'skipped') {
        return false;
    }

    return (
        target.can_retry ??
        (target.error_kind !== undefined && target.error_kind !== 'unknown')
    );
}

export interface PostCapabilities {
    canEdit: boolean;
    canSchedule: boolean;
    canReschedule: boolean;
    canUnschedule: boolean;
    canDelete: boolean;
    canRetry: boolean;
    canDuplicate: boolean;
}

const NONE: PostCapabilities = {
    canEdit: false,
    canSchedule: false,
    canReschedule: false,
    canUnschedule: false,
    canDelete: false,
    canRetry: false,
    canDuplicate: false,
};

export function postCapabilities(post: PostView): PostCapabilities {
    // Tolerate partial Inertia payloads that omit targets (e.g. lighter feed rows).
    const hasRetryableTarget = (post.targets ?? []).some(targetCanRetry);
    switch (post.status) {
        case 'draft':
            return {
                ...NONE,
                canEdit: true,
                canSchedule: true,
                canDelete: true,
            };
        case 'scheduled':
            // Not a draft → content is read-only; only the schedule itself can
            // still be changed (reschedule/unschedule) or the post discarded.
            return {
                ...NONE,
                canReschedule: true,
                canUnschedule: true,
                canDelete: true,
            };
        case 'missed':
            // A post the scheduler skipped as too stale: let the user reschedule
            // it back into the pipeline (→ scheduled) or discard it.
            return {
                ...NONE,
                canReschedule: true,
                canDelete: true,
                canDuplicate: true,
            };
        case 'published':
        case 'partial':
        case 'failed':
            return {
                ...NONE,
                canDelete: true,
                canRetry: hasRetryableTarget,
                canDuplicate: true,
            };
        default:
            return NONE;
    }
}
