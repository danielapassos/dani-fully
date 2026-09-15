import { describe, expect, it } from 'vitest';

import { dayjs } from '@/lib/datetime/dayjs';

import { postLiveStatus } from '../live-status';

describe('postLiveStatus', () => {
    it.each([
        ['awaiting_action', 'Action needed on the connected platform'],
        ['completed', 'Upload complete'],
        ['partial', 'Partially completed'],
    ] as const)(
        'describes %s without claiming publication',
        (status, expected) => {
            expect(
                postLiveStatus({
                    status,
                    scheduled_at: null,
                    published_at: null,
                }),
            ).toBe(expected);
        },
    );

    it('counts down to a scheduled post going live', () => {
        const scheduled_at = dayjs().add(3, 'hour').toISOString();
        const label = postLiveStatus({
            status: 'scheduled',
            scheduled_at,
            published_at: null,
        });
        expect(label).toMatch(/^Going live in /);
    });

    it('reports how long ago a post was published', () => {
        const published_at = dayjs().subtract(2, 'hour').toISOString();
        const label = postLiveStatus({
            status: 'published',
            scheduled_at: null,
            published_at,
        });
        expect(label).toMatch(/^Published .* ago$/);
    });

    it('surfaces when a scheduled run was missed', () => {
        const scheduled_at = dayjs().subtract(1, 'hour').toISOString();
        const label = postLiveStatus({
            status: 'missed',
            scheduled_at,
            published_at: null,
        });
        expect(label).toMatch(/^Missed · was due .* ago$/);
    });

    it('has no live line for a draft', () => {
        expect(
            postLiveStatus({
                status: 'draft',
                scheduled_at: null,
                published_at: null,
            }),
        ).toBeNull();
    });

    it('falls back to a plain label when a scheduled time is missing', () => {
        expect(
            postLiveStatus({
                status: 'scheduled',
                scheduled_at: null,
                published_at: null,
            }),
        ).toBe('Scheduled');
    });
});
