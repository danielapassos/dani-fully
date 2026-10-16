<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;

final class PublicationProgress
{
    /** @return array{phase: string, label: string, message: string|null, live_verified: bool, receipt: array<string, mixed>|null} */
    public function view(PostTarget $target): array
    {
        $status = $target->publicationStatus();
        $receipt = $this->receipt($target);
        $live = $status === PostTargetStatus::Published && $receipt !== null
            && in_array($receipt['visibility'], ['public', 'published'], true);
        $state = $target->media_upload_state ?? [];
        $phase = match ($status) {
            PostTargetStatus::Published => $live ? 'live' : 'published_unverified',
            PostTargetStatus::Completed => 'completed',
            PostTargetStatus::Deleting => 'deleting',
            PostTargetStatus::Deleted => 'deleted',
            default => $this->activePhase($target, $status, $state),
        };
        $label = match ($phase) {
            'live' => 'Published · receipt verified',
            'published_unverified' => 'Published · not verified',
            'uncertain' => 'Outcome unconfirmed',
            'uploading' => 'Uploading',
            'uploaded' => 'Uploaded · awaiting processing',
            'processing' => 'Processing',
            'inbox' => 'In TikTok inbox',
            'completed' => $receipt !== null && in_array($receipt['visibility'], ['private', 'unlisted'], true)
                ? 'Completed · '.$receipt['visibility'] : 'Completed · visibility unconfirmed',
            'action_required' => 'Action needed',
            'pending' => 'Queued',
            default => ucfirst($phase),
        };
        $message = match ($phase) {
            'live' => 'The provider verified this post and destination at the receipt time. Visibility can change later.',
            'published_unverified' => 'Publishing was recorded, but a verified live receipt is not available. Check the destination before posting again.',
            'uncertain' => 'The provider outcome is unconfirmed. Reconcile the existing submission before sending another copy.',
            'uploaded' => 'The media upload completed. Provider processing, inbox delivery and public visibility are not yet confirmed.',
            'uploading', 'processing' => $target->error_message ?? 'The existing submission is still in progress. Public visibility is not confirmed.',
            default => $target->publicationMessage($status),
        };

        return ['phase' => $phase, 'label' => $label, 'message' => $message, 'live_verified' => $live, 'receipt' => $receipt];
    }

    /** @param array<string, mixed> $state */
    private function activePhase(PostTarget $target, PostTargetStatus $status, array $state): string
    {
        if ($target->error_kind === ErrorKind::Unknown || $this->hasUnknownOutcome($state)) {
            return 'uncertain';
        }
        if ($status === PostTargetStatus::AwaitingAction) {
            $nativeInbox = $target->platform === Platform::TikTok
                && ! isset($state['_metricool']) && ! isset($state['_tiktok_accounts'])
                && ($state['_tiktok_provider'] ?? 'native') === 'native';

            return $nativeInbox ? 'inbox' : 'action_required';
        }
        if ($status !== PostTargetStatus::Publishing) {
            return $status->value;
        }
        $uploaded = false;
        foreach ($state as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
            if (isset($metadata['uploaded_bytes'], $metadata['total_bytes'])
                && $metadata['uploaded_bytes'] < $metadata['total_bytes']) {
                return 'uploading';
            }
            $uploaded = $uploaded || ($metadata['upload_complete'] ?? false) === true;
        }
        if ($target->error_kind === ErrorKind::MediaProcessing) {
            return 'processing';
        }

        return $uploaded ? 'uploaded' : 'publishing';
    }

    /** @param array<string, mixed> $state */
    private function hasUnknownOutcome(array $state): bool
    {
        foreach ($state as $key => $value) {
            if (in_array($key, ['outcome_unknown', 'init_outcome_unknown', 'publish_outcome_unknown', 'create_outcome_unknown'], true) && $value === true) {
                return true;
            }
            if (is_array($value) && $this->hasUnknownOutcome($value)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function receipt(PostTarget $target): ?array
    {
        if (! in_array($target->publicationStatus(), [PostTargetStatus::Published, PostTargetStatus::Completed], true)) {
            return null;
        }
        $receipt = data_get($target->media_upload_state, 'publication.receipt');
        if (! is_array($receipt) && $target->platform === Platform::TikTok) {
            $record = $target->media_upload_state['_tiktok_reconciliation'] ?? [];
            $bindingMatches = ($record['connected_account_id'] ?? null) === $target->connected_account_id
                && ($record['remote_account_id'] ?? null) === $target->account?->remote_account_id
                && is_string($record['publish_id'] ?? null) && $record['publish_id'] !== '';
            $hasOriginalOperation = false;
            foreach ($target->media_upload_state ?? [] as $entry) {
                if (is_array($entry) && ($entry['remote_ref'] ?? null) === ($record['publish_id'] ?? null)
                    && is_string($entry['remote_ref'] ?? null)) {
                    $hasOriginalOperation = true;
                }
            }
            foreach ($bindingMatches && $hasOriginalOperation ? ($record['public_posts'] ?? []) : [] as $post) {
                if (($post['id'] ?? null) === $target->remote_id) {
                    $receipt = ['remote_id' => $post['id'], 'remote_account_id' => $record['remote_account_id'],
                        'url' => $post['url'] ?? null, 'verified_at' => $record['verified_at'] ?? null, 'visibility' => 'public'];
                    break;
                }
            }
        }
        if (! is_array($receipt) || ! is_string($receipt['remote_id'] ?? null) || $receipt['remote_id'] === ''
            || $receipt['remote_id'] !== $target->remote_id
            || ! is_string($receipt['remote_account_id'] ?? null) || $receipt['remote_account_id'] === ''
            || $receipt['remote_account_id'] !== $target->account?->remote_account_id
            || ! is_string($receipt['verified_at'] ?? null)
            || ($verifiedAt = strtotime($receipt['verified_at'])) === false || $verifiedAt > now()->timestamp
            || ! in_array($receipt['visibility'] ?? null, ['public', 'published', 'private', 'unlisted'], true)
            || ! $this->validReceiptUrl($target->platform, $receipt['url'] ?? null, $receipt['remote_id'])) {
            return null;
        }

        return array_intersect_key($receipt, array_flip(['remote_id', 'remote_account_id', 'url', 'verified_at', 'visibility']));
    }

    private function validReceiptUrl(Platform $platform, mixed $url, string $id): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        return match ($platform) {
            Platform::Instagram => in_array($host, ['www.instagram.com', 'instagram.com'], true)
                && preg_match('~^/(?:(?:p|reel|reels)/[A-Za-z0-9_-]+|stories/[^/]+/[0-9]+)/?$~', $path) === 1,
            Platform::YouTube => $host === 'www.youtube.com' && $path === '/watch' && ($parts['query'] ?? '') === 'v='.$id,
            Platform::TikTok => in_array($host, ['www.tiktok.com', 'tiktok.com'], true) && preg_match('~^/@[^/]+/(?:video|photo)/'.preg_quote($id, '~').'/?$~', $path) === 1,
            default => false,
        };
    }
}
