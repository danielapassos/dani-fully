import { describe, expect, it } from 'vitest';

import type {
    InstagramPostOptions,
    MediaView,
    PostView,
} from '@/types/compose';

import {
    buildPutBody,
    composerHasContent,
    composerReducer,
    contentMatchesServer,
    initialComposerState,
} from '../composer-state';
import {
    canChooseInstagramCover,
    instagramCoverFileError,
} from '../instagram-cover';

const account = { id: 'instagram-account', platform: 'instagram' as const };
const video: MediaView = {
    id: 'video',
    url: 'https://media.test/video.mp4',
    mime: 'video/mp4',
    kind: 'video',
    alt_text: null,
    duration_seconds: 15,
    position: 0,
    edit_settings: null,
    source_url: null,
    edit_url: '/media/video',
    source_edit_url: null,
};
const image: MediaView = {
    ...video,
    id: 'image',
    kind: 'image',
    mime: 'image/jpeg',
};

function postWithCover(options?: InstagramPostOptions): PostView {
    return {
        id: 'post',
        base_text: 'Caption',
        segments: ['Caption'],
        status: 'draft',
        published_at: null,
        updated_at: '2026-09-15T10:00:00Z',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'account', id: account.id },
        media: [video],
        targets: [
            {
                id: 'target',
                connected_account_id: account.id,
                platform: 'instagram',
                handle: '@creator',
                display_name: null,
                avatar_url: null,
                sections: ['Caption'],
                content_override: options ? { instagram: options } : null,
                auto_split: false,
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

describe('Instagram cover state', () => {
    it('hydrates and serializes trial settings independently for each destination', () => {
        const trial: InstagramPostOptions = {
            cover_media_id: 'cover',
            trial_params: { graduation_strategy: 'MANUAL' },
        };
        const post = postWithCover(trial);
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        expect(state.instagramByAccount[account.id]).toEqual(trial);
        expect(contentMatchesServer(state, post)).toBe(true);
        state = composerReducer(state, {
            type: 'setInstagramTrial',
            accountId: 'second-instagram',
            strategy: 'SS_PERFORMANCE',
        });
        expect(buildPutBody(state, [account.id]).targets).toHaveLength(1);
        const targets = buildPutBody(state, [
            account.id,
            'second-instagram',
        ]).targets;
        expect(targets[0].content_override).toEqual({ instagram: trial });
        expect(targets[1].content_override?.instagram).toEqual({
            cover_media_id: null,
            trial_params: { graduation_strategy: 'SS_PERFORMANCE' },
        });
        expect(contentMatchesServer(state, post)).toBe(true);
        expect(state.media).toEqual([video]);
    });

    it('preserves trial settings when changing or removing a cover and preserves covers when toggling trial', () => {
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: postWithCover({ cover_media_id: 'original-cover' }),
        });
        state = composerReducer(state, {
            type: 'setInstagramTrial',
            accountId: account.id,
            strategy: 'MANUAL',
        });
        expect(state.instagramByAccount[account.id].cover_media_id).toBe(
            'original-cover',
        );
        for (const mediaId of ['new-cover', null]) {
            state = composerReducer(state, {
                type: 'setInstagramCover',
                accountId: account.id,
                mediaId,
            });
            expect(state.instagramByAccount[account.id]).toEqual({
                cover_media_id: mediaId,
                trial_params: { graduation_strategy: 'MANUAL' },
            });
        }
        state = composerReducer(state, {
            type: 'setInstagramCover',
            accountId: account.id,
            mediaId: 'keep-cover',
        });
        state = composerReducer(state, {
            type: 'setInstagramTrial',
            accountId: account.id,
            strategy: null,
        });
        expect(
            buildPutBody(state, [account.id]).targets[0].content_override
                ?.instagram,
        ).toEqual({
            cover_media_id: 'keep-cover',
            trial_params: null,
        });
        expect(
            contentMatchesServer(
                state,
                postWithCover({ cover_media_id: 'keep-cover' }),
            ),
        ).toBe(true);
    });

    it('treats a changed trial strategy as a real edit conflict and preserves it after incompatible media changes', () => {
        const post = postWithCover({
            cover_media_id: null,
            trial_params: { graduation_strategy: 'MANUAL' },
        });
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        state = composerReducer(state, {
            type: 'setInstagramTrial',
            accountId: account.id,
            strategy: 'SS_PERFORMANCE',
        });
        expect(contentMatchesServer(state, post)).toBe(false);
        state = composerReducer(state, { type: 'saveFailedStale', post });
        expect(state.saveState).toBe('conflict');
        state = composerReducer(state, { type: 'resolveConflictKeepMine' });
        state = composerReducer(state, {
            type: 'setFormat',
            accountId: account.id,
            format: 'story',
        });
        state = composerReducer(state, {
            type: 'removeMedia',
            mediaId: video.id,
        });
        expect(state.instagramByAccount[account.id].trial_params).toEqual({
            graduation_strategy: 'SS_PERFORMANCE',
        });
        expect(canChooseInstagramCover(state, account)).toBe(false);
    });

    it('does not choose a cover or treat a cover reference as post content', () => {
        const initial = initialComposerState();
        expect(initial.instagramByAccount).toEqual({});
        const state = composerReducer(initial, {
            type: 'setInstagramCover',
            accountId: account.id,
            mediaId: 'cover',
        });
        expect(state.saveState).toBe('dirty');
        expect(state.media).toBe(initial.media);
        expect(state.placements).toBe(initial.placements);
        expect(state.placementsByAccount).toBe(initial.placementsByAccount);
        expect(composerHasContent(state)).toBe(false);
        expect(buildPutBody(state, [account.id]).media_ids).toEqual([]);
    });

    it('hydrates an independent cover reference and preserves text overrides when saving', () => {
        const post = postWithCover({ cover_media_id: 'cover' });
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        expect(state.instagramByAccount[account.id]).toEqual({
            cover_media_id: 'cover',
        });
        expect(state.media.map(({ id }) => id)).toEqual(['video']);
        expect(contentMatchesServer(state, post)).toBe(true);
        state = composerReducer(state, {
            type: 'setOverrideSegments',
            accountId: account.id,
            segments: ['Custom caption'],
        });
        state = composerReducer(state, {
            type: 'setInstagramCover',
            accountId: 'second-instagram',
            mediaId: 'second-cover',
        });
        const body = buildPutBody(state, [account.id, 'second-instagram']);
        expect(body.targets[0].content_override).toEqual({
            segments: ['Custom caption'],
            media_ids: ['video'],
            instagram: { cover_media_id: 'cover' },
        });
        expect(body.targets[1].content_override).toEqual({
            instagram: { cover_media_id: 'second-cover' },
        });
        expect(body.media_ids).toEqual(['video']);
        expect(body.placements.map(({ media_id }) => media_id)).toEqual([
            'video',
        ]);
    });

    it('clears explicitly and reconciles null with a missing server cover', () => {
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: postWithCover({ cover_media_id: 'cover' }),
        });
        state = composerReducer(state, {
            type: 'setInstagramCover',
            accountId: account.id,
            mediaId: null,
        });
        expect(
            buildPutBody(state, [account.id]).targets[0].content_override,
        ).toEqual({ instagram: { cover_media_id: null } });
        expect(contentMatchesServer(state, postWithCover())).toBe(true);
        expect(
            contentMatchesServer(
                state,
                postWithCover({ cover_media_id: null }),
            ),
        ).toBe(true);
        expect(
            contentMatchesServer(
                state,
                postWithCover({ cover_media_id: 'cover' }),
            ),
        ).toBe(false);
    });

    it('normalizes an empty legacy Instagram object to no cover', () => {
        const post = postWithCover({} as InstagramPostOptions);
        const state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        expect(state.instagramByAccount[account.id]).toEqual({
            cover_media_id: null,
        });
        expect(contentMatchesServer(state, post)).toBe(true);
        expect(contentMatchesServer(state, postWithCover())).toBe(true);
        expect(
            contentMatchesServer(
                state,
                postWithCover({ cover_media_id: null }),
            ),
        ).toBe(true);
    });

    it('ignores remembered covers for deselected accounts and resets on another draft', () => {
        const post = postWithCover({ cover_media_id: 'cover' });
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        state = composerReducer(state, {
            type: 'setInstagramCover',
            accountId: 'deselected',
            mediaId: 'other-cover',
        });
        expect(contentMatchesServer(state, post)).toBe(true);
        state = composerReducer(state, {
            type: 'setDestination',
            destination: { kind: 'accounts', ids: [account.id, 'deselected'] },
        });
        expect(contentMatchesServer(state, post)).toBe(false);
        state = composerReducer(state, {
            type: 'syncServerPost',
            post: { ...postWithCover(), id: 'new-post' },
        });
        expect(state.instagramByAccount).toEqual({});
    });
});

