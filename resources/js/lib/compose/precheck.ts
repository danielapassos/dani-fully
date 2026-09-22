import { replaceMentionTokens } from '@/lib/compose/mentions';
import { measure } from '@/lib/compose/section-split';
import {
    TIKTOK_ISSUE_MESSAGES,
    tiktokIssues,
    type TikTokIssue,
} from '@/lib/compose/tiktok';
import { youtubeOptionsComplete } from '@/lib/compose/youtube';
import { platformLabel } from '@/lib/platforms';
import type {
    Account,
    InstagramPostOptions,
    MediaView,
    MentionPlaceholder,
    PlatformLimits,
    PlatformName,
    PostFormat,
    YouTubePostOptions,
    TikTokCreatorInfo,
    TikTokPostOptions,
} from '@/types/compose';

export type BlockReason =
    | TikTokIssue
    | 'youtube_options_required'
    | 'youtube_thumbnail_unavailable'
    | 'youtube_thumbnail_release_scope_required'
    | 'youtube_thumbnail_requires_video'
    | 'instagram_cover_requires_reel'
    | 'instagram_trial_requires_reel'
    | 'empty'
    | 'publishing_unavailable'
    | 'media_required'
    | 'video_required'
    | 'section_too_long'
    | 'too_many_sections'
    | 'too_many_media'
    | 'unplaced_media'
    | 'mixed_video_and_images'
    | 'video_too_long'
    | 'video_too_large'
    | 'gif_not_mixable'
    | 'reels_requires_video'
    | 'story_requires_media';

export type AccountBlock = {
    accountId: string;
    handle: string;
    platform: PlatformName;
    reasons: BlockReason[];
    publishingUnavailableReason?: string | null;
};

type PrecheckAccountInput = {
    account: Account;
    segments: string[];
    autoSplit: boolean;
    mentions: MentionPlaceholder[];
    mediaCount: number;
    /** Media counts for each resolved thread post; falls back to mediaCount. */
    mediaCounts?: number[];
    hasVideo: boolean;
    format: PostFormat;
    limits: PlatformLimits;
};

function byteLength(text: string): number {
    return new TextEncoder().encode(text).length;
}

/**
 * Blocking reasons for one account, mirroring the sections the server's
 * PostSplitter will actually store:
 *  - no text and no media: nothing to post, so `empty` is the only reason —
 *    the length/media checks below are meaningless on it.
 *  - media-first platform (requiresMedia): text alone is rejected by the
 *    platform, so a caption with no attachment blocks as `media_required`.
 *  - thread-capped platform (threadMax !== null): all segments collapse into a
 *    single joined section.
 *  - non-capped + auto-split ON: the server hard-splits any over-limit paragraph
 *    down to word/char, so every stored section fits by length — length never
 *    blocks (a rare byte-budget survivor is caught by the server backstop).
 *  - non-capped + auto-split OFF: stored sections are the raw trimmed segments.
 */
export function precheckAccount({
    account,
    segments,
    autoSplit,
    mentions,
    mediaCount,
    mediaCounts,
    hasVideo,
    format,
    limits,
}: PrecheckAccountInput): BlockReason[] {
    const reasons: BlockReason[] = [];
    const clean = segments
        .map((segment) => segment.trim())
        .filter((segment) => segment !== '');

    if (clean.length === 0 && mediaCount === 0) {
        return ['empty'];
    }

    const capped = limits.threadMax !== null;
    const sections = capped ? [clean.join('\n')] : autoSplit ? [] : clean;

    const limit = account.max_text_length || limits.maxLength;
    const overLength = sections.some((section) => {
        const resolved = replaceMentionTokens(
            section,
            mentions,
            account.platform,
        );
        if (limit > 0 && measure(resolved, account.platform) > limit) {
            return true;
        }

        return (
            limits.maxBytes !== null && byteLength(resolved) > limits.maxBytes
        );
    });
    if (overLength) {
        reasons.push('section_too_long');
    }

    if (limits.threadMax !== null && sections.length > limits.threadMax) {
        reasons.push('too_many_sections');
    }

    if (account.publishing_ready === false) {
        reasons.push('publishing_unavailable');
    }

    const formatRequiresSingleMedia = format === 'reels' || format === 'story';
    if (
        (mediaCounts ?? [mediaCount]).some(
            (count) => count > limits.maxMedia,
        ) ||
        (formatRequiresSingleMedia && mediaCount > 1)
    ) {
        reasons.push('too_many_media');
    }

    if (limits.requiresVideo && !hasVideo) {
        reasons.push('video_required');
    } else if (mediaCount === 0 && limits.requiresMedia) {
        reasons.push('media_required');
    }

    if (format === 'reels' && !hasVideo) {
        reasons.push('reels_requires_video');
    }
    if (format === 'story' && mediaCount === 0) {
        reasons.push('story_requires_media');
    }

    return reasons;
}

