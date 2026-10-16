<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\UsageCategory;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Reads existing inbox operations only; this service never initializes or uploads media. */
class TikTokInboxReconciliation
{
    use TracksUsage;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly TokenManager $tokens,
        private readonly PostStatusRollup $rollup,
    ) {}

    public function eligible(PostTarget $target): bool
    {
        return $this->reference($target) !== null
            && in_array($target->publicationStatus(), [PostTargetStatus::Failed, PostTargetStatus::Skipped, PostTargetStatus::AwaitingAction, PostTargetStatus::Completed, PostTargetStatus::Published], true);
    }

    public function due(PostTarget $target, bool $manual = false): bool
    {
        $checked = data_get($target->media_upload_state, '_tiktok_reconciliation.checked_at');

        return $this->eligible($target) && (! is_string($checked)
            || CarbonImmutable::parse($checked)->lte(now()->subSeconds($manual ? 60 : 900)));
    }

    /** @return array{can_refresh: bool, status: string, message: string, checked_at: string|null, verified_at: string|null, error: string|null, public_posts: list<array{id: string, url: string, caption: string, created_at: int|null}>}|null */
    public function view(PostTarget $target): ?array
    {
        if ($this->reference($target) === null) {
            return null;
        }

        $record = $target->media_upload_state['_tiktok_reconciliation'] ?? [];

        return [
            'can_refresh' => $this->eligible($target) && $target->account !== null && ! $target->account->isDisabled(),
            'status' => $target->publicationStatus()->value,
            'message' => $record['error'] ?? $record['message'] ?? $target->publicationMessage() ?? ($target->status === PostTargetStatus::Failed
                ? 'TikTok accepted this upload, but delivery is unconfirmed. Check the original upload status before sending another copy.'
                : 'Check TikTok for public posts created from this upload.'),
            'checked_at' => $record['checked_at'] ?? null,
            'verified_at' => $record['verified_at'] ?? null,
            'error' => $record['error'] ?? null,
            'public_posts' => $record['public_posts'] ?? [],
        ];
    }

    public function reconcile(PostTarget $target, bool $manual = false): void
    {
        $lock = Cache::lock('tiktok-inbox-reconciliation:'.$target->id, 240);
        if (! $lock->get()) {
            return;
        }

        try {
            $target = $target->fresh();
            if ($target === null || ! $this->due($target, $manual)) {
                return;
            }
            $reference = $this->reference($target);
            $account = $target->account()->withoutGlobalScopes()->first();
            $post = $target->post()->withoutGlobalScopes()->first();
            if ($reference === null || $account === null || $account->isDisabled()
                || $post === null || $post->status === PostStatus::Deleted
                || $account->platform !== Platform::TikTok || $post->workspace_id !== $account->workspace_id) {
                return;
            }

            $rateKey = 'tiktok-inbox-reconciliation-account:'.$account->id;
            if (RateLimiter::tooManyAttempts($rateKey, 10)) {
                return;
            }
            RateLimiter::hit($rateKey, 60);
            $record = ['checked_at' => now()->toIso8601String(), 'error' => null];

            try {
                $token = (string) ($this->tokens->fresh($account)['access_token'] ?? '');
                if ($token === '') {
                    $this->saveEvidence($target, $reference, [...$record, 'error' => 'TikTok credentials are unavailable. Check the connected account.']);

                    return;
                }
                $response = $this->http->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5)
                    ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', ['publish_id' => $reference['publish_id']]);
                $this->meter(UsageCategory::ExternalApi, UsageOperation::MEDIA_STATUS_POLL, $account, $response);
                if (! $response->successful() || $response->json('error.code') !== 'ok') {
                    $this->saveEvidence($target, $reference, [...$record, 'error' => 'TikTok could not confirm this upload status. The original upload is retained; no new upload was sent.']);

                    return;
                }
                $status = $response->json('data.status');
                if (! in_array($status, ['SEND_TO_USER_INBOX', 'PUBLISH_COMPLETE', 'PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD', 'FAILED'], true)) {
                    $this->saveEvidence($target, $reference, [...$record, 'error' => 'TikTok returned an unrecognized upload status.']);

                    return;
                }
                $record['provider_status'] = $status;
                $record['message'] = match ($status) {
                    'SEND_TO_USER_INBOX' => 'TikTok reports that the notification was sent to your inbox. Open TikTok to finish posting; do not send another copy just to refresh the notification.',
                    'PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD' => 'TikTok is still processing the original upload. No replacement was sent; check its status again before sending another copy.',
                    default => 'TikTok confirms completion, but current public visibility has not been verified.',
                };
                if ($status === 'FAILED') {
                    $this->saveEvidence($target, $reference, [...$record, 'error' => 'TikTok reports that the original inbox upload failed. No replacement upload was sent.'], PostTargetStatus::Failed);

                    return;
                }
                if ($status !== 'PUBLISH_COMPLETE') {
                    $this->saveEvidence($target, $reference, $record, $status === 'SEND_TO_USER_INBOX' ? PostTargetStatus::AwaitingAction : null);

                    return;
                }

                $rawIds = $response->json('data.publicaly_available_post_id', []);
                if (! is_array($rawIds) || count($rawIds) > 20 || collect($rawIds)->contains(fn ($id): bool => ! (is_int($id) || is_string($id)) || preg_match('/\A[1-9][0-9]{0,19}\z/', (string) $id) !== 1)) {
                    $this->saveEvidence($target, $reference, [...$record, 'error' => 'TikTok returned invalid or too many public post identifiers; review this upload manually.'], PostTargetStatus::Completed);

                    return;
                }
                $ids = array_values(array_unique(array_map(strval(...), $rawIds)));
                sort($ids, SORT_STRING);
                $record['public_post_ids'] = $ids;
                if ($ids === []) {
                    $this->saveEvidence($target, $reference, $record, PostTargetStatus::Completed);

                    return;
                }

                // Completion is confirmed even if the separate ownership lookup times out.
                $this->saveEvidence($target, $reference, $record, PostTargetStatus::Completed);

                $videos = $this->http->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5)
                    ->post('https://open.tiktokapis.com/v2/video/query/?fields=id,share_url,video_description,create_time', ['filters' => ['video_ids' => $ids]]);
                $this->meter(UsageCategory::ExternalApi, UsageOperation::MEDIA_STATUS_POLL, $account, $videos);
                $rows = $videos->json('data.videos');
                if (! $videos->successful() || $videos->json('error.code') !== 'ok' || ! is_array($rows)) {
                    $this->saveEvidence($target, $reference, [...$record, 'error' => 'Public post IDs were returned, but ownership could not be verified. Check video.list access for this TikTok account.'], PostTargetStatus::Completed);

                    return;
                }
                $posts = [];
                foreach ($rows as $video) {
                    $id = is_array($video) && (is_string($video['id'] ?? null) || is_int($video['id'] ?? null)) ? (string) $video['id'] : '';
                    $url = is_array($video) ? ($video['share_url'] ?? null) : null;
                    if (! in_array($id, $ids, true) || ! $this->validPublicUrl($url, $id)) {
                        continue;
                    }
                    $posts[$id] = ['id' => $id, 'url' => $url, 'caption' => is_string($video['video_description'] ?? null) ? $video['video_description'] : '', 'created_at' => is_int($video['create_time'] ?? null) ? $video['create_time'] : null];
                }
                if (count($posts) !== count($ids) || count($rows) !== count($ids)) {
                    $this->saveEvidence($target, $reference, [...$record, 'error' => 'Not every public post could be verified on the connected TikTok account. No caption-based match was made.'], PostTargetStatus::Completed);

                    return;
                }
                ksort($posts, SORT_STRING);
                $this->saveEvidence($target, $reference, [...$record, 'public_posts' => array_values($posts), 'verified_at' => now()->toIso8601String(), 'message' => 'TikTok public publication verified from the original inbox upload.'], PostTargetStatus::Published);
            } catch (TokenRefreshException|ConnectionException) {
                $this->saveEvidence($target, $reference, [...$record, 'error' => 'TikTok could not be reached with the saved account credentials. The original upload is retained.']);
            }
        } finally {
            $lock->release();
        }
    }

    /** @return array{media_key: string, publish_id: string, remote_account_id: string}|null */
    private function reference(PostTarget $target): ?array
    {
        $state = $target->media_upload_state ?? [];
        if ($target->platform !== Platform::TikTok || in_array($target->status, [PostTargetStatus::Deleting, PostTargetStatus::Deleted], true)
            || (array_key_exists('_tiktok_provider', $state) && $state['_tiktok_provider'] !== 'native')
            || array_key_exists('_metricool', $state) || array_key_exists('_tiktok_accounts', $state)) {
            return null;
        }
        $account = $target->account()->withoutGlobalScopes()->first();
        if ($account === null || $account->remote_account_id === '') {
            return null;
        }
        $references = [];
        foreach ($state as $key => $entry) {
            if (str_starts_with((string) $key, '_') || $key === 'publication' || ! is_array($entry)) {
                continue;
            }
            if (data_get($entry, 'metadata.media_type') === 'PHOTO') {
                if (data_get($entry, 'metadata.connected_account_id') !== $target->connected_account_id
                    || data_get($entry, 'metadata.remote_account_id') !== $account->remote_account_id) {
                    return null;
                }
            }
            $mode = data_get($entry, 'metadata.publish_mode');
            if ($mode !== null && $mode !== 'inbox') {
                return null;
            }
            $id = $entry['remote_ref'] ?? null;
            if (is_string($id) && $id !== '' && ($mode === 'inbox' || str_starts_with($id, 'v_inbox_'))) {
                $references[] = ['media_key' => (string) $key, 'publish_id' => $id, 'remote_account_id' => $account->remote_account_id];
            }
        }

        return count($references) === 1 ? $references[0] : null;
    }

    private function validPublicUrl(mixed $url, string $id): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['host'] ?? null, ['www.tiktok.com', 'tiktok.com'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && preg_match('~\A/@[^/]+/(?:video|photo)/'.preg_quote($id, '~').'/?\z~', $parts['path'] ?? '') === 1;
    }

    /** @param array{media_key: string, publish_id: string, remote_account_id: string} $reference
     * @param  array<string, mixed>  $record
     */
    private function saveEvidence(PostTarget $target, array $reference, array $record, ?PostTargetStatus $status = null): void
    {
        DB::transaction(function () use ($target, $reference, $record, $status): void {
            $post = Post::withoutGlobalScopes()->lockForUpdate()->find($target->post_id);
            if ($post === null || $post->status === PostStatus::Deleted) {
                return;
            }
            $account = ConnectedAccount::withoutGlobalScopes()->lockForUpdate()->find($target->connected_account_id);
            if ($account === null || $account->isDisabled() || $account->workspace_id !== $post->workspace_id
                || $account->remote_account_id !== $reference['remote_account_id']) {
                return;
            }
            $fresh = PostTarget::query()->where('post_id', $target->post_id)->where('connected_account_id', $target->connected_account_id)->lockForUpdate()->find($target->id);
            if ($fresh === null || ! $this->eligible($fresh) || $this->reference($fresh) !== $reference) {
                return;
            }
            // Late delivery responses cannot reverse confirmed completion or public evidence.
            $canUpdateStatus = match ($fresh->publicationStatus()) {
                PostTargetStatus::Published => $status === PostTargetStatus::Published,
                PostTargetStatus::Completed => in_array($status, [PostTargetStatus::Completed, PostTargetStatus::Published], true),
                default => true,
            };
            if (! $canUpdateStatus && isset($record['provider_status']) && $record['provider_status'] !== 'PUBLISH_COMPLETE') {
                unset($record['error'], $record['provider_status'], $record['message'], $record['public_post_ids']);
            }
            $state = $fresh->media_upload_state ?? [];
            if (isset($record['verified_at'])) {
                $record = [...$record, 'connected_account_id' => $fresh->connected_account_id,
                    'remote_account_id' => $reference['remote_account_id'], 'publish_id' => $reference['publish_id']];
            }
            $state['_tiktok_reconciliation'] = [...($state['_tiktok_reconciliation'] ?? []), ...$record];
            if (isset($record['provider_status'])) {
                $state[$reference['media_key']]['metadata']['provider_status'] = $record['provider_status'];
            }
            $attributes = ['media_upload_state' => $state];
            $verifiedPublishedAt = null;
            if ($status !== null && $canUpdateStatus) {
                $message = match ($status) {
                    PostTargetStatus::Published => 'TikTok public publication verified from the original inbox upload.',
                    PostTargetStatus::AwaitingAction => PostTarget::TIKTOK_INBOX_MESSAGE,
                    PostTargetStatus::Failed => $record['error'],
                    default => 'TikTok confirms completion, but public visibility has not been confirmed. It may be private or still awaiting moderation.',
                };
                $state['publication'] = ['status' => $status->value, 'message' => $message];
                $attributes = [...$attributes, 'media_upload_state' => $state, 'status' => $status, 'error_kind' => $status === PostTargetStatus::Failed ? ErrorKind::Unknown : null, 'error_message' => null, 'next_attempt_at' => null];
                if ($status === PostTargetStatus::Published) {
                    $ids = array_column($record['public_posts'], 'id');
                    $created = array_filter(array_column($record['public_posts'], 'created_at'), fn ($value): bool => is_int($value) && $value > 0 && $value <= now()->timestamp);
                    $verifiedPublishedAt = $created !== [] ? CarbonImmutable::createFromTimestamp(min($created)) : null;
                    $attributes = [...$attributes, 'remote_id' => $ids[0], 'remote_ids' => $ids, 'posted_at' => $fresh->posted_at ?? $verifiedPublishedAt ?? now()];
                }
            }
            $fresh->forceFill($attributes)->save();
            $this->rollup->recompute($post);
            if ($verifiedPublishedAt !== null && $post->published_at !== null) {
                foreach ($post->targets()->get() as $publishedTarget) {
                    $postedAt = $publishedTarget->is($fresh) ? $verifiedPublishedAt : $publishedTarget->posted_at;
                    if ($publishedTarget->publicationStatus() === PostTargetStatus::Published && $postedAt !== null && $postedAt->timestamp > 0 && $postedAt->lte(now()) && $postedAt->lt($post->published_at)) {
                        $post->published_at = $postedAt;
                    }
                }
                if ($post->isDirty('published_at')) {
                    $post->save();
                }
            }
        });
    }
}
