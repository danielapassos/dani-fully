<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetAttempt;
use App\Notifications\AccountNeedsAttentionNotification;
use App\Notifications\PostPublishedNotification;
use App\Notifications\PublishFailedNotification;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\SegmentMediaResolver;
use App\Services\Publishing\TargetMediaSelection;
use App\Services\Publishing\TikTokPublishingRoute;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PublishPostTarget implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const int MAX_ATTEMPTS = 5;

    private const int MAX_MEDIA_POLLS = 40;

    /** Fallback delay (seconds) between transcode-status polls when the platform gives no hint. */
    private const int MEDIA_POLL_DELAY = 10;

    /**
     * Each publish is its own retry loop (self-dispatched delayed jobs), so the queue
     * worker must not also retry — `tries=1` keeps a transient throw from amplifying
     * into duplicate posts. Combined with the terminal-status guard in handle().
     */
    public int $tries = 1;

    /**
     * Generous so a large video upload to a platform (streamed inside the first run)
     * can finish. MUST stay below the queue connection's `retry_after` (see config/queue.php,
     * default 1200) or a slow run would be released to a second worker and double-post.
     */
    public int $timeout = 900;

    /** Keep duplicate pending jobs collapsed until the winning job starts. */
    public int $uniqueFor = 1260;

    private const array TERMINAL = [
        PostTargetStatus::Published,
        PostTargetStatus::AwaitingAction,
        PostTargetStatus::Completed,
        PostTargetStatus::Failed,
        PostTargetStatus::Skipped,
        PostTargetStatus::Deleting,
        PostTargetStatus::Deleted,
    ];

    private const array RUNNABLE = [
        PostTargetStatus::Pending,
        PostTargetStatus::Publishing,
    ];

    public function __construct(public PostTarget $target) {}

    public function uniqueId(): string
    {
        return (string) $this->target->getKey();
    }

    /**
     * One target may involve a long upload plus several delayed poll jobs. Keep
     * workers from mutating the same remote post concurrently; a duplicate job
     * is discarded because the target's persisted state remains authoritative.
     * Sync queues execute delayed self-dispatches inline, so they cannot use a
     * lock that the parent invocation still owns.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        if (config('queue.default') === 'sync') {
            return [];
        }

        return [
            (new WithoutOverlapping("publish-post-target:{$this->target->getKey()}"))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        PublishConnectorRegistry $registry,
        TokenManager $tokens,
        PostStatusRollup $rollup,
        BackoffSchedule $backoff,
        ?WorkspaceSubscriptionGate $subscriptions = null,
        ?InstanceSettings $settings = null,
    ): void {
        $subscriptions ??= app(WorkspaceSubscriptionGate::class);
        $settings ??= app(InstanceSettings::class);
        $target = $this->target->fresh();
        if ($target === null) {
            return;
        }
        $this->target = $target;
        $acceptedMetricool = app(TikTokPublishingRoute::class)->hasAcceptedOperation($target);

        if ($this->reconcileRecordedOutcome($target, $rollup)) {
            return;
        }

        // Guard against a stale delayed retry or a double dispatch firing after the
        // target already reached a terminal state: doing nothing keeps it a no-op.
        if (in_array($target->status, self::TERMINAL, true)) {
            return;
        }

        // Manual retries must first atomically move Failed/Skipped back to
        // Pending. A stale duplicate job must never turn a completed failure
        // into an implicit retry after the overlap lock has been released.
        if (! in_array($target->status, self::RUNNABLE, true)) {
            return;
        }

        if (! $acceptedMetricool && ! $settings->platformAvailable($target->platform)) {
            $target->forceFill([
                'status' => PostTargetStatus::Skipped->value,
                'error_kind' => null,
                'error_message' => "{$target->platform->label()} is disabled on this instance.",
                'next_attempt_at' => null,
            ])->save();

            $rollup->recompute($target->post()->firstOrFail());

            return;
        }

        $account = $target->account()->firstOrFail();
        $metricool = app(TikTokPublishingRoute::class)->forTarget($target) === 'metricool';

        if (! $acceptedMetricool && $account->isDisabled()) {
            $target->forceFill([
                'status' => PostTargetStatus::Skipped->value,
                'error_kind' => null,
                'error_message' => 'This account is disabled in the workspace.',
                'next_attempt_at' => null,
            ])->save();

            $rollup->recompute($target->post()->firstOrFail());

            return;
        }

        // Re-check provider flags and upload scopes at execution time. They can
        // change after the request was queued, and the connector must not be the
        // first place an unavailable publishing capability is discovered.
        $publishable = $metricool
            ? app(TikTokPublishingRoute::class)->ready($account)
            : $account->canPublish();
        if (($metricool || $account->status === ConnectedAccountStatus::Active) && ! $publishable
            && ! $acceptedMetricool && ! $this->canPollExistingTikTokTransfer($target, $account)) {
            $target->forceFill([
                'status' => PostTargetStatus::Skipped->value,
                'error_kind' => null,
                'error_message' => $account->publishingUnavailableReason() ?? 'This account is not ready to publish.',
                'next_attempt_at' => null,
            ])->save();

            $rollup->recompute($target->post()->firstOrFail());

            return;
        }

        $attempt = DB::transaction(function () use ($target): ?PostTargetAttempt {
            $current = PostTarget::query()->whereKey($target->id)->lockForUpdate()->first();
            if ($current === null || ! in_array($current->status, self::RUNNABLE, true)) {
                return null;
            }
            $target->forceFill([
                'status' => PostTargetStatus::Publishing->value,
                'attempts' => $target->attempts + 1,
                // Real duplicate-prevention relies on incremental remote_ids resume (spec §4.3)
                // plus the terminal-status guard above; idempotency_key is reserved for providers
                // that support an idempotency header (X/Bluesky/LinkedIn do not uniformly today).
                'idempotency_key' => $target->idempotency_key ?? (string) Str::uuid(),
            ])->save();

            return PostTargetAttempt::create([
                'post_target_id' => $target->id,
                'attempt_no' => $target->attempts,
                'status' => 'retrying',
                'started_at' => Date::now(),
            ]);
        });
        if ($attempt === null) {
            return;
        }

        $workspace = $target->post()->firstOrFail()->workspace()->firstOrFail();

        if (! $acceptedMetricool && ! $subscriptions->canPublish($workspace)) {
            $result = PublishResult::failure(
                ErrorKind::BillingRequired,
                'An active Shoutrrr subscription is required to publish posts.',
            );
        } elseif ($target->platform === Platform::X && ! $subscriptions->canPublishX($workspace)) {
            $result = PublishResult::failure(
                ErrorKind::BillingRequired,
                $subscriptions->remainingXPosts($workspace) > 0
                    ? 'Monthly X API budget exceeded. Upgrade or wait for the next billing period.'
                    : 'Monthly X publishing quota exceeded. Upgrade or wait for the next billing period.',
            );
        } elseif (! $metricool && $account->status === ConnectedAccountStatus::NeedsAttention) {
            $result = PublishResult::failure(
                ErrorKind::AuthExpired,
                "{$account->platform->label()} account needs attention. Reconnect it before publishing.",
            );
        } else {
            try {
                app(TikTokPublishingRoute::class)->pin($target);
                $credentials = $metricool ? [] : $tokens->fresh($account);
                $connector = $registry->for($target->platform);
                $result = $connector->publish($this->context($target, $credentials));

                // The proactive token sweep can rotate a still-valid access token
                // just after this job reads it. A resulting 401 means the request
                // was rejected before any side effect, so it is safe to force one
                // fresh credential exchange and retry the publish once (media that
                // did upload resumes from stored state) before declaring the
                // account needs attention.
                if (! $metricool && $result->errorKind === ErrorKind::AuthExpired) {
                    $credentials = $tokens->fresh($account, force: true);
                    $result = $connector->publish($this->context($target, $credentials));
                }
            } catch (TokenRefreshException $e) {
                $result = PublishResult::failure(ErrorKind::AuthExpired, $e->getMessage());
            }
        }

        if ($metricool && $result->errorKind === ErrorKind::AuthExpired) {
            $result = PublishResult::failure(
                ErrorKind::Unsupported,
                'The Metricool publishing connection needs attention. Check its server credentials and connected TikTok brand.',
                $result->httpStatus,
            );
        }

        if ($result->isSuccessful() && in_array($result->outcome, ['awaiting_action', 'completed'], true)) {
            $this->onNonPublicCompletion($target, $attempt, $result);
        } elseif ($result->isSuccessful()) {
            $this->onSuccess($target, $attempt, $result);
        } else {
            $this->onFailure($target, $attempt, $result, $backoff);
        }

        $rollup->recompute($target->post()->firstOrFail());
    }

    /**
     * Runs when the job fails — either an uncaught throw from handle() or, more
     * insidiously, an orphaned queue reservation: a worker that died mid-run
     * (deploy/OOM/crash) leaves its reserved message behind, the database queue
     * re-delivers it after `retry_after`, and because `tries=1` the redelivery is
     * rejected as "attempted too many times" BEFORE handle() (and its terminal-status
     * guard) can run. Rather than blindly bury the target as Failed, reconcile against
     * the segment ids already persisted mid-run (BlueskyPublishConnector saves each
     * uri before sending the next), so a post that actually went out — or partly did —
     * is not lost:
     *   - all segments posted  → mark Published (the run just died before recording it);
     *   - some segments posted → re-dispatch to resume the thread (bounded by MAX_ATTEMPTS);
     *   - nothing posted       → mark terminally Failed.
     *
     * A redelivery can land up to `retry_after` after the orphan was created, so the
     * target may already be terminal (a parallel retry finished it, the user deleted the
     * post, or the platform was frozen). Mirror handle()'s terminal guard first so we
     * never resurrect a Deleted post, re-notify a Published one, or clobber a Skipped one.
     */
    public function failed(Throwable $e): void
    {
        $target = $this->target->fresh() ?? $this->target;

        if ($this->reconcileRecordedOutcome($target, app(PostStatusRollup::class))) {
            return;
        }

        if (in_array($target->status, self::TERMINAL, true)) {
            return;
        }

        $segmentCount = count($target->sections ?? []);
        $postedCount = count($this->postedRemoteIds($target));

        if ($target->platform !== Platform::YouTube && $segmentCount > 0 && $postedCount >= $segmentCount) {
            $this->reconcilePublished($target);

            return;
        }

        if ($postedCount > 0 && $target->attempts < self::MAX_ATTEMPTS) {
            $this->resumePartial($target);

            return;
        }

        $this->markFailed($target, $e);
    }

    /**
     * The non-empty AT-URIs already persisted for this target, in segment order.
     *
     * @return list<string>
     */
    private function postedRemoteIds(PostTarget $target): array
    {
        return array_values(array_filter(
            $target->remote_ids ?? [],
            static fn (string $remoteId): bool => $remoteId !== '',
        ));
    }

    /**
     * A worker died after every segment was posted but before onSuccess recorded it.
     * Recover the target to Published so the author is not wrongly told it failed.
     */
    private function reconcilePublished(PostTarget $target): void
    {
        $remoteIds = $this->postedRemoteIds($target);

        $target->forceFill([
            'status' => PostTargetStatus::Published->value,
            'remote_id' => $remoteIds[0] ?? $target->remote_id,
            'remote_ids' => $remoteIds,
            'posted_at' => $target->posted_at ?? Date::now(),
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        $this->closeOpenAttempt($target, 'published');

        app(PostStatusRollup::class)->recompute($target->post()->firstOrFail());

        $this->notifyPublished($target);
    }

    /**
     * A worker died mid-thread. Re-dispatch so handle() resumes from the persisted
     * remote_ids (posting only the unsent segments) rather than abandoning a
     * half-published thread as Failed.
     */
    private function resumePartial(PostTarget $target): void
    {
        $this->closeOpenAttempt($target, 'retrying');

        $target->forceFill([
            'status' => PostTargetStatus::Publishing->value,
            'next_attempt_at' => Date::now(),
        ])->save();

        app(PostStatusRollup::class)->recompute($target->post()->firstOrFail());

        self::dispatch($target->fresh());
    }

    private function markFailed(PostTarget $target, Throwable $e): void
    {
        $this->closeOpenAttempt(
            $target,
            'failed',
            Str::limit($e->getMessage(), 1000),
            ErrorKind::Unknown,
        );

        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_kind' => ErrorKind::Unknown->value,
            'error_message' => Str::limit($e->getMessage(), 1000),
            'next_attempt_at' => null,
        ])->save();

        app(PostStatusRollup::class)->recompute($target->post()->firstOrFail());

        $this->notifyFailed($target, ErrorKind::Unknown);
    }

    /**
     * Close the currently-open attempt row (the one this dead run left unfinished).
     */
    private function closeOpenAttempt(
        PostTarget $target,
        string $status,
        ?string $errorMessage = null,
        ?ErrorKind $errorKind = null,
    ): void {
        $attempt = $target->attemptLogs()->whereNull('finished_at')->latest('id')->first();

        if ($attempt === null) {
            return;
        }

        $fields = ['status' => $status, 'finished_at' => Date::now()];

        if ($errorMessage !== null) {
            $fields['error_message'] = $errorMessage;
        }

        if ($errorKind !== null) {
            $fields['error_kind'] = $errorKind->value;
        }

        $attempt->forceFill($fields)->save();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function context(PostTarget $target, array $credentials): PublishContext
    {
        $post = $target->post()->firstOrFail();
        $media = array_values($post->media()->get()->all());

        $selection = app(TargetMediaSelection::class)->resolve(
            $target,
            $target->placements()->get(),
        );

        $mediaBySection = app(SegmentMediaResolver::class)->resolve(
            sections: $target->sections,
            sectionSources: $target->section_sources ?? [],
            segmentBreaks: $target->segment_breaks ?? [],
            placements: $selection['placements'],
            allMedia: $media,
            placementsExplicit: $selection['explicit'],
        );

        return new PublishContext(
            target: $target,
            segments: $target->sections,
            media: $media,
            account: $target->account()->firstOrFail(),
            credentials: $credentials,
            mediaBySection: $mediaBySection,
        );
    }

    /**
     * Notify the post author that a target published successfully.
     */
    public function notifyPublished(PostTarget $target): void
    {
        $author = $target->post()->firstOrFail()->author()->first();

        $author?->notify(new PostPublishedNotification($target));
    }

    /**
     * Notify the post author about a terminal failure. Auth-expiry routes to the
     * reconnect notification; everything else to the publish-failed notification.
     */
    public function notifyFailed(PostTarget $target, ErrorKind $kind): void
    {
        $post = $target->post()->firstOrFail();
        $author = $post->author()->first();

        if ($author === null) {
            return;
        }

        if ($kind === ErrorKind::AuthExpired) {
            $author->notify(new AccountNeedsAttentionNotification(
                $target->account()->firstOrFail(),
                $post->workspace_id,
            ));

            return;
        }

        $author->notify(new PublishFailedNotification($target));
    }

    private function onSuccess(PostTarget $target, PostTargetAttempt $attempt, PublishResult $result): void
    {
        $target->forceFill([
            'status' => PostTargetStatus::Published->value,
            'remote_id' => $result->remoteIds[0] ?? null,
            'remote_ids' => $result->remoteIds,
            'posted_at' => Date::now(),
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        $attempt->forceFill([
            'status' => 'published',
            'http_status' => $result->httpStatus,
            'finished_at' => Date::now(),
        ])->save();

        $this->notifyPublished($target);
    }

    private function reconcileRecordedOutcome(PostTarget $target, PostStatusRollup $rollup): bool
    {
        $status = $target->publicationStatus();
        if ($status === $target->status || ! in_array($status, [PostTargetStatus::AwaitingAction, PostTargetStatus::Completed], true)) {
            return false;
        }

        $this->onNonPublicCompletion($target, null, new PublishResult(
            remoteIds: [],
            outcome: $status->value,
            statusMessage: $target->publicationMessage(),
        ));
        $rollup->recompute($target->post()->firstOrFail());

        return true;
    }

    private function canPollExistingTikTokTransfer(PostTarget $target, ConnectedAccount $account): bool
    {
        if ($target->platform !== Platform::TikTok) {
            return false;
        }

        $scopes = $account->capabilities['oauth_scopes'] ?? [];
        if (! is_array($scopes) || array_intersect(['video.upload', 'video.publish'], $scopes) === []) {
            return false;
        }

        foreach ($target->media_upload_state ?? [] as $entry) {
            if (is_array($entry) && is_string($entry['remote_ref'] ?? null) && $entry['remote_ref'] !== '') {
                return true;
            }
        }

        return false;
    }

    private function onNonPublicCompletion(PostTarget $target, ?PostTargetAttempt $attempt, PublishResult $result): void
    {
        $state = $target->media_upload_state ?? [];
        $state['publication'] = ['status' => $result->outcome, 'message' => $result->statusMessage];
        $existingIds = $target->remote_ids ?? array_filter([$target->remote_id]);
        $remoteIds = array_values(array_unique([...$existingIds, ...$result->remoteIds]));

        $target->forceFill([
            'status' => $result->outcome,
            'media_upload_state' => $state,
            'remote_id' => $remoteIds[0] ?? $target->remote_id,
            'remote_ids' => $remoteIds,
            'posted_at' => null,
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        if ($attempt !== null) {
            $attempt->forceFill([
                'status' => $result->outcome,
                'http_status' => $result->httpStatus,
                'finished_at' => Date::now(),
            ])->save();
        } else {
            $this->closeOpenAttempt($target, $result->outcome);
        }
    }

    private function onFailure(PostTarget $target, PostTargetAttempt $attempt, PublishResult $result, BackoffSchedule $backoff): void
    {
        if (($result->errorKind ?? null) === ErrorKind::MediaProcessing) {
            $this->onMediaProcessing($target, $attempt, $result);

            return;
        }

        $kind = $result->errorKind ?? ErrorKind::Unknown;
        $canRetry = $kind->isRetryable() && $target->attempts < self::MAX_ATTEMPTS;

        $attempt->forceFill([
            'status' => $canRetry ? 'retrying' : 'failed',
            'error_kind' => $kind->value,
            'error_message' => $result->errorMessage,
            'http_status' => $result->httpStatus,
            'response_excerpt' => $result->responseExcerpt,
            'finished_at' => Date::now(),
        ])->save();

        if ($canRetry) {
            $delay = $result->retryAfter ?? $backoff->nextDelaySeconds($target->attempts);

            $target->forceFill([
                'status' => PostTargetStatus::Publishing->value,
                'error_kind' => $kind->value,
                'error_message' => $result->errorMessage,
                'next_attempt_at' => Date::now()->addSeconds($delay),
            ])->save();

            self::dispatch($target->fresh())->delay($delay);

            return;
        }

        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_kind' => $kind->value,
            'error_message' => $result->errorMessage,
            'next_attempt_at' => null,
        ])->save();

        if ($kind === ErrorKind::AuthExpired) {
            $target->account()->firstOrFail()->forceFill([
                'status' => ConnectedAccountStatus::NeedsAttention->value,
            ])->save();
        }

        $this->notifyFailed($target, $kind);
    }

    private function onMediaProcessing(PostTarget $target, PostTargetAttempt $attempt, PublishResult $result): void
    {
        $state = new MediaUploadState($target->media_upload_state);
        $polls = $state->incrementPolls();

        $maxPolls = match ($target->platform) {
            // TikTok's creator-inbox flow intentionally waits for the human to
            // finish the post in TikTok; YouTube also advances one resumable
            // 8 MiB upload chunk per poll cycle. Neither fits the short Meta/X
            // transcode window used by the original connectors.
            Platform::TikTok => 1_440,
            Platform::YouTube => 512,
            default => self::MAX_MEDIA_POLLS,
        };

        if ($polls > $maxPolls) {
            Log::warning('Video transcode poll timed out', [
                'post_target_id' => $target->id,
                'platform' => $target->platform->value,
                'polls' => $polls,
            ]);

            $attempt->forceFill([
                'status' => 'failed',
                'error_kind' => ErrorKind::ServerError->value,
                'error_message' => 'Video processing did not complete in time.',
                'finished_at' => Date::now(),
            ])->save();

            $target->forceFill([
                'status' => PostTargetStatus::Failed->value,
                'error_kind' => ErrorKind::ServerError->value,
                'error_message' => 'Video processing did not complete in time.',
                'media_upload_state' => $state->toArray(),
                'next_attempt_at' => null,
            ])->save();

            $this->notifyFailed($target, ErrorKind::ServerError);

            return;
        }

        // Honor the platform's suggested delay; otherwise poll on a tight fixed cadence
        // (not the publish backoff, whose 60s base is far too slow for transcode checks).
        $delay = $result->retryAfter ?? self::MEDIA_POLL_DELAY;

        $attempt->forceFill([
            'status' => 'retrying',
            'error_kind' => ErrorKind::MediaProcessing->value,
            'error_message' => $result->errorMessage,
            'finished_at' => Date::now(),
        ])->save();

        $target->forceFill([
            'status' => PostTargetStatus::Publishing->value,
            // Transcode polls must not exhaust the publish-failure budget, so neutralize
            // the attempts++ that handle() applied at the start of this run.
            'attempts' => max(0, $target->attempts - 1),
            'media_upload_state' => $state->toArray(),
            'next_attempt_at' => Date::now()->addSeconds($delay),
        ])->save();

        self::dispatch($target->fresh())->delay($delay);
    }
}
