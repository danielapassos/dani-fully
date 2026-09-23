<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
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

    /** @return array{kind: 'tiktok_inbox', caption: string, instructions: string}|null */
    public function forTarget(PostTarget $target): ?array
    {
        if (! $this->isInbox($target)) {
            return null;
        }

        $instructions = $target->publicationStatus() === PostTargetStatus::AwaitingAction
            ? 'Open the upload notification in your TikTok inbox to finish this video.'
            : 'After TikTok confirms delivery, open the upload notification in your TikTok inbox to finish this video.';

        return [
            'kind' => 'tiktok_inbox',
            'caption' => implode("\n\n", $target->sections ?? []),
            'instructions' => $instructions.' This is an inbox upload, not an item in TikTok Drafts. Shoutrrr sends only the video. Replace any prefilled caption with the saved caption below, then review mentions, cover, and privacy before posting manually in TikTok.',
        ];
    }

    public function confirmation(Post $post): string
    {
        return match ($this->submissionKind($post)) {
            'inbox' => 'This will upload the video to your TikTok inbox for manual completion. It will not publish a live TikTok post or transfer the caption. Replace any prefilled caption with the saved caption, then review mentions, cover, and privacy before posting in TikTok.',
            'mixed' => 'This will submit the post to its connected accounts now. TikTok inbox targets upload only the video and require manual completion; other targets follow their selected publishing settings. The TikTok caption must be pasted manually.',
            default => 'This will submit the post to its connected accounts now using their selected publishing settings.',
        };
    }

    public function submissionMessage(Post $post): string
    {
        return match ($this->submissionKind($post)) {
            'inbox' => 'TikTok inbox upload queued. Delivery is not yet confirmed, and this is not a live post. The saved caption must be pasted manually in TikTok.',
            'mixed' => 'Submission queued. TikTok inbox targets require manual completion; other targets follow their selected publishing settings. Check each target for its result.',
            default => 'Publishing started.',
        };
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
