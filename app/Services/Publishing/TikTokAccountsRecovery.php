<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Exceptions\PostTargetRetryRejected;
use App\Jobs\PublishPostTarget;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\PublicMediaUrl;
use App\Services\Publishing\TikTokAccounts\TikTokAccountsClient;
use App\Support\FileStorage;
use Illuminate\Bus\UniqueLock;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class TikTokAccountsRecovery
{
    public const string REJECTION_CODE = 'unaudited_client_can_only_post_to_private_accounts';

    public function __construct(
        private readonly TikTokPublishingRoute $route,
        private readonly TikTokAccountsClient $client,
        private readonly TargetMediaSelection $selection,
        private readonly SegmentMediaResolver $mediaResolver,
    ) {}

    /** @return array<string, mixed> */
    public static function snapshot(PublishContext $context, PostMedia $media): array
    {
        return [
            'post_id' => $context->target->post_id,
            'connected_account_id' => $context->account->id,
            'remote_account_id' => $context->account->remote_account_id,
            'account_handle' => $context->account->handle,
            'workspace_id' => $context->account->workspace_id,
            'media_id' => $media->id,
            'media_source_version' => app(PublicMediaUrl::class)->tikTokSourceVersion($media),
            'media_updated_at' => $media->getRawOriginal('updated_at'),
            'sections' => $context->segments,
            'post_options' => $context->target->content_override['tiktok'] ?? [],
        ];
    }

    /** Prepare the same failed target for an explicit retry; never dispatch it. */
    public function recover(PostTarget $target): PostTarget
    {
        $job = new PublishPostTarget($target);
        $queuedLock = Cache::lock(UniqueLock::getKey($job), 180);
        $runningLock = Cache::lock((new WithoutOverlapping('publish-post-target:'.$target->id))->getLockKey($job), 180);

        if (! $queuedLock->get()) {
            throw new PostTargetRetryRejected('This target has queued publishing work. Wait for it to finish before recovery.');
        }

        try {
            if (! $runningLock->get()) {
                throw new PostTargetRetryRejected('This target has an active publishing lock. Wait for it to finish before recovery.');
            }

            try {
                return DB::transaction(fn (): PostTarget => $this->recoverLocked($target));
            } finally {
                $runningLock->release();
            }
        } finally {
            $queuedLock->release();
        }
    }

    private function recoverLocked(PostTarget $target): PostTarget
    {
        $locked = PostTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
        if ($locked->platform !== Platform::TikTok || $locked->status !== PostTargetStatus::Failed
            || $locked->publicationStatus() !== PostTargetStatus::Failed || $locked->error_kind === ErrorKind::Unknown
            || $locked->next_attempt_at !== null || $locked->posted_at !== null
            || $locked->remote_id !== null || ($locked->remote_ids ?? []) !== []
            || $locked->repost_remote_id !== null || $locked->reposted_at !== null) {
            throw new PostTargetRetryRejected('Only a terminal failed TikTok target with no accepted or uncertain publication can be recovered.');
        }

        if ($this->route->forTarget($locked) !== 'native') {
            throw new PostTargetRetryRejected('This target is not an unrecovered native TikTok submission. Its saved route was not changed.');
        }

        $attempts = $locked->attemptLogs()->orderByDesc('attempt_no')->lockForUpdate()->get();
        $latest = $attempts->first();
        if ($latest === null || $latest->attempt_no !== $locked->attempts || $attempts->count() !== $locked->attempts) {
            $this->missingEvidence();
        }
        foreach ($attempts as $attempt) {
            $response = json_decode($attempt->response_excerpt ?? '', true);
            if ($attempt->status !== 'failed' || $attempt->finished_at === null || $attempt->http_status !== 403
                || $attempt->error_kind === ErrorKind::Unknown || ! is_array($response)
                || ($response['code'] ?? null) !== self::REJECTION_CODE
                || ! is_string($response['log_id'] ?? null) || preg_match('/\A[a-z0-9]{10,80}\z/i', $response['log_id']) !== 1) {
                $this->missingEvidence();
            }
        }

        $post = $locked->post()->lockForUpdate()->firstOrFail();
        $account = $locked->account()->lockForUpdate()->firstOrFail();
        if ($account->platform !== Platform::TikTok || $account->workspace_id !== $post->workspace_id) {
            throw new PostTargetRetryRejected('The original TikTok account is unavailable or no longer belongs to this post workspace.');
        }
        if (! $this->route->usesAccountsApi($account)) {
            throw new PostTargetRetryRejected('Select the approved TikTok Accounts API installation before recovery.');
        }
        if (($reason = $this->route->unavailableReason($account)) !== null) {
            throw new PostTargetRetryRejected($reason);
        }

        $allMedia = array_values($post->media()->lockForUpdate()->get()->all());
        $selection = $this->selection->resolve($locked, $locked->placements()->lockForUpdate()->get());
        $context = new PublishContext($locked, $locked->sections, $allMedia, $account, [], $this->mediaResolver->resolve(
            $locked->sections, $locked->section_sources ?? [], $locked->segment_breaks ?? [],
            $selection['placements'], $allMedia, $selection['explicit'],
        ));
        $media = $context->effectiveMedia();
        if (count($media) !== 1 || ! $media[0]->isVideo() || $media[0]->workspace_id !== $post->workspace_id) {
            throw new PostTargetRetryRejected('The original single TikTok video must remain attached to this target.');
        }
        $video = $media[0];
        $state = $locked->media_upload_state ?? [];
        foreach ($state as $key => $value) {
            if ($key !== $video->id && $key !== '_tiktok_provider') {
                throw new PostTargetRetryRejected('The saved native state contains additional publishing history. Reconcile it before recovery.');
            }
        }
        $entry = $state[$video->id] ?? null;
        $metadata = is_array($entry) ? ($entry['metadata'] ?? null) : null;
        if (! is_array($entry) || array_diff(array_keys($entry), ['metadata']) !== [] || ! is_array($metadata)
            || array_diff(array_keys($metadata), ['publish_mode', 'post_options', 'source', 'privacy_level', 'init_outcome_unknown', 'init_rejection']) !== []
            || ($metadata['init_outcome_unknown'] ?? false) !== false
            || ($metadata['publish_mode'] ?? null) !== 'direct' || ($metadata['source'] ?? null) !== 'PULL_FROM_URL'
            || ($metadata['privacy_level'] ?? null) !== 'PUBLIC_TO_EVERYONE') {
            $this->missingEvidence();
        }
        $receipt = $metadata['init_rejection'] ?? null;
        $response = json_decode($latest->response_excerpt ?? '', true);
        if (! is_array($receipt) || ($receipt['code'] ?? null) !== self::REJECTION_CODE
            || ($receipt['http_status'] ?? null) !== 403 || ($receipt['log_id'] ?? null) !== ($response['log_id'] ?? null)) {
            $this->missingEvidence();
        }
        foreach (self::snapshot($context, $video) as $key => $value) {
            if (! array_key_exists($key, $receipt) || $receipt[$key] !== $value) {
                throw new PostTargetRetryRejected('The original account, caption, options, or video changed after rejection. Reconcile the original target before recovery.');
            }
        }
        if (($metadata['post_options'] ?? null) !== ($locked->content_override['tiktok'] ?? [])
            || ($locked->content_override['tiktok']['privacy_level'] ?? null) !== 'PUBLIC_TO_EVERYONE') {
            throw new PostTargetRetryRejected('Keep the original reviewed TikTok options and Everyone visibility before recovery.');
        }
        if (! FileStorage::disk($video->disk)->exists($video->path)
            || FileStorage::disk($video->disk)->size($video->path) !== (int) $video->size_bytes) {
            throw new PostTargetRetryRejected('The original video file is missing or changed. Restore and verify it before recovery.');
        }

        $binding = $this->client->binding($account);
        $creator = $binding['creator'];
        if (! in_array('PUBLIC_TO_EVERYONE', $creator['privacy_level_options'], true)) {
            throw new PostTargetRetryRejected('The verified TikTok Accounts API destination does not allow public publishing.');
        }

        $locked->forceFill(['media_upload_state' => [
            '_tiktok_provider' => 'accounts_api',
            '_tiktok_accounts' => [
                'open_id' => $binding['open_id'],
                'client_id' => $binding['client_id'],
                'expected_handle' => $binding['expected_handle'],
                'account_id' => $account->id,
                'workspace_id' => $account->workspace_id,
                'media_id' => $video->id,
                'source_version' => app(PublicMediaUrl::class)->tikTokSourceVersion($video),
                'caption' => implode("\n\n", $locked->sections),
                'post_options' => $locked->content_override['tiktok'],
            ],
            '_tiktok_accounts_recovery' => [
                'recovered_at' => now()->toIso8601String(),
                'attempt_id' => $latest->id,
                'previous_status' => $locked->status->value,
                'previous_error_kind' => $locked->error_kind?->value,
                'previous_error_message' => $locked->error_message,
                'previous_media_upload_state' => $state,
                'accounts_open_id' => $binding['open_id'],
            ],
        ]])->save();

        return $locked;
    }

    private function missingEvidence(): never
    {
        throw new PostTargetRetryRejected('Recovery requires a recorded HTTP 403 audit rejection with its request log and original account/video receipt, and no active or uncertain attempt. This older target lacks sufficient evidence; reconcile the original submission before changing its route. Nothing was reset.');
    }
}
