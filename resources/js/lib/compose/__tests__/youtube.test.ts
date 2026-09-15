import { describe, expect, it } from 'vitest';

import type {
    MediaView,
    PostView,
    TargetView,
    YouTubePostOptions,
} from '@/types/compose';

import {
    buildPutBody,
    composerReducer,
    contentMatchesServer,
    initialComposerState,
} from '../composer-state';
import { TIKTOK_DEFAULT_OPTIONS } from '../tiktok';
import {
    YOUTUBE_DEFAULT_OPTIONS,
    canChooseYouTubeThumbnail,
    youtubeCopyErrors,
    youtubeOptionsComplete,
    youtubePostOptionsEqual,
    youtubeThumbnailFileError,
} from '../youtube';

const selectedOptions: YouTubePostOptions = {
    privacy_status: 'public',
    format_intent: 'short',
    category_id: '19',
    made_for_kids: false,
    contains_synthetic_media: true,
    has_paid_product_placement: false,
    notify_subscribers: true,
};

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

function postWithOptions(options: YouTubePostOptions): PostView {
    const target: TargetView = {
        id: 'target',
        connected_account_id: 'youtube-account',
        platform: 'youtube',
        handle: '@creator',
        display_name: null,
        avatar_url: null,
        sections: ['Inherited caption'],
        content_override: { youtube: options },
        auto_split: false,
        format: 'feed',
        issues: [],
        status: 'pending',
        error_kind: null,
        error_message: null,
        attempts: 0,
        remote_id: null,
    };
    return {
        id: 'post',
        base_text: 'Inherited caption',
        segments: ['Inherited caption'],
        status: 'draft',
        published_at: null,
        updated_at: '2026-09-15T10:00:00Z',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'account', id: 'youtube-account' },
        targets: [target],
        media: [],
    };
}

