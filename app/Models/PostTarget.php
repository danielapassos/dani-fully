<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ErrorKind;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostTargetStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $post_id
 * @property string $connected_account_id
 * @property Platform $platform
 * @property list<string> $sections
 * @property list<string>|null $segment_breaks
 * @property list<int>|null $section_sources
 * @property bool $placements_explicit
 * @property array<string, mixed>|null $content_override
 * @property bool $auto_split
 * @property PostFormat $format
 * @property PostTargetStatus $status
 * @property string|null $remote_id
 * @property list<string>|null $remote_ids
 * @property ErrorKind|null $error_kind
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property string|null $idempotency_key
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable|null $reposted_at
 * @property string|null $repost_remote_id
 * @property array<string, mixed>|null $media_upload_state
 * @property int $likes
 * @property int $comments
 * @property int $reposts
 * @property int|null $impressions
 * @property CarbonImmutable|null $metrics_captured_at
 * @property MetricsStatus|null $metrics_status
 * @property int $metrics_unchanged_streak
 * @property CarbonImmutable|null $reply_fetched_at
 * @property int $reply_fetch_empty_streak
 */
#[Fillable([
    'post_id',
    'connected_account_id',
    'platform',
    'sections',
    'segment_breaks',
    'section_sources',
    'placements_explicit',
    'content_override',
    'auto_split',
    'format',
    'status',
    'remote_id',
    'remote_ids',
    'error_kind',
    'error_message',
    'attempts',
    'next_attempt_at',
    'idempotency_key',
    'posted_at',
    'reposted_at',
    'repost_remote_id',
    'media_upload_state',
    'likes',
    'comments',
    'reposts',
    'impressions',
    'metrics_captured_at',
    'metrics_status',
    'metrics_unchanged_streak',
    'reply_fetched_at',
    'reply_fetch_empty_streak',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory, HasUuids;

    public const string TIKTOK_INBOX_MESSAGE = 'Uploaded to your TikTok inbox. Open TikTok to finish posting; this is not live.';

    /** Read recorded provider evidence without sending another upload or changing the row. */
    public function publicationStatus(): PostTargetStatus
    {
        if (in_array($this->status, [PostTargetStatus::Deleting, PostTargetStatus::Deleted], true)) {
            return $this->status;
        }

        $recorded = data_get($this->media_upload_state, 'publication.status');
        if (in_array($recorded, [PostTargetStatus::AwaitingAction->value, PostTargetStatus::Completed->value], true)) {
            return PostTargetStatus::from($recorded);
        }

        if ($this->platform === Platform::TikTok && $this->status === PostTargetStatus::Publishing && $this->remote_id === null) {
            $hasReference = false;
            foreach ($this->media_upload_state ?? [] as $entry) {
                if (! is_array($entry) || ! is_string($entry['remote_ref'] ?? null) || $entry['remote_ref'] === '') {
                    continue;
                }

                $hasReference = true;
                if (data_get($entry, 'metadata.provider_status') === 'SEND_TO_USER_INBOX') {
                    return PostTargetStatus::AwaitingAction;
                }
                if (data_get($entry, 'metadata.provider_status') === 'PUBLISH_COMPLETE') {
                    return PostTargetStatus::Completed;
                }
            }

            if ($hasReference) {
                $message = 'Video sent to TikTok. Open TikTok to finish the native post.';
                $inboxConfirmed = ($this->error_kind === ErrorKind::MediaProcessing && $this->error_message === $message)
                    || $this->attemptLogs()->where('error_kind', ErrorKind::MediaProcessing->value)->where('error_message', $message)->exists();
                if ($inboxConfirmed) {
                    return PostTargetStatus::AwaitingAction;
                }
            }
        }

        if ($this->platform === Platform::YouTube && $this->status === PostTargetStatus::Published) {
            foreach ($this->media_upload_state ?? [] as $entry) {
                if (is_array($entry) && in_array(data_get($entry, 'metadata.privacy_status'), ['private', 'unlisted'], true)) {
                    return PostTargetStatus::Completed;
                }
            }
        }

        return $this->status;
    }

    public function publicationMessage(?PostTargetStatus $status = null): ?string
    {
        $message = data_get($this->media_upload_state, 'publication.message');
        if (is_string($message) && $message !== '') {
            return $message;
        }

        $status ??= $this->publicationStatus();
        if ($status === PostTargetStatus::AwaitingAction) {
            return $this->platform === Platform::TikTok ? self::TIKTOK_INBOX_MESSAGE : 'Complete the remaining step on the connected platform.';
        }

        if ($status === PostTargetStatus::Completed) {
            if ($this->platform === Platform::YouTube) {
                foreach ($this->media_upload_state ?? [] as $entry) {
                    $privacy = is_array($entry) ? data_get($entry, 'metadata.privacy_status') : null;
                    if (in_array($privacy, ['private', 'unlisted'], true)) {
                        return "Uploaded to YouTube as {$privacy}. This video is not publicly listed.";
                    }
                }
            }

            return 'Upload complete. A public post has not been confirmed.';
        }

        return null;
    }

    /**
     * Whether this target may be manually dispatched again without risking a
     * duplicate provider-side post. Unknown failures include deliberately
     * persisted lost-response markers: the provider may already have accepted
     * the publish even though Shoutrrr did not receive its id.
     */
    public function canRetryManually(): bool
    {
        return $this->publicationStatus()->isRetryable()
            && $this->error_kind !== ErrorKind::Unknown
            && $this->account?->canPublish() === true;
    }

    /**
     * Explain the only retry block that needs a human decision rather than a
     * different target status.
     */
    public function manualRetryBlockedReason(): ?string
    {
        if ($this->status->isRetryable() && $this->error_kind === ErrorKind::Unknown) {
            return 'The provider outcome is unconfirmed and may already be live. Check the connected platform before taking any further action.';
        }

        if ($this->status->isRetryable() && $this->error_kind !== ErrorKind::Unknown) {
            $account = $this->account;

            if ($account === null) {
                return 'The connected account is unavailable. Open Accounts before retrying.';
            }

            return $account->canPublish()
                ? null
                : ($account->publishingUnavailableReason()
                    ?? 'The connected account is unavailable. Open Accounts before retrying.');
        }

        return null;
    }

    /** @return 'enable_account'|'reconnect'|'operator_configuration'|null */
    public function manualRetryRecoveryKind(): ?string
    {
        if (! $this->status->isRetryable() || $this->error_kind === ErrorKind::Unknown) {
            return null;
        }

        return $this->account?->publishingRecoveryKind();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => PostTargetStatus::class,
            'sections' => 'array',
            'segment_breaks' => 'array',
            'section_sources' => 'array',
            'placements_explicit' => 'boolean',
            'content_override' => 'array',
            'auto_split' => 'boolean',
            'format' => PostFormat::class,
            'remote_ids' => 'array',
            'media_upload_state' => 'array',
            'error_kind' => ErrorKind::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'reposted_at' => 'immutable_datetime',
            'likes' => 'integer',
            'comments' => 'integer',
            'reposts' => 'integer',
            'impressions' => 'integer',
            'metrics_captured_at' => 'immutable_datetime',
            'metrics_status' => MetricsStatus::class,
            'metrics_unchanged_streak' => 'integer',
            'reply_fetched_at' => 'immutable_datetime',
            'reply_fetch_empty_streak' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    /**
     * @return HasMany<PostTargetAttempt, $this>
     */
    public function attemptLogs(): HasMany
    {
        return $this->hasMany(PostTargetAttempt::class, 'post_target_id');
    }

    /** @return HasMany<PostTargetMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(PostTargetMetric::class, 'post_target_id')->orderBy('captured_at');
    }

    /** @return HasMany<PostTargetReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(PostTargetReply::class, 'post_target_id');
    }

    /** @return HasMany<PostMediaPlacement, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(PostMediaPlacement::class, 'post_target_id')->orderBy('position');
    }
}
