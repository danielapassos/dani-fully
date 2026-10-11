<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;

final class TikTokInboxHandoff
{
    public function enabledFor(ConnectedAccount $account): bool
    {
        return $account->platform === Platform::TikTok
            && config('services.tiktok.publishing_provider', 'native') === 'native'
            && config('services.tiktok.inbox_enabled') === true
            && config('services.tiktok.direct_post_enabled') !== true;
    }

    /** @return array{kind: 'tiktok_inbox', caption: string, instructions: string, media_type: 'photo'|'video', caption_included: bool, music_track: string|null}|null */
    public function forTarget(PostTarget $target): ?array
    {
        if (! $this->isInbox($target)) {
            return null;
        }

        $photo = $this->isPhoto($target);
        $noun = $photo ? 'photo post' : 'video';
        $instructions = $target->publicationStatus() === PostTargetStatus::AwaitingAction
            ? "Open the upload notification in your TikTok inbox to finish this {$noun}."
            : "After TikTok confirms delivery, open the upload notification in your TikTok inbox to finish this {$noun}.";
        $caption = implode("\n\n", $target->sections ?? []);
        foreach ($target->media_upload_state ?? [] as $entry) {
            if (is_array($entry) && data_get($entry, 'metadata.media_type') === 'PHOTO'
                && is_string(data_get($entry, 'metadata.caption'))) {
                $caption = $entry['metadata']['caption'];
                break;
            }
        }
        $music = app(PhotoMusic::class)->track($target);
        $completion = $photo
            ? ' The photo upload includes your caption. Review it in TikTok, select music if wanted, then review mentions, cover, and privacy before posting manually.'
            : ' Shoutrrr sends only the video. Replace any prefilled caption with the saved caption below, then review mentions, cover, and privacy before posting manually in TikTok.';
        if ($photo && $music !== null) {
            $completion .= ' Music to select in TikTok: '.$music.'. This is a reminder; the track has not been attached. Availability depends on the TikTok music library for your account.';
        }

        return [
            'kind' => 'tiktok_inbox',
            'caption' => $caption,
            'media_type' => $photo ? 'photo' : 'video',
            'caption_included' => $photo,
            'music_track' => $music,
            'instructions' => $instructions.' This is an inbox upload, not an item in TikTok Drafts.'.$completion,
        ];
    }

    public function confirmation(Post $post): string
    {
        return match ($this->submissionKind($post)) {
            'inbox' => 'This will upload your media to your TikTok inbox for manual completion, not publish a live TikTok post. Photo uploads include the caption; video captions must be pasted manually. Choose music and review the caption, mentions, cover, and privacy in TikTok before posting.',
            'mixed' => 'This will submit the post to its connected accounts now. TikTok inbox targets require manual completion; other targets follow their selected publishing settings. Photo inbox uploads include the caption; video captions must be pasted manually. Choose music in TikTok.',
            default => 'This will submit the post to its connected accounts now using their selected publishing settings.',
        };
    }

    public function submissionMessage(Post $post): string
    {
        return match ($this->submissionKind($post)) {
            'inbox' => 'TikTok inbox upload queued. Delivery is not yet confirmed, and this is not a live post. Photo uploads include the caption; video captions must be pasted manually. Music is chosen in TikTok.',
            'mixed' => 'Submission queued. TikTok inbox targets require manual completion; other targets follow their selected publishing settings. Check each target for its result.',
            default => 'Publishing started.',
        };
    }

    private function isPhoto(PostTarget $target): bool
    {
        foreach ($target->media_upload_state ?? [] as $entry) {
            if (is_array($entry) && data_get($entry, 'metadata.media_type') === 'PHOTO') {
                return true;
            }
            if (is_array($entry) && ! empty($entry['remote_ref'])) {
                return false;
            }
        }
        $selection = app(TargetMediaSelection::class)->resolve($target, $target->placements);
        $media = $target->post?->media ?? collect();
        if ($selection['explicit']) {
            $media = $media->whereIn('id', array_column($selection['placements'], 'post_media_id'));
        }

        return $media->isNotEmpty() && $media->every(fn (PostMedia $item): bool => $item->kind === 'image');
    }

    private function isInbox(PostTarget $target): bool
    {
        if ($target->platform !== Platform::TikTok
            || in_array($target->publicationStatus(), [PostTargetStatus::Published, PostTargetStatus::Completed, PostTargetStatus::Deleting, PostTargetStatus::Deleted], true)) {
            return false;
        }

        $state = $target->media_upload_state ?? [];
        if ((array_key_exists('_tiktok_provider', $state) && $state['_tiktok_provider'] !== 'native')
            || array_key_exists('_metricool', $state) || array_key_exists('_tiktok_accounts', $state)) {
            return false;
        }

        $savedInbox = false;
        foreach ($state as $key => $entry) {
            if (str_starts_with((string) $key, '_') || $key === 'publication' || ! is_array($entry)) {
                continue;
            }
            $mode = data_get($entry, 'metadata.publish_mode');
            if ($mode !== null && $mode !== 'inbox') {
                return false;
            }
            $savedInbox = $savedInbox || $mode === 'inbox';
        }

        if ($savedInbox || $target->publicationStatus() === PostTargetStatus::AwaitingAction) {
            return true;
        }

        return in_array($target->status, [PostTargetStatus::Pending, PostTargetStatus::Publishing], true)
            && (int) $target->attempts === 0 && $target->remote_id === null && ($target->remote_ids ?? []) === []
            && array_diff_key($state, ['_tiktok_provider' => true]) === []
            && $target->account !== null && $this->enabledFor($target->account);
    }

    private function submissionKind(Post $post): string
    {
        $post->loadMissing('targets.account');
        $targets = $post->targets->filter(fn (PostTarget $target): bool => $target->publicationStatus() === PostTargetStatus::Pending);
        $inbox = $targets->filter(fn (PostTarget $target): bool => $this->isInbox($target))->count();

        return $inbox === 0 ? 'publish' : ($inbox === $targets->count() ? 'inbox' : 'mixed');
    }
}