describe('YouTube per-post settings', () => {
    it('merges a completed cover upload into the latest settings without attaching another post image', () => {
        const post = postWithOptions({
            ...selectedOptions,
            title: 'Original title',
        });
        post.media = [video];
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        state = composerReducer(state, {
            type: 'setYouTubeOptions',
            accountId: 'youtube-account',
            options: {
                ...selectedOptions,
                title: 'Edited while uploading',
                privacy_status: 'private',
            },
        });
        const beforeCover = state;
        state = composerReducer(state, {
            type: 'setYouTubeThumbnail',
            accountId: 'youtube-account',
            mediaId: 'cover-id',
        });
        expect(state.youtubeByAccount['youtube-account']).toEqual({
            ...selectedOptions,
            title: 'Edited while uploading',
            privacy_status: 'private',
            thumbnail_media_id: 'cover-id',
        });
        expect(state.media).toBe(beforeCover.media);
        expect(state.placements).toBe(beforeCover.placements);
        expect(state.placementsByAccount).toBe(beforeCover.placementsByAccount);
        expect(state.saveState).toBe('dirty');
        const body = buildPutBody(state, ['youtube-account']);
        expect(
            body.targets[0].content_override?.youtube?.thumbnail_media_id,
        ).toBe('cover-id');
        expect(body.media_ids).toEqual(['video']);
        expect(body.placements.map(({ media_id }) => media_id)).toEqual([
            'video',
        ]);
    });

    it('hydrates a cover and sends explicit null on removal while comparing it as no cover', () => {
        const post = postWithOptions({
            ...selectedOptions,
            thumbnail_media_id: 'cover-id',
        });
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        expect(
            state.youtubeByAccount['youtube-account'].thumbnail_media_id,
        ).toBe('cover-id');
        expect(contentMatchesServer(state, post)).toBe(true);
        state = composerReducer(state, {
            type: 'setYouTubeThumbnail',
            accountId: 'youtube-account',
            mediaId: null,
        });
        expect(
            buildPutBody(state, ['youtube-account']).targets[0].content_override
                ?.youtube,
        ).toEqual({ ...selectedOptions, thumbnail_media_id: null });
        expect(
            contentMatchesServer(state, postWithOptions(selectedOptions)),
        ).toBe(true);
        expect(contentMatchesServer(state, post)).toBe(false);
    });

    it('preserves a completed cover when a settings edit omits its key and allows explicit removal', () => {
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: postWithOptions(selectedOptions),
        });
        state = composerReducer(state, {
            type: 'setYouTubeThumbnail',
            accountId: 'youtube-account',
            mediaId: 'cover-id',
        });
        state = composerReducer(state, {
            type: 'setYouTubeOptions',
            accountId: 'youtube-account',
            options: { ...selectedOptions, title: 'Updated title' },
        });
        expect(state.youtubeByAccount['youtube-account']).toEqual({
            ...selectedOptions,
            title: 'Updated title',
            thumbnail_media_id: 'cover-id',
        });
        state = composerReducer(state, {
            type: 'setYouTubeOptions',
            accountId: 'youtube-account',
            options: { ...selectedOptions, thumbnail_media_id: null },
        });
        expect(
            state.youtubeByAccount['youtube-account'].thumbnail_media_id,
        ).toBeNull();
    });

    it('compares cover omission and null independently from captions, declarations and property order', () => {
        expect(
            youtubePostOptionsEqual(selectedOptions, {
                thumbnail_media_id: null,
                ...selectedOptions,
            }),
        ).toBe(true);
        expect(
            youtubePostOptionsEqual(
                {
                    ...selectedOptions,
                    title: 'Title',
                    thumbnail_media_id: 'one',
                },
                {
                    thumbnail_media_id: 'one',
                    title: 'Title',
                    ...selectedOptions,
                },
            ),
        ).toBe(true);
        expect(
            youtubePostOptionsEqual(
                { ...selectedOptions, thumbnail_media_id: 'one' },
                { ...selectedOptions, thumbnail_media_id: 'two' },
            ),
        ).toBe(false);
        expect(
            youtubePostOptionsEqual(
                { ...selectedOptions, description: '' },
                selectedOptions,
            ),
        ).toBe(false);
        expect(
            youtubePostOptionsEqual(
                { ...selectedOptions, description: '' },
                { ...selectedOptions, description: null as unknown as string },
            ),
        ).toBe(true);
        expect(youtubePostOptionsEqual({ made_for_kids: false }, {})).toBe(
            false,
        );
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                thumbnail_media_id: null,
            }),
        ).toBe(true);
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                thumbnail_media_id: '',
            }),
        ).toBe(false);
    });

    it('normalizes a server null description as explicitly blank without turning omission into blank', () => {
        // Laravel may serialize a submitted empty string as null.
        const options = {
            ...selectedOptions,
            description: null as unknown as string,
        };
        const post = postWithOptions(options);
        const state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        expect(state.youtubeByAccount['youtube-account'].description).toBe('');
        expect(
            youtubeOptionsComplete(state.youtubeByAccount['youtube-account']),
        ).toBe(true);
        expect(contentMatchesServer(state, post)).toBe(true);
        expect(
            contentMatchesServer(
                state,
                postWithOptions({ ...selectedOptions, description: '' }),
            ),
        ).toBe(true);
        expect(
            contentMatchesServer(state, postWithOptions(selectedOptions)),
        ).toBe(false);
        expect(
            buildPutBody(state, ['youtube-account']).targets[0].content_override
                ?.youtube?.description,
        ).toBe('');
    });

    it('keeps optional title and empty description separate from the inherited caption', () => {
        const options = {
            ...selectedOptions,
            title: 'A separate YouTube title',
            description: '',
        };
        const post = postWithOptions(options);
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        expect(state.youtubeByAccount['youtube-account']).toEqual(options);
        expect(contentMatchesServer(state, post)).toBe(true);
        state = composerReducer(state, {
            type: 'updateSegments',
            segments: ['New post caption'],
        });
        expect(
            buildPutBody(state, ['youtube-account']).targets[0]
                .content_override,
        ).toEqual({ youtube: options });
        expect(youtubeOptionsComplete(options)).toBe(true);
        expect(youtubeOptionsComplete(selectedOptions)).toBe(true);
    });

    it('validates provided copy with Unicode character and UTF-8 byte limits', () => {
        expect(youtubeCopyErrors(undefined)).toEqual({});
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                title: '😀'.repeat(100),
                description: 'é'.repeat(2500),
            }),
        ).toBe(true);
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                title: '😀'.repeat(101),
            }),
        ).toBe(false);
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                description: 'é'.repeat(2501),
            }),
        ).toBe(false);
        expect(youtubeOptionsComplete({ ...selectedOptions, title: '' })).toBe(
            false,
        );
        expect(
            youtubeOptionsComplete({ ...selectedOptions, title: '   ' }),
        ).toBe(false);
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                title: 'Title <tag>',
            }),
        ).toBe(false);
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                description: 'Description >',
            }),
        ).toBe(false);
        expect(
            youtubeOptionsComplete({ ...selectedOptions, description: '' }),
        ).toBe(true);
    });

    it('requires explicit visibility format and declarations before publishing', () => {
        expect(YOUTUBE_DEFAULT_OPTIONS.privacy_status).toBeUndefined();
        expect(YOUTUBE_DEFAULT_OPTIONS.format_intent).toBeUndefined();
        expect(YOUTUBE_DEFAULT_OPTIONS.made_for_kids).toBeUndefined();
        expect(
            YOUTUBE_DEFAULT_OPTIONS.contains_synthetic_media,
        ).toBeUndefined();
        expect(
            YOUTUBE_DEFAULT_OPTIONS.has_paid_product_placement,
        ).toBeUndefined();
        expect(youtubeOptionsComplete(undefined)).toBe(false);
        expect(
            youtubeOptionsComplete({
                ...YOUTUBE_DEFAULT_OPTIONS,
                privacy_status: 'public',
            }),
        ).toBe(false);
        expect(youtubeOptionsComplete(selectedOptions)).toBe(true);
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                made_for_kids: undefined,
            }),
        ).toBe(false);
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                category_id: 'not-a-category',
            }),
        ).toBe(false);
        expect(
            youtubeOptionsComplete({ ...selectedOptions, category_id: '1234' }),
        ).toBe(false);
    });

    it('keeps partial choices in a draft save without creating an empty caption override', () => {
        const partial: YouTubePostOptions = {
            ...YOUTUBE_DEFAULT_OPTIONS,
            privacy_status: 'unlisted',
        };
        const state = composerReducer(initialComposerState(), {
            type: 'setYouTubeOptions',
            accountId: 'youtube-account',
            options: partial,
        });
        expect(state.saveState).toBe('dirty');
        const target = buildPutBody(state, ['youtube-account']).targets[0];
        expect(target.content_override).toEqual({ youtube: partial });
        expect(target.content_override?.segments).toBeUndefined();
    });

    it('rehydrates and resaves exact false and true declarations independently per account', () => {
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: postWithOptions(selectedOptions),
        });
        expect(state.segments).toEqual(['Inherited caption']);
        expect(state.overrideByAccount['youtube-account']).toBeUndefined();
        expect(state.youtubeByAccount['youtube-account']).toEqual(
            selectedOptions,
        );
        state = composerReducer(state, {
            type: 'setYouTubeOptions',
            accountId: 'second-channel',
            options: {
                ...selectedOptions,
                privacy_status: 'private',
                notify_subscribers: false,
            },
        });
        const targets = buildPutBody(state, [
            'youtube-account',
            'second-channel',
        ]).targets;
        expect(targets[0].content_override).toEqual({
            youtube: selectedOptions,
        });
        expect(targets[1].content_override?.youtube).toEqual({
            ...selectedOptions,
            privacy_status: 'private',
            notify_subscribers: false,
        });
    });

    it('ignores retained settings for deselected channels when reconciling a stale save', () => {
        const post = postWithOptions(selectedOptions);
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        state = composerReducer(state, {
            type: 'setYouTubeOptions',
            accountId: 'deselected-youtube',
            options: selectedOptions,
        });
        state = composerReducer(state, {
            type: 'setTikTokOptions',
            accountId: 'deselected-tiktok',
            options: TIKTOK_DEFAULT_OPTIONS,
        });

        expect(contentMatchesServer(state, post)).toBe(true);
        expect(
            composerReducer(state, { type: 'saveFailedStale', post }).saveState,
        ).toBe('saved');

        state = composerReducer(state, {
            type: 'setDestination',
            destination: {
                kind: 'accounts',
                ids: ['youtube-account', 'deselected-youtube'],
            },
        });
        expect(contentMatchesServer(state, post)).toBe(false);
    });
});

