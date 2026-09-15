import { describe, expect, it } from 'vitest';

import {
    postCapabilities,
    postDeletionDescription,
    targetCanRetry,
} from '@/lib/posts/capabilities';
import type { PostView } from '@/types/compose';

function post(partial: Partial<PostView>): PostView {
    return {
        id: 'p',
        base_text: '',
        status: 'draft',
        published_at: null,
        updated_at: '',
        scheduled_at: null,
        destination: { kind: 'all', id: null },
        targets: [],
        media: [],
        ...partial,
    } as PostView;
}

describe('postCapabilities', () => {
    it.each(['awaiting_action', 'completed'] as const)(
        '%s allows deletion and duplication but never retry or another publish',
        (status) => {
            const target = {
                status,
                can_retry: true,
            } as PostView['targets'][number];
            const delivered = post({ status, targets: [target] });

            expect(postCapabilities(delivered)).toEqual({
                canDelete: true,
                canDuplicate: true,
                canEdit: false,
                canSchedule: false,
                canReschedule: false,
                canUnschedule: false,
                canRetry: false,
            });
            expect(targetCanRetry(target)).toBe(false);
            expect(postDeletionDescription(delivered)).toBe(
                'This removes the Shoutrrr record and deletes the connected upload where the platform supports it. Inbox transfers may still need removal in TikTok.',
            );
            expect(
                postDeletionDescription(
                    post({ status: 'partial', targets: [target] }),
                ),
            ).not.toContain('Published copies');
        },
    );

    it('draft: edit/schedule/delete and independent copy', () => {
        const c = postCapabilities(post({ status: 'draft' }));
        expect(c).toMatchObject({
            canEdit: true,
            canSchedule: true,
            canDelete: true,
            canReschedule: false,
            canDuplicate: true,
        });
    });
    it('scheduled: edit/reschedule/unschedule/delete, no duplicate', () => {
        const c = postCapabilities(post({ status: 'scheduled' }));
        expect(c).toMatchObject({
            canReschedule: true,
            canUnschedule: true,
            canDelete: true,
            canSchedule: false,
            canDuplicate: false,
        });
    });
    it('failed with a failed target: delete + retry + duplicate', () => {
        const c = postCapabilities(
            post({
                status: 'failed',
                targets: [
                    {
                        status: 'failed',
                        error_kind: 'validation',
                        can_retry: true,
                    } as PostView['targets'][number],
                ],
            }),
        );
        expect(c).toMatchObject({
            canDelete: true,
            canRetry: true,
            canEdit: false,
            canDuplicate: true,
        });
    });
    it('failed with only an unconfirmed provider outcome: manual review, no retry', () => {
        const c = postCapabilities(
            post({
                status: 'failed',
                targets: [
                    {
                        status: 'failed',
                        error_kind: 'unknown',
                    } as PostView['targets'][number],
                ],
            }),
        );
        expect(c).toMatchObject({
            canDelete: true,
            canRetry: false,
            canDuplicate: true,
        });
    });
    it('a safe failed target remains retryable when another target needs manual review', () => {
        const targets = [
            {
                status: 'failed',
                error_kind: 'unknown',
                can_retry: false,
            },
            {
                status: 'failed',
                error_kind: 'validation',
                can_retry: true,
            },
        ] as PostView['targets'];

        expect(
            postCapabilities(post({ status: 'partial', targets })).canRetry,
        ).toBe(true);
        expect(targetCanRetry(targets[0])).toBe(false);
        expect(targetCanRetry(targets[1])).toBe(true);
    });
    it('a partial post can recover a safely retryable skipped target', () => {
        const skipped = {
            status: 'skipped',
            error_kind: 'validation',
            can_retry: true,
        } as PostView['targets'][number];

        expect(
            postCapabilities(post({ status: 'partial', targets: [skipped] }))
                .canRetry,
        ).toBe(true);
        expect(targetCanRetry(skipped)).toBe(true);
    });
    it('published: delete + duplicate', () => {
        const c = postCapabilities(post({ status: 'published' }));
        expect(c).toMatchObject({
            canDelete: true,
            canDuplicate: true,
            canRetry: false,
        });
    });
    it('missed: reschedule + delete + duplicate', () => {
        const c = postCapabilities(post({ status: 'missed' }));
        expect(c).toMatchObject({
            canReschedule: true,
            canDelete: true,
            canDuplicate: true,
        });
    });
    it('publishing/deleted: nothing', () => {
        expect(postCapabilities(post({ status: 'publishing' })).canDelete).toBe(
            false,
        );
        expect(postCapabilities(post({ status: 'deleted' })).canDelete).toBe(
            false,
        );
    });
    it('tolerates a partial payload with no targets (canRetry false, no throw)', () => {
        const partial = { status: 'failed' } as unknown as PostView;
        expect(() => postCapabilities(partial)).not.toThrow();
        expect(postCapabilities(partial).canRetry).toBe(false);
    });
    it('fails closed when a partial failed target omits both retry fields', () => {
        const target = { status: 'failed' } as PostView['targets'][number];
        expect(targetCanRetry(target)).toBe(false);
    });
});
