import type {
    TikTokCreatorInfo,
    TikTokPostOptions,
    TikTokPrivacy,
} from '@/types/compose';

export const TIKTOK_PRIVACY_LABELS: Record<TikTokPrivacy, string> = {
    PUBLIC_TO_EVERYONE: 'Everyone',
    MUTUAL_FOLLOW_FRIENDS: 'Friends',
    FOLLOWER_OF_CREATOR: 'Followers',
    SELF_ONLY: 'Only me',
};

export const TIKTOK_DEFAULT_OPTIONS: TikTokPostOptions = {
    disable_comment: true,
    disable_duet: true,
    disable_stitch: true,
    commercial_content: false,
    brand_organic_toggle: false,
    brand_content_toggle: false,
    is_aigc: false,
    music_usage_confirmed: false,
    branded_content_policy_confirmed: false,
};

export const TIKTOK_ISSUE_MESSAGES = {
    tiktok_creator_unavailable:
        'Refresh the TikTok publishing settings before posting.',
    tiktok_privacy_required:
        'Choose a privacy setting currently available for this TikTok account.',
    tiktok_options_required:
        'Review the TikTok publishing controls before posting.',
    tiktok_music_consent_required:
        'Agree to TikTok’s Music Usage Confirmation before posting.',
    tiktok_commercial_disclosure_required:
        'Indicate whether commercial content promotes your brand, a third party, or both.',
    tiktok_branded_content_private:
        'Branded content visibility cannot be set to private.',
    tiktok_branded_consent_required:
        'Agree to TikTok’s Branded Content Policy before posting branded content.',
    tiktok_duration_unknown:
        'The video duration must be known before posting to TikTok.',
    tiktok_duration_exceeded:
        'The video is longer than this TikTok account currently allows.',
    tiktok_cover_invalid: 'Choose a cover time within the video.',
    tiktok_interaction_unavailable:
        'An interaction is disabled in this TikTok account’s settings. Refresh the publishing controls.',
} as const;

export type TikTokIssue = keyof typeof TIKTOK_ISSUE_MESSAGES;

export function tiktokIssues(
    options: TikTokPostOptions | undefined,
    creator: TikTokCreatorInfo | null | undefined,
    durationSeconds: number | null | undefined,
): TikTokIssue[] {
    const issues: TikTokIssue[] = [];
    if (!creator) issues.push('tiktok_creator_unavailable');
    if (!options) issues.push('tiktok_options_required');
    const current = options ?? TIKTOK_DEFAULT_OPTIONS;
    if (
        !current.privacy_level ||
        (creator &&
            !creator.privacy_level_options.includes(current.privacy_level))
    )
        issues.push('tiktok_privacy_required');
    if (!current.music_usage_confirmed)
        issues.push('tiktok_music_consent_required');
    if (
        (current.commercial_content &&
            !current.brand_organic_toggle &&
            !current.brand_content_toggle) ||
        (!current.commercial_content &&
            (current.brand_organic_toggle || current.brand_content_toggle))
    )
        issues.push('tiktok_commercial_disclosure_required');
    if (current.brand_content_toggle && current.privacy_level === 'SELF_ONLY')
        issues.push('tiktok_branded_content_private');
    if (
        current.brand_content_toggle &&
        !current.branded_content_policy_confirmed
    )
        issues.push('tiktok_branded_consent_required');
    if (!durationSeconds || durationSeconds < 1)
        issues.push('tiktok_duration_unknown');
    else if (creator && durationSeconds > creator.max_video_post_duration_sec)
        issues.push('tiktok_duration_exceeded');
    if (
        current.video_cover_timestamp_ms !== undefined &&
        (!Number.isInteger(current.video_cover_timestamp_ms) ||
            current.video_cover_timestamp_ms < 0 ||
            !durationSeconds ||
            current.video_cover_timestamp_ms >= durationSeconds * 1000)
    )
        issues.push('tiktok_cover_invalid');
    if (
        creator &&
        ((creator.comment_disabled && !current.disable_comment) ||
            (creator.duet_disabled && !current.disable_duet) ||
            (creator.stitch_disabled && !current.disable_stitch))
    )
        issues.push('tiktok_interaction_unavailable');
    return issues;
}