describe('YouTube cover eligibility', () => {
    const account = { id: 'youtube-account', platform: 'youtube' as const };
    const state = {
        ...initialComposerState(),
        media: [video],
        placements: { __head__: ['video'] },
    };

    it('requires one effective video without assuming custom-cover support for Shorts', () => {
        expect(canChooseYouTubeThumbnail(state, account)).toBe(true);
        expect(
            canChooseYouTubeThumbnail(
                { ...state, placements: { __head__: ['video', 'removed-id'] } },
                account,
            ),
        ).toBe(true);
        expect(
            canChooseYouTubeThumbnail(
                { ...state, placementsByAccount: { [account.id]: {} } },
                account,
            ),
        ).toBe(false);
        expect(
            canChooseYouTubeThumbnail(
                { ...state, media: [{ ...video, kind: 'image' }] },
                account,
            ),
        ).toBe(false);
        expect(
            canChooseYouTubeThumbnail(
                {
                    ...state,
                    media: [video, { ...video, id: 'second-video' }],
                    placements: { __head__: ['video', 'second-video'] },
                },
                account,
            ),
        ).toBe(false);
        expect(
            canChooseYouTubeThumbnail(state, {
                ...account,
                platform: 'instagram',
            }),
        ).toBe(false);
        expect(
            youtubeOptionsComplete({
                ...selectedOptions,
                format_intent: 'short',
                thumbnail_media_id: 'cover-id',
            }),
        ).toBe(true);
    });

    it('limits uploads to nonempty JPEG and PNG files up to 8 MB', () => {
        expect(
            youtubeThumbnailFileError(
                new File(['bytes'], 'cover.jpg', { type: 'image/jpeg' }),
            ),
        ).toBeNull();
        expect(
            youtubeThumbnailFileError(
                new File(['bytes'], 'cover.png', { type: 'image/png' }),
            ),
        ).toBeNull();
        expect(
            youtubeThumbnailFileError(
                new File(['bytes'], 'cover.gif', { type: 'image/gif' }),
            ),
        ).toContain('JPEG or PNG');
        expect(
            youtubeThumbnailFileError(
                new File([], 'empty.jpg', { type: 'image/jpeg' }),
            ),
        ).toContain('larger than 0');
        const file = new File(['bytes'], 'cover.jpg', { type: 'image/jpeg' });
        Object.defineProperty(file, 'size', { value: 8 * 1024 * 1024 + 1 });
        expect(youtubeThumbnailFileError(file)).toContain('8 MB');
    });
});
