import { describe, expect, it } from 'vitest';

import type { PostView, TargetView, YouTubePostOptions } from '@/types/compose';

import {
    buildPutBody,
    composerReducer,
    contentMatchesServer,
    initialComposerState,
} from '../composer-state';
import { TIKTOK_DEFAULT_OPTIONS } from '../tiktok';
import { YOUTUBE_DEFAULT_OPTIONS, youtubeOptionsComplete } from '../youtube';

const selectedOptions: YouTubePostOptions = {
    privacy_status: 'public',
    format_intent: 'short',
    category_id: '19',
    made_for_kids: false,
    contains_synthetic_media: true,
    has_paid_product_placement: false,
    notify_subscribers: true,
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
