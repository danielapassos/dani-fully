<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\ConnectedAccounts\TikTok\TikTokPostOptions;
use App\Services\Publishing\InstagramReelCover;
use App\Services\Publishing\SegmentMediaResolver;
use App\Services\Publishing\TargetMediaSelection;
use App\Services\Publishing\YouTubePostOptions;
use App\Services\Publishing\YouTubeThumbnail;
use Illuminate\Support\Collection;

class PublishPrecheck
{
    public function __construct(
        private readonly PostSplitter $splitter,
        private readonly SegmentMediaResolver $segmentMediaResolver,
        private readonly TargetMediaSelection $targetMediaSelection,
    ) {}

    /**
     * Targets whose stored content would be rejected by the platform. Reuses the
     * same validation the composer preview shows, so a doomed publish is stopped
     * before dispatch instead of failing per-target on the platform API.
     *
     * A target with neither text nor media is blocked as `empty` — there is
     * nothing to post, and the platform limit checks are meaningless on it.
     *
     * The media-required rule lives here rather than in
     * PostSplitter::validateSections() because split() calls that method with a
     * hardcoded media count of 0 (it runs before media is known), which would
     * report a false `media_required` on every Instagram draft.
     *
     * @return list<array{connected_account_id: string, handle: ?string, platform: string, issues: list<string>}>
     */
    public function blockingTargets(Post $post): array
    {
        $media = $post->media;

        /** @var list<array{connected_account_id: string, handle: ?string, platform: string, issues: list<string>}> $blocking */
        $blocking = [];

        foreach ($post->targets as $target) {
            /** @var PostTarget $target */
            $targetMedia = $this->targetMedia($target, $media);
            $issues = $this->hasContent($target, $targetMedia->count())
                ? $this->targetIssues($target, $targetMedia)
                : ['empty'];

            if ($issues === []) {
                continue;
            }

            $blocking[] = [
                'connected_account_id' => (string) $target->connected_account_id,
                'handle' => $target->account?->handle,
                'platform' => $target->platform->value,
                'issues' => $issues,
            ];
        }

        return $blocking;
    }

    /**
     * A human-readable reason a target was blocked, for the stored error_message
     * on the non-interactive dispatch paths (scheduler, MCP) where there is no
     * client to render the raw issue codes.
     *
     * @param  list<string>  $issues
     */
    public function describe(array $issues, Platform $platform): string
    {
        $label = $platform->label();

        $messages = array_map(static fn (string $issue): string => str_starts_with($issue, 'tiktok_')
            ? app(TikTokPostOptions::class)->describe($issue)
            : match ($issue) {
                'empty' => 'Add text or media before publishing.',
                'publishing_unavailable' => "Reconnect the {$label} account or enable {$label} publishing before posting.",
                'youtube_options_required' => 'Review YouTube visibility, format, audience, synthetic-media, paid-placement, and subscriber notification choices before publishing.',
                'youtube_thumbnail_unavailable' => YouTubeThumbnail::UNAVAILABLE_MESSAGE,
                'youtube_thumbnail_release_scope_required' => YouTubeThumbnail::RELEASE_SCOPE_MESSAGE,
                'youtube_thumbnail_requires_video' => 'A custom YouTube cover requires one video.',
                'instagram_cover_unavailable' => 'Choose an available JPEG or PNG cover image from this workspace.',
                'instagram_cover_requires_reel' => 'A custom Instagram cover requires one Reel video. Remove the cover for photos, carousels, or Stories.',
                'media_required' => "{$label} needs at least one image or video.",
                'video_required' => "{$label} needs exactly one video for this publishing flow.",
                'section_too_long' => "A section is over {$label}'s length limit.",
                'too_many_sections' => "Too many thread sections for {$label}.",
                'too_many_media' => "Too many media items for {$label}.",
                'mixed_video_and_images' => 'A post can contain one video or images, not both.',
                'video_too_long' => "The video is longer than {$label} allows.",
                'video_too_large' => "The video is larger than {$label} allows.",
                'gif_not_mixable' => "{$label} allows only one GIF and won't mix it with other media.",
                'unplaced_media' => "Some attached media isn't placed in this post — remove it or add it to a thread section.",
                default => "{$label} can't publish this post yet.",
            }, $issues);

        return implode(' ', array_values(array_unique($messages)));
    }

