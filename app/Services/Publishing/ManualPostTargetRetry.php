<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostTargetStatus;
use App\Exceptions\PostTargetRetryRejected;
use App\Jobs\PublishPostTarget;
use App\Models\PostTarget;
use App\Services\Posts\PublishPrecheck;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ManualPostTargetRetry
{
    public function __construct(
        private readonly PublishPrecheck $precheck,
        private readonly PostStatusRollup $rollup,
        private readonly ManualRetryEligibility $eligibility,
    ) {}

    /**
     * Claim one manually retryable target, after rerunning the same readiness
     * and content checks as a first publish. The row lock plus compare-and-set
     * update ensures concurrent retry requests can enqueue at most one job.
     *
     * @throws PostTargetRetryRejected
     */
    public function dispatch(PostTarget $target): PostTarget
    {
        $claim = DB::transaction(function () use ($target): array {
            $locked = PostTarget::query()
                ->whereKey($target->getKey())
                ->where('post_id', $target->post_id)
                ->lockForUpdate()
                ->firstOrFail();

            $post = $locked->post()
                ->with(['targets.account', 'targets.placements', 'media'])
                ->firstOrFail();

            $locked->loadMissing('account');
            $eligibility = $this->eligibility->evaluate($locked, $post);
            if (! $eligibility['allowed']) {
                throw new PostTargetRetryRejected($eligibility['reason'] ?? 'This target cannot be retried.');
            }

            $blocked = collect($this->precheck->blockingTargets($post))
                ->firstWhere('connected_account_id', (string) $locked->connected_account_id);

            if ($blocked !== null) {
                throw new PostTargetRetryRejected(
                    $this->precheck->describe($blocked['issues'], $locked->platform),
                );
            }

            $claim = PostTarget::query()
                ->whereKey($locked->getKey())
                ->where('post_id', $locked->post_id)
                ->where('status', $locked->status->value);

            $this->matchErrorKind($claim, $locked);

            $previous = [
                'status' => $locked->getRawOriginal('status'),
                'error_kind' => $locked->getRawOriginal('error_kind'),
                'error_message' => $locked->error_message,
                'next_attempt_at' => $locked->getRawOriginal('next_attempt_at'),
            ];

            $updated = $claim->update([
                'status' => PostTargetStatus::Pending->value,
                'error_kind' => null,
                'error_message' => null,
                'next_attempt_at' => null,
            ]);

            if ($updated !== 1) {
                throw new PostTargetRetryRejected('This target is already being retried.');
            }

            return [
                'target' => $locked->fresh() ?? $locked,
                'previous' => $previous,
            ];
        });

        /** @var PostTarget $claimed */
        $claimed = $claim['target'];

        try {
            $this->rollup->recompute($claimed->post()->firstOrFail());
            PublishPostTarget::dispatch($claimed);
        } catch (Throwable $exception) {
            $this->restoreClaim($claimed, $claim['previous']);

            throw $exception;
        }

        return $claimed->fresh() ?? $claimed;
    }

    /**
     * A queue outage after the atomic claim must not strand the target in
     * Pending without a job. Restore only the exact untouched claim; if a sync
     * worker already advanced it, the compare-and-set deliberately does nothing.
     *
     * @param  array{status: mixed, error_kind: mixed, error_message: string|null, next_attempt_at: mixed}  $previous
     */
    private function restoreClaim(PostTarget $claimed, array $previous): void
    {
        try {
            $restored = PostTarget::query()
                ->whereKey($claimed->getKey())
                ->where('status', PostTargetStatus::Pending->value)
                ->whereNull('error_kind')
                ->whereNull('error_message')
                ->whereNull('next_attempt_at')
                ->update($previous);

            if ($restored === 1) {
                $this->rollup->recompute($claimed->post()->firstOrFail());
            }
        } catch (Throwable $recoveryException) {
            report($recoveryException);
        }
    }

    /**
     * Include the original failure kind in the compare-and-set claim so a
     * concurrent state change cannot be overwritten, including a NULL kind.
     *
     * @param  Builder<PostTarget>  $query
     */
    private function matchErrorKind(Builder $query, PostTarget $target): void
    {
        if ($target->error_kind === null) {
            $query->whereNull('error_kind');

            return;
        }

        $query->where('error_kind', $target->error_kind->value);
    }
}