type PrecheckDestinationsInput = {
    accounts: Account[];
    segments: string[];
    mentions: MentionPlaceholder[];
    autoSplitByAccount: Record<string, boolean>;
    overrideByAccount: Record<string, string[] | undefined>;
    media: MediaView[];
    limits: PlatformLimits[];
    formatByAccount: Record<string, PostFormat>;
    /** Canonical segmentRef -> ordered media ids. */
    placements?: Record<string, string[]>;
    /** Per-account placement overrides for targets that diverged. */
    placementsByAccount?: Record<string, Record<string, string[]>>;
    /** Ordered segment break ids used to resolve placement refs. */
    segmentBreaks?: string[];
    youtubeByAccount?: Record<string, YouTubePostOptions>;
    instagramByAccount?: Record<string, InstagramPostOptions>;
    tiktokByAccount?: Record<string, TikTokPostOptions>;
    tiktokCreatorByAccount?: Record<string, TikTokCreatorInfo | null>;
};

/**
 * Resolve this target's media counts the same way the server's publishing
 * precheck does. Explicit placements are capped per authored thread segment;
 * a thread-capped platform collapses them into its single published post.
 * Legacy drafts without placements fall back to one post containing all media.
 */
function mediaGroupingForTarget(
    media: MediaView[],
    limits: PlatformLimits,
    placements: Record<string, string[]> | undefined,
    segmentBreaks: string[],
): { counts: number[]; mediaIds: string[] } {
    if (media.length === 0) {
        return { counts: [0], mediaIds: [] };
    }

    const mediaIds = new Set(media.map((item) => item.id));
    const placedIds = new Set<string>();
    const validRefs = new Set(['__head__', ...segmentBreaks]);
    const counts = new Map<string, number>();

    for (const [segmentRef, ids] of Object.entries(placements ?? {})) {
        const resolvedRef = validRefs.has(segmentRef) ? segmentRef : '__head__';
        for (const id of ids) {
            if (!mediaIds.has(id) || placedIds.has(id)) {
                continue;
            }
            placedIds.add(id);
            counts.set(resolvedRef, (counts.get(resolvedRef) ?? 0) + 1);
        }
    }

    // Only a missing placement map is legacy. A defined empty map (or one whose
    // rows no longer resolve) is an explicit account-specific exclusion and
    // must never silently put every attachment back.
    if (placedIds.size === 0) {
        return placements === undefined
            ? { counts: [media.length], mediaIds: media.map(({ id }) => id) }
            : { counts: [0], mediaIds: [] };
    }

    if (limits.threadMax !== null) {
        return {
            counts: [
                [...counts.values()].reduce((sum, count) => sum + count, 0),
            ],
            mediaIds: [...placedIds],
        };
    }

    return { counts: [...counts.values()], mediaIds: [...placedIds] };
}

