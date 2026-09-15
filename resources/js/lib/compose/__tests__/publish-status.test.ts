import { describe, expect, it } from 'vitest';

import type { PostView, TargetStatus, TargetView } from '@/types/compose';

import {
    anyTargetActive,
    applyOptimisticSubmit,
    failedTargets,
    isPostTerminal,
    shouldPollPostStatus,
    OPTIMISTIC_PUBLISH,
    OPTIMISTIC_SCHEDULE,
    targetStatusMessage,
    targetStatusMeta,
} from '../publish-status';

function target(id: string, status: TargetStatus): TargetView {
    return {
        id,
        connected_account_id: `acc-${id}`,
        platform: 'x',
        handle: '@h',
        display_name: null,
        avatar_url: null,
        sections: ['x'],
        content_override: null,
        auto_split: true,
        format: 'feed',
        issues: [],
        status,
        error_kind: null,
        error_message: null,
        attempts: 0,
        remote_id: null,
    };
}

function post(targets: TargetView[]): PostView {
    return {
        id: 'p1',
        base_text: 'hi',
        segments: ['hi'],
        status: 'publishing',
        published_at: null,
        updated_at: '2026-06-12T10:00:00+00:00',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'all', id: null },
        targets,
        media: [],
    };
}

describe('anyTargetActive', () => {
    it('is true while a target is pending or publishing', () => {
        expect(anyTargetActive([target('a', 'pending')])).toBe(true);
        expect(anyTargetActive([target('a', 'publishing')])).toBe(true);
        expect(anyTargetActive([target('a', 'deleting')])).toBe(true);
    });

    it('is false when all targets are terminal', () => {
        expect(
            anyTargetActive([target('a', 'published'), target('b', 'failed')]),
        ).toBe(false);
        expect(anyTargetActive([])).toBe(false);
    });

    it('treats inbox handoffs and non-public uploads as terminal', () => {
        const targets = [
            target('inbox', 'awaiting_action'),
            target('private', 'completed'),
        ];

        expect(anyTargetActive(targets)).toBe(false);
        expect(isPostTerminal(post(targets))).toBe(true);
        expect(shouldPollPostStatus(post(targets))).toBe(false);
        expect(failedTargets(targets)).toEqual([]);
    });
});

describe('isPostTerminal', () => {
    it('mirrors anyTargetActive inverted', () => {
        expect(isPostTerminal(post([target('a', 'publishing')]))).toBe(false);
        expect(
            isPostTerminal(
                post([target('a', 'published'), target('b', 'failed')]),
            ),
        ).toBe(true);
    });
});

describe('shouldPollPostStatus', () => {
    it('does not poll draft posts whose targets are only editable draft placeholders', () => {
        const draft = post([target('a', 'pending')]);
        draft.status = 'draft';

        expect(shouldPollPostStatus(draft)).toBe(false);
    });

    it('polls posts that are actively publishing', () => {
        expect(shouldPollPostStatus(post([target('a', 'publishing')]))).toBe(
            true,
        );
    });

    it('continues polling an active destination alongside an inbox handoff', () => {
        expect(
            shouldPollPostStatus(
                post([
                    target('inbox', 'awaiting_action'),
                    target('active', 'publishing'),
                ]),
            ),
        ).toBe(true);
    });
});

describe('failedTargets', () => {
    it('returns only failed targets', () => {
        const ts = [
            target('a', 'published'),
            target('b', 'failed'),
            target('c', 'failed'),
        ];
        expect(failedTargets(ts).map((t) => t.id)).toEqual(['b', 'c']);
    });
});