describe('Instagram cover eligibility', () => {
    const base = {
        ...initialComposerState(),
        media: [video, image],
        placements: { __head__: ['video'] },
    };
    it('allows a single effective video in feed or Reels without using every workspace image', () => {
        expect(canChooseInstagramCover(base, account)).toBe(true);
        expect(
            canChooseInstagramCover(
                { ...base, formatByAccount: { [account.id]: 'reels' } },
                account,
            ),
        ).toBe(true);
        expect(
            canChooseInstagramCover(
                {
                    ...base,
                    placements: { __head__: ['video'], second: ['video'] },
                },
                account,
            ),
        ).toBe(true);
        expect(
            canChooseInstagramCover(
                {
                    ...base,
                    placements: { __head__: ['video', 'removed-media'] },
                },
                account,
            ),
        ).toBe(true);
    });

    it('rejects Stories, mixed media, images, missing media and explicit empty account placements', () => {
        expect(
            canChooseInstagramCover(
                { ...base, formatByAccount: { [account.id]: 'story' } },
                account,
            ),
        ).toBe(false);
        expect(
            canChooseInstagramCover(
                { ...base, placements: { __head__: ['video', 'image'] } },
                account,
            ),
        ).toBe(false);
        expect(
            canChooseInstagramCover(
                { ...base, placements: { __head__: ['image'] } },
                account,
            ),
        ).toBe(false);
        expect(
            canChooseInstagramCover(
                { ...base, placements: { __head__: ['missing'] } },
                account,
            ),
        ).toBe(false);
        expect(
            canChooseInstagramCover(
                { ...base, placementsByAccount: { [account.id]: {} } },
                account,
            ),
        ).toBe(false);
        expect(
            canChooseInstagramCover(base, { ...account, platform: 'tiktok' }),
        ).toBe(false);
    });

    it('accepts only nonempty JPEG or PNG files up to 8 MB', () => {
        expect(
            instagramCoverFileError(
                new File(['bytes'], 'cover.jpg', { type: 'image/jpeg' }),
            ),
        ).toBeNull();
        expect(
            instagramCoverFileError(
                new File(['bytes'], 'cover.png', { type: 'image/png' }),
            ),
        ).toBeNull();
        expect(
            instagramCoverFileError(
                new File(['bytes'], 'cover.gif', { type: 'image/gif' }),
            ),
        ).toContain('JPEG or PNG');
        expect(
            instagramCoverFileError(
                new File([], 'empty.jpg', { type: 'image/jpeg' }),
            ),
        ).toContain('larger than 0');
        const large = new File(['bytes'], 'large.jpg', { type: 'image/jpeg' });
        Object.defineProperty(large, 'size', { value: 8 * 1024 * 1024 + 1 });
        expect(instagramCoverFileError(large)).toContain('8 MB');
    });
});
