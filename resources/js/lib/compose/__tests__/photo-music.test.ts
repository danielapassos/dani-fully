import { describe, expect, it } from 'vitest';

import {
    buildPutBody,
    composerReducer,
    contentMatchesServer,
    initialComposerState,
} from '@/lib/compose/composer-state';
import type { PostView } from '@/types/compose';

function post(track?: string): PostView {
    return {
        id: 'post',
        base_text: 'Caption',
        segments: ['Caption'],
        status: 'draft',
        published_at: null,
        updated_at: '2026-10-11T10:00:00Z',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'account', id: 'tiktok' },
        media: [],
        targets: [
            {
                id: 'target',
                connected_account_id: 'tiktok',
                platform: 'tiktok',
                handle: '@creator',
                display_name: 'Creator',
                avatar_url: null,
                sections: ['Caption'],
                content_override: track ? { music: { track } } : null,
                auto_split: true,
                format: 'feed',
                issues: [],
                status: 'pending',
                error_kind: null,
                error_message: null,
                attempts: 0,
                remote_id: null,
            },
        ],
    };
}

describe('photo music autosave', () => {
    it('hydrates and saves a per-target note without changing the caption', () => {
        const saved = post('Song — Artist');
        const state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: saved,
        });
        expect(state.musicByAccount).toEqual({
            tiktok: { track: 'Song — Artist' },
        });
        expect(contentMatchesServer(state, saved)).toBe(true);
        expect(
            buildPutBody(state, ['tiktok']).targets[0].content_override,
        ).toEqual({ music: { track: 'Song — Artist' } });
        expect(buildPutBody(state, ['tiktok']).segments).toEqual(['Caption']);
    });

    it('tracks note edits as content changes and explicitly clears stored music', () => {
        const saved = post('Song — Artist');
        const initial = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: saved,
        });
        const changed = composerReducer(initial, {
            type: 'setPhotoMusic',
            accountId: 'tiktok',
            track: 'Other song — Artist',
        });
        expect(changed.saveState).toBe('dirty');
        expect(contentMatchesServer(changed, saved)).toBe(false);
        const cleared = composerReducer(changed, {
            type: 'setPhotoMusic',
            accountId: 'tiktok',
            track: '',
        });
        expect(
            buildPutBody(cleared, ['tiktok']).targets[0].content_override,
        ).toEqual({ music: null });
        expect(contentMatchesServer(cleared, post())).toBe(true);
    });

    it('does not add music to legacy drafts or another account', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: post(),
        });
        expect(
            buildPutBody(state, ['tiktok']).targets[0].content_override,
        ).toBeNull();
        const changed = composerReducer(state, {
            type: 'setPhotoMusic',
            accountId: 'instagram',
            track: 'Song — Artist',
        });
        expect(
            buildPutBody(changed, ['tiktok']).targets[0].content_override,
        ).toBeNull();
        expect(contentMatchesServer(changed, post())).toBe(true);
    });

    it('normalizes surrounding whitespace only at persistence and comparison boundaries', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: post(),
        });
        const changed = composerReducer(state, {
            type: 'setPhotoMusic',
            accountId: 'tiktok',
            track: ' Song — Artist ',
        });
        expect(changed.musicByAccount.tiktok?.track).toBe(' Song — Artist ');
        expect(
            buildPutBody(changed, ['tiktok']).targets[0].content_override,
        ).toEqual({ music: { track: 'Song — Artist' } });
        expect(contentMatchesServer(changed, post('Song — Artist'))).toBe(true);
    });
});
