import { describe, expect, it } from 'vitest';

import type { TikTokCreatorInfo, TikTokPostOptions } from '@/types/compose';

import {
    buildPutBody,
    composerReducer,
    initialComposerState,
} from '../composer-state';
import { TIKTOK_DEFAULT_OPTIONS, tiktokIssues } from '../tiktok';

const creator: TikTokCreatorInfo = {
    creator_username: 'current_creator',
    creator_nickname: 'Current creator',
    creator_avatar_url: null,
    privacy_level_options: ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
    comment_disabled: false,
    duet_disabled: true,
    stitch_disabled: false,
    max_video_post_duration_sec: 60,
};

const readyOptions: TikTokPostOptions = {
    ...TIKTOK_DEFAULT_OPTIONS,
    privacy_level: 'PUBLIC_TO_EVERYONE',
    music_usage_confirmed: true,
};

describe('TikTok direct publishing controls', () => {
    it('does not preselect privacy, interaction opt-ins, disclosure, or consent', () => {
        expect(TIKTOK_DEFAULT_OPTIONS.privacy_level).toBeUndefined();
        expect(TIKTOK_DEFAULT_OPTIONS.disable_comment).toBe(true);
        expect(TIKTOK_DEFAULT_OPTIONS.disable_duet).toBe(true);
        expect(TIKTOK_DEFAULT_OPTIONS.disable_stitch).toBe(true);
        expect(TIKTOK_DEFAULT_OPTIONS.commercial_content).toBe(false);
        expect(TIKTOK_DEFAULT_OPTIONS.music_usage_confirmed).toBe(false);
        expect(tiktokIssues(undefined, creator, 12)).toEqual(
            expect.arrayContaining([
                'tiktok_options_required',
                'tiktok_privacy_required',
                'tiktok_music_consent_required',
            ]),
        );
    });

    it('requires fresh creator settings and checks their restrictions', () => {
        expect(tiktokIssues(readyOptions, null, 12)).toContain(
            'tiktok_creator_unavailable',
        );
        expect(
            tiktokIssues(
                { ...readyOptions, privacy_level: 'FOLLOWER_OF_CREATOR' },
                creator,
                12,
            ),
        ).toContain('tiktok_privacy_required');
        expect(
            tiktokIssues({ ...readyOptions, disable_duet: false }, creator, 12),
        ).toContain('tiktok_interaction_unavailable');
        expect(tiktokIssues(readyOptions, creator, 61)).toContain(
            'tiktok_duration_exceeded',
        );
        expect(tiktokIssues(readyOptions, creator, null)).toContain(
            'tiktok_duration_unknown',
        );
    });

    it('blocks incomplete commercial disclosures and private branded content', () => {
        expect(
            tiktokIssues(
                { ...readyOptions, commercial_content: true },
                creator,
                12,
            ),
        ).toContain('tiktok_commercial_disclosure_required');
        const branded = {
            ...readyOptions,
            commercial_content: true,
            brand_content_toggle: true,
        };
        expect(tiktokIssues(branded, creator, 12)).toContain(
            'tiktok_branded_consent_required',
        );
        expect(
            tiktokIssues(
                { ...branded, privacy_level: 'SELF_ONLY' },
                creator,
                12,
            ),
        ).toContain('tiktok_branded_content_private');
        expect(
            tiktokIssues(
                { ...branded, branded_content_policy_confirmed: true },
                creator,
                12,
            ),
        ).toEqual([]);
    });

    it('rejects cover timestamps outside the video and permits a declared AI video', () => {
        for (const timestamp of [-1, 0.5, 12000, Number.NaN]) {
            expect(
                tiktokIssues(
                    { ...readyOptions, video_cover_timestamp_ms: timestamp },
                    creator,
                    12,
                ),
            ).toContain('tiktok_cover_invalid');
        }
        expect(
            tiktokIssues(
                {
                    ...readyOptions,
                    is_aigc: true,
                    video_cover_timestamp_ms: 11999,
                },
                creator,
                12,
            ),
        ).toEqual([]);
    });

    it('serializes settings on the first draft without replacing its inherited caption', () => {
        let state = composerReducer(initialComposerState(), {
            type: 'setTikTokOptions',
            accountId: 'tiktok-account',
            options: readyOptions,
        });
        expect(state.saveState).toBe('dirty');
        const body = buildPutBody(state, ['tiktok-account']);
        expect(body.targets[0].content_override).toEqual({
            tiktok: readyOptions,
        });
        expect(body.targets[0].content_override?.segments).toBeUndefined();

        state = composerReducer(state, {
            type: 'setTikTokOptions',
            accountId: 'second-account',
            options: { ...readyOptions, privacy_level: 'SELF_ONLY' },
        });
        expect(
            buildPutBody(state, [
                'tiktok-account',
                'second-account',
            ]).targets.map(
                (target) => target.content_override?.tiktok?.privacy_level,
            ),
        ).toEqual(['PUBLIC_TO_EVERYONE', 'SELF_ONLY']);
    });
});
