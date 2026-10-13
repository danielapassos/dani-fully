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

it('round trips the full music plan and detects non-title edits', () => {
    const saved = post();
    const plan = {
        track: 'Song',
        mode: 'native' as const,
        required: true,
        artist: 'Artist',
        version: 'Official remix',
        native_audio_id: 'audio-42',
        source_url: 'https://www.instagram.com/reels/audio/42/',
        start_seconds: 12.5,
        duration_seconds: 15,
        music_volume: 0,
        original_volume: 100,
        sync_notes: 'Keep narration clear',
        availability_checked_at: '2026-10-12T12:00:00Z',
        availability_note: 'Checked in this account, US, Reel',
    };
    saved.targets[0].content_override = { music: plan };
    const state = composerReducer(initialComposerState(), {
        type: 'hydrate',
        post: saved,
    });
    expect(
        buildPutBody(state, ['tiktok']).targets[0].content_override?.music,
    ).toEqual(plan);
    expect(contentMatchesServer(state, saved)).toBe(true);
    const changed = composerReducer(state, {
        type: 'setMusicOptions',
        accountId: 'tiktok',
        options: { ...plan, version: 'Radio edit' },
    });
    expect(contentMatchesServer(changed, saved)).toBe(false);
    expect(
        buildPutBody(changed, ['tiktok']).targets[0].content_override?.music,
    ).toEqual({ ...plan, version: 'Radio edit' });
});

it('retains required music before a track is chosen and does not copy it to other accounts', () => {
    const state = composerReducer(initialComposerState(), {
        type: 'hydrate',
        post: post(),
    });
    const changed = composerReducer(state, {
        type: 'setMusicOptions',
        accountId: 'tiktok',
        options: { mode: 'native', track: '', required: true },
    });
    expect(
        buildPutBody(changed, ['tiktok']).targets[0].content_override?.music,
    ).toEqual({ mode: 'native', track: '', required: true });
    expect(contentMatchesServer(changed, post())).toBe(false);
    expect(
        buildPutBody(changed, ['instagram']).targets[0].content_override,
    ).toBeNull();
});

it.each(['none', 'existing_audio'] as const)(
    'persists the %s decision instead of treating it as an unreviewed draft',
    (mode) => {
        const saved = post();
        const state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: saved,
        });
        const changed = composerReducer(state, {
            type: 'setMusicOptions',
            accountId: 'tiktok',
            options: { mode, track: '', required: false },
        });
        expect(contentMatchesServer(changed, saved)).toBe(false);
        expect(
            buildPutBody(changed, ['tiktok']).targets[0].content_override?.music
                ?.mode,
        ).toBe(mode);
    },
);