export function precheckDestinations({
    accounts,
    segments,
    mentions,
    autoSplitByAccount,
    overrideByAccount,
    media,
    limits,
    formatByAccount,
    placements,
    placementsByAccount,
    segmentBreaks = [],
    youtubeByAccount,
    instagramByAccount,
    tiktokByAccount,
    tiktokCreatorByAccount,
}: PrecheckDestinationsInput): AccountBlock[] {
    const blocks: AccountBlock[] = [];
    for (const account of accounts) {
        const platformLimits = limits.find(
            (item) => item.platform === account.platform,
        );
        if (!platformLimits) {
            continue;
        }
        const accountSegments = overrideByAccount[account.id] ?? segments;
        const mediaGrouping = mediaGroupingForTarget(
            media,
            platformLimits,
            placementsByAccount?.[account.id] ?? placements,
            segmentBreaks,
        );
        const effectiveIds = new Set(mediaGrouping.mediaIds);
        const targetMedia = media.filter(({ id }) => effectiveIds.has(id));
        const reasons = precheckAccount({
            account,
            segments: accountSegments,
            autoSplit: autoSplitByAccount[account.id] ?? true,
            mentions,
            mediaCount: targetMedia.length,
            mediaCounts: mediaGrouping.counts,
            hasVideo: targetMedia.some((item) => item.kind === 'video'),
            format: formatByAccount[account.id] ?? 'feed',
            limits: platformLimits,
        });
        if (
            account.platform === 'instagram' &&
            instagramByAccount?.[account.id]?.cover_media_id &&
            (formatByAccount[account.id] === 'story' ||
                targetMedia.length !== 1 ||
                targetMedia[0].kind !== 'video')
        ) {
            reasons.push('instagram_cover_requires_reel');
        }
        if (
            account.platform === 'instagram' &&
            instagramByAccount?.[account.id]?.trial_params &&
            (formatByAccount[account.id] === 'story' ||
                targetMedia.length !== 1 ||
                targetMedia[0].kind !== 'video')
        ) {
            reasons.push('instagram_trial_requires_reel');
        }
        if (
            account.platform === 'youtube' &&
            youtubeByAccount !== undefined &&
            !youtubeOptionsComplete(youtubeByAccount[account.id])
        ) {
            reasons.push('youtube_options_required');
        }
        if (
            account.platform === 'tiktok' &&
            account.tiktok_direct_post_enabled
        ) {
            reasons.push(
                ...tiktokIssues(
                    tiktokByAccount?.[account.id],
                    tiktokCreatorByAccount?.[account.id],
                    targetMedia.find((item) => item.kind === 'video')
                        ?.duration_seconds,
                ),
            );
        }
        if (reasons.length > 0) {
            blocks.push({
                accountId: account.id,
                handle: account.handle,
                platform: account.platform,
                reasons,
                publishingUnavailableReason:
                    account.publishing_unavailable_reason,
            });
        }
    }

    return blocks;
}

export function describeReason(
    reason: BlockReason,
    platform: PlatformName,
    limits: PlatformLimits,
    publishingUnavailableReason?: string | null,
): string {
    if (reason in TIKTOK_ISSUE_MESSAGES)
        return TIKTOK_ISSUE_MESSAGES[reason as TikTokIssue];
    const label = platformLabel(platform);
    switch (reason) {
        case 'empty':
            return 'add some text or media before publishing';
        case 'publishing_unavailable':
            return (
                publishingUnavailableReason ??
                `reconnect the ${label} account or enable ${label} publishing before posting`
            );
        case 'media_required':
            return `${label} needs at least one image or video`;
        case 'video_required':
            return `${label} needs exactly one video`;
        case 'section_too_long': {
            const base = `over ${label}'s ${limits.maxLength.toLocaleString()}-character limit`;

            return limits.threadMax === null
                ? `${base} — shorten it or turn on auto-split`
                : base;
        }
        case 'too_many_sections': {
            const max = limits.threadMax ?? 1;

            return `${label} allows only ${max} post${max === 1 ? '' : 's'} — remove thread breaks`;
        }
        case 'too_many_media':
            return `${label} allows only ${limits.maxMedia} media item${limits.maxMedia === 1 ? '' : 's'}`;
        case 'unplaced_media':
            return 'some attached media is not placed — remove it or add it to a thread section';
        case 'mixed_video_and_images':
            return 'a post can contain one video or images, not both';
        case 'video_too_long':
            return `the video is longer than ${label}'s ${limits.maxVideoDurationSeconds}s limit`;
        case 'video_too_large':
            return `the video is larger than ${label}'s ${Math.floor(limits.maxVideoBytes / (1024 * 1024))} MB limit`;
        case 'gif_not_mixable':
            return `${label} allows only one GIF and won't mix it with other media`;
        case 'reels_requires_video':
            return `${label} Reels need a video`;
        case 'instagram_cover_requires_reel':
            return 'use exactly one video in an Instagram Reel or feed post, or remove its cover';
        case 'instagram_trial_requires_reel':
            return 'use exactly one video in an Instagram Reel or feed post, or turn off Trial Reel';
        case 'youtube_options_required':
            return 'Complete YouTube publishing settings and fix any title or description errors before publishing.';
        case 'youtube_thumbnail_unavailable':
            return 'Choose an existing JPEG or PNG image up to 8 MB from this workspace for the YouTube cover.';
        case 'youtube_thumbnail_release_scope_required':
            return 'Reconnect this YouTube account to grant video management access before publishing with a cover. The upload stays private until YouTube accepts the cover.';
        case 'youtube_thumbnail_requires_video':
            return 'A custom YouTube cover requires exactly one video. Add a video or remove the cover.';
        case 'story_requires_media':
            return `${label} Stories need an image or video`;
        default:
            return 'review the publishing settings';
    }
}
