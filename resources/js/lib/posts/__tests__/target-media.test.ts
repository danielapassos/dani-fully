import { describe, expect, it } from 'vitest';

import {
    resolveTargetMedia,
    resolveTargetMediaBySection,
} from '@/lib/posts/target-media';
import type { MediaView, TargetView } from '@/types/compose';

function media(id: string): MediaView {
    return {
        id,
        url: `https://example.test/${id}.jpg`,
        mime: 'image/jpeg',
        kind: 'image',
        alt_text: null,
        duration_seconds: null,
        position: 0,
        edit_settings: null,
        source_url: null,
        edit_url: `https://example.test/${id}/edit`,
        source_edit_url: null,
    };
}

function target(overrides: Partial<TargetView> = {}): TargetView {
    return {
        id: 'target-1',
        connected_account_id: 'account-1',
        platform: 'x',
        handle: '@account',
        display_name: null,
        avatar_url: null,
        sections: ['hello'],
        content_override: null,
        auto_split: true,
        format: 'feed',
        issues: [],
        status: 'published',
        error_kind: null,
        error_message: null,
        attempts: 1,
        remote_id: 'remote-1',
        ...overrides,
    };
}

describe('resolveTargetMedia', () => {
    it('keeps an explicit empty placement set empty', () => {
        expect(
            resolveTargetMedia(
                target({ placements_explicit: true, placements: [] }),
                [media('m1')],
            ),
        ).toEqual([]);
    });

    it('uses placement rows even when the marker predates a migration backfill', () => {
        const all = [media('m1'), media('m2')];
        expect(
            resolveTargetMedia(
                target({
                    placements_explicit: false,
                    placements: [
                        {
                            media_id: 'm2',
                            segment_ref: '__head__',
                            position: 0,
                        },
                    ],
                }),
                all,
            ).map(({ id }) => id),
        ).toEqual(['m2']);
    });

    it('honors an explicitly empty legacy override before falling back to all media', () => {
        const all = [media('m1')];
        expect(
            resolveTargetMedia(
                target({ content_override: { media_ids: [] } }),
                all,
            ),
        ).toEqual([]);
        expect(resolveTargetMedia(target(), all)).toEqual(all);
    });

    it('renders placed media under the thread section where it actually landed', () => {
        const all = [media('m1'), media('m2')];
        const sections = resolveTargetMediaBySection(
            target({
                sections: ['first', 'second'],
                segment_breaks: ['b1'],
                section_sources: [0, 1],
                placements_explicit: true,
                placements: [
                    { media_id: 'm1', segment_ref: '__head__', position: 0 },
                    { media_id: 'm2', segment_ref: 'b1', position: 0 },
                ],
            }),
            all,
        );

        expect(sections.map((items) => items.map(({ id }) => id))).toEqual([
            ['m1'],
            ['m2'],
        ]);
    });
});