    /**
     * Platform-limit issues for a target that has content, plus the media rules
     * the section-length limits don't cover.
     *
     * `too_many_media` is deliberately not sourced from
     * PostSplitter::validateSections() here: that method's media-count argument
     * is checked against the whole post, but publishing caps media per thread
     * section (each connector slices `mediaForSection()` to
     * `Platform::maxMedia()`). A thread with 4 images on each of two sections is
     * publishable even though it holds 8 images overall, so `mediaIssues()`
     * recomputes this per section using the same grouping `mixIssues()` already
     * relies on.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return list<string>
     */
    private function targetIssues(PostTarget $target, Collection $media): array
    {
        $platform = $target->platform;

        $issues = $this->splitter->validateSections(
            $target->sections,
            $platform,
            0,
            $target->account?->maxTextLength(),
        );

        if (! $target->account?->canPublish()) {
            $issues[] = 'publishing_unavailable';
        }

        $requiresVideo = $platform->requiresVideo() || $target->format->requiresVideo();
        $requiresMedia = $platform->requiresMedia() || $target->format->requiresMedia();

        if ($requiresVideo && ! $media->contains(fn (PostMedia $item): bool => $item->isVideo())) {
            $issues[] = 'video_required';
        } elseif ($media->count() === 0 && $requiresMedia) {
            $issues[] = 'media_required';
        }

        if ($target->format->singleMediaOnly() && $media->count() > 1) {
            $issues[] = 'too_many_media';
        }

        foreach ($this->mediaIssues($target, $platform, $media) as $issue) {
            $issues[] = $issue;
        }

        if ($platform === Platform::TikTok && config('services.tiktok.direct_post_enabled')
            && ! collect($target->media_upload_state ?? [])->contains(static fn (mixed $entry): bool => is_array($entry) && ! empty($entry['remote_ref']))) {
            $options = $target->content_override['tiktok'] ?? [];
            $video = $media->first(fn (PostMedia $item): bool => $item->isVideo());
            $issues = array_merge($issues, app(TikTokPostOptions::class)->issues(
                is_array($options) ? $options : [],
                durationSeconds: $video?->duration_seconds,
            ));
        }

        if ($platform === Platform::YouTube && app(YouTubePostOptions::class)->resolve($target) === null) {
            $issues[] = 'youtube_options_required';
        }

        $mediaItems = array_values($media->all());
        $issues = array_merge($issues, app(InstagramReelCover::class)->issues($target, $mediaItems), app(YouTubeThumbnail::class)->issues($target, $mediaItems));

        return array_values(array_unique($issues));
    }

    /**
     * Media-attribute rules the connectors enforce only at publish time — the
     * per-section media cap, image/video mixing, and GIF mixing.
     * Video caps are checked against the whole target's media (never
     * re-encoded server-side, so caps can't self-heal the way images can). The
     * other rules are checked per thread segment: each connector publishes a
     * thread segment as its own post (see SegmentMediaResolver/mediaForSection),
     * so e.g. two segments each holding 4 images don't violate a platform's
     * 4-image cap that only applies within a single published post.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return list<string>
     */
    private function mediaIssues(PostTarget $target, Platform $platform, Collection $media): array
    {
        if ($media->isEmpty()) {
            return [];
        }

        $issues = [];
        $sections = $this->mediaBySection($target, $media);

        foreach ($sections as $sectionMedia) {
            if ($sectionMedia->count() > $platform->maxMedia()) {
                $issues[] = 'too_many_media';
            }

            foreach ($this->mixIssues($platform, $sectionMedia) as $issue) {
                $issues[] = $issue;
            }
        }

        $videos = $media->filter(fn (PostMedia $item): bool => $item->isVideo());
        foreach ($videos as $video) {
            if ($video->duration_seconds !== null && $video->duration_seconds > $platform->maxVideoDurationSeconds()) {
                $issues[] = 'video_too_long';
            }

            if ($video->size_bytes > $platform->maxVideoBytes()) {
                $issues[] = 'video_too_large';
            }
        }

        return $issues;
    }