describe('applyOptimisticSubmit', () => {
    it('flips a draft post and pending targets to publishing on Publish now', () => {
        const before = post([target('a', 'pending'), target('b', 'pending')]);
        before.status = 'draft';

        const after = applyOptimisticSubmit(before, OPTIMISTIC_PUBLISH);

        expect(after.status).toBe('publishing');
        expect(after.targets.map((t) => t.status)).toEqual([
            'publishing',
            'publishing',
        ]);
    });

    it('flips to scheduled/pending on queue or schedule', () => {
        const before = post([target('a', 'failed')]);
        before.status = 'draft';

        const after = applyOptimisticSubmit(before, OPTIMISTIC_SCHEDULE);

        expect(after.status).toBe('scheduled');
        expect(after.targets[0].status).toBe('pending');
    });

    it('clears a prior error so a fresh attempt loses the stale failure', () => {
        const before = post([target('a', 'failed')]);
        before.targets[0].error_kind = 'rate_limited';
        before.targets[0].error_message = 'Too many requests';

        const after = applyOptimisticSubmit(before, OPTIMISTIC_PUBLISH);

        expect(after.targets[0].error_kind).toBeNull();
        expect(after.targets[0].error_message).toBeNull();
    });

    it('leaves terminal targets (published/deleting/deleted) untouched', () => {
        const before = post([
            target('a', 'published'),
            target('b', 'deleting'),
            target('c', 'deleted'),
            target('d', 'pending'),
        ]);

        const after = applyOptimisticSubmit(before, OPTIMISTIC_PUBLISH);

        expect(after.targets.map((t) => t.status)).toEqual([
            'published',
            'deleting',
            'deleted',
            'publishing',
        ]);
    });

    it('does not mutate the input (revert is just restoring the prior view)', () => {
        const before = post([target('a', 'pending')]);
        const snapshot = structuredClone(before);

        applyOptimisticSubmit(before, OPTIMISTIC_PUBLISH);

        expect(before).toEqual(snapshot);
    });

    it.each(['awaiting_action', 'completed'] as const)(
        'never optimistically overwrites a %s post or target',
        (status) => {
            const delivered = {
                ...target('delivered', status),
                status_message: 'Stored provider outcome',
                remote_id: 'existing-reference',
            };
            const mixed = post([delivered, target('pending', 'pending')]);
            const after = applyOptimisticSubmit(mixed, OPTIMISTIC_PUBLISH);

            expect(after.targets[0]).toBe(delivered);
            expect(after.targets[1].status).toBe('publishing');

            const terminal = { ...post([delivered]), status };
            expect(applyOptimisticSubmit(terminal, OPTIMISTIC_PUBLISH)).toBe(
                terminal,
            );
            expect(applyOptimisticSubmit(terminal, OPTIMISTIC_SCHEDULE)).toBe(
                terminal,
            );
        },
    );
});

describe('targetStatusMeta', () => {
    it('maps publishing to a spinning active tone', () => {
        const meta = targetStatusMeta('publishing');
        expect(meta.spinning).toBe(true);
        expect(meta.tone).toBe('active');
    });

    it('maps published to a non-spinning success tone', () => {
        expect(targetStatusMeta('published')).toMatchObject({
            tone: 'success',
            spinning: false,
        });
    });

    it('distinguishes an inbox handoff from a completed upload and public publication', () => {
        expect(targetStatusMeta('awaiting_action', 'tiktok')).toEqual({
            label: 'In TikTok inbox',
            tone: 'warning',
            spinning: false,
        });
        expect(targetStatusMeta('awaiting_action').label).toBe('Action needed');
        expect(targetStatusMeta('completed')).toEqual({
            label: 'Upload complete',
            tone: 'muted',
            spinning: false,
        });
        expect(
            targetStatusMessage({
                platform: 'tiktok',
                status: 'awaiting_action',
            }),
        ).toBe('Finish the post in TikTok. It is not live yet.');
        expect(
            targetStatusMessage({
                platform: 'youtube',
                status: 'completed',
                status_message: 'Uploaded privately to YouTube.',
            }),
        ).toBe('Uploaded privately to YouTube.');
        expect(
            targetStatusMessage({ platform: 'youtube', status: 'completed' }),
        ).toBe('The upload is complete. Public publishing is not confirmed.');
    });
});