    /**
     * The image/video and GIF mixing rules, judged against a single thread
     * segment's media rather than the whole post's.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return list<string>
     */
    private function mixIssues(Platform $platform, Collection $media): array
    {
        $issues = [];

        $videos = $media->filter(fn (PostMedia $item): bool => $item->isVideo());
        $images = $media->reject(fn (PostMedia $item): bool => $item->isVideo());

        // On platforms that don't build a real mixed carousel, the connector keeps
        // only the first video and silently drops every image — a "successful"
        // publish would be missing content.
        if ($videos->isNotEmpty() && $images->isNotEmpty() && ! $platform->combinesVideoAndImages()) {
            $issues[] = 'mixed_video_and_images';
        }

        if (! $platform->allowsGifWithOtherMedia()) {
            $gifCount = $media->filter(fn (PostMedia $item): bool => $item->mime === 'image/gif')->count();
            if ($gifCount >= 1 && ($media->count() > 1 || $gifCount > 1)) {
                $issues[] = 'gif_not_mixable';
            }
        }

        return $issues;
    }

    /**
     * This target's media grouped by resolved thread segment, mirroring how
     * PublishPostTarget builds the PublishContext each connector actually
     * publishes from. Targets with no placements (e.g. media added before
     * per-segment placements existed, or fixtures that don't set them up) fall
     * back to a single segment holding all of the target's media.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return array<int, Collection<int, PostMedia>>
     */
    private function mediaBySection(PostTarget $target, Collection $media): array
    {
        $selection = $this->targetMediaSelection->resolve($target, $target->placements);

        $bySection = $this->segmentMediaResolver->resolve(
            sections: $target->sections,
            sectionSources: $target->section_sources ?? [],
            segmentBreaks: $target->segment_breaks ?? [],
            placements: $selection['placements'],
            allMedia: array_values($media->all()),
            placementsExplicit: $selection['explicit'],
        );

        return array_map(static fn (array $sectionMedia): Collection => collect($sectionMedia), $bySection);
    }

    /**
     * Resolve the ordered media this account will actually publish. Once a
     * target has explicit placements, attachments omitted from that map are an
     * intentional account-specific exclusion rather than a validation error.
     * Legacy targets without placement rows retain the full-media fallback.
     *
     * @param  Collection<int, PostMedia>  $media
     * @return Collection<int, PostMedia>
     */
    private function targetMedia(PostTarget $target, Collection $media): Collection
    {
        $selection = $this->targetMediaSelection->resolve($target, $target->placements);
        if (! $selection['explicit']) {
            return $media->values();
        }

        $byId = $media->keyBy(fn (PostMedia $item): string => (string) $item->id);

        return collect($selection['placements'])
            ->map(fn (array $placement): ?PostMedia => $byId->get($placement['post_media_id']))
            ->filter(static fn (?PostMedia $item): bool => $item !== null)
            ->unique(fn (PostMedia $item): string => (string) $item->id)
            ->values();
    }

    /**
     * Whether a target has anything worth posting. Empty segments are stored as
     * a single blank section by PostSplitter, so a text-less target arrives here
     * as `['']` rather than `[]`.
     */
    private function hasContent(PostTarget $target, int $mediaCount): bool
    {
        if ($mediaCount > 0) {
            return true;
        }

        foreach ($target->sections as $section) {
            if (trim($section) !== '') {
                return true;
            }
        }

        return false;
    }
}
