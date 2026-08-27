<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\ErrorKind;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\PublishPrecheck;
use Throwable;

class PublishDispatcher
{
    public const string NO_RUNNABLE_MESSAGE = 'This post has no pending targets to publish. Use Retry on an eligible failed or skipped target.';

    private const array RUNNABLE = [
        PostTargetStatus::Pending,
    ];

    public function __construct(
        private readonly PublishPrecheck $precheck,
        private readonly PostStatusRollup $rollup,
    ) {}

    /**
     * Fan out one publish job per runnable target, but first drop any target
     * the precheck would reject (empty, media-required, over-limit). Enforcing the
     * precheck HERE — the single choke point every publish path funnels through —
     * means a doomed target can never reach the platform API, whether it was
     * published from the composer, the API, the scheduler, or an MCP tool. The
     * interactive callers still precheck up front to return a 422 before flipping
     * the post to publishing; this is the backstop that covers the rest.
     */
    public function dispatchForPost(Post $post): void
    {
        $post->loadMissing(['targets.account', 'media']);

        $issuesByAccount = [];
        foreach ($this->blockingTargets($post) as $blocked) {
            $issuesByAccount[$blocked['connected_account_id']] = $blocked['issues'];
        }

        $statusChanged = false;
        foreach ($post->targets as $target) {
            if (! $this->targetIsRunnable($target)) {
                continue;
            }

            $issues = $issuesByAccount[(string) $target->connected_account_id] ?? null;
            if ($issues !== null) {
                $this->markBlocked($target, $issues);
                $statusChanged = true;

                continue;
            }

            $attempts = $target->attempts;
            $claimed = $this->claim($target);
            if ($claimed === null) {
                continue;
            }

            $statusChanged = true;

            try {
                PublishPostTarget::dispatch($claimed);
            } catch (Throwable $exception) {
                $this->restoreClaim($claimed, $attempts);

                throw $exception;
            }
        }

        if ($statusChanged) {
            $this->rollup->recompute($post);
        }
    }

    public function hasRunnableTargets(Post $post): bool
    {
        $post->loadMissing('targets');

        return $post->targets->contains(fn (PostTarget $target): bool => $this->targetIsRunnable($target));
    }

    /**
     * Precheck only targets the general publish action can actually dispatch.
     * Failed/skipped targets have their own guarded manual-retry flow.
     *
     * @return list<array{connected_account_id: string, handle: ?string, platform: string, issues: list<string>}>
     */
    public function blockingTargets(Post $post): array
    {
        $post->loadMissing(['targets.account', 'targets.placements', 'media']);
        $runnableAccounts = $post->targets
            ->filter(fn (PostTarget $target): bool => $this->targetIsRunnable($target))
            ->pluck('connected_account_id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true]);

        return array_values(array_filter(
            $this->precheck->blockingTargets($post),
            static fn (array $blocked): bool => $runnableAccounts->has($blocked['connected_account_id']),
        ));
    }

    private function targetIsRunnable(PostTarget $target): bool
    {
        return in_array($target->status, self::RUNNABLE, true);
    }

    /**
     * Atomically move a new target into the publishing chain. Persisting this
     * claim means two requests cannot seed two independent jobs from the same
     * Pending row, even when they both loaded the post before either dispatched.
     */
    private function claim(PostTarget $target): ?PostTarget
    {
        $claimed = PostTarget::query()
            ->whereKey($target->getKey())
            ->where('status', PostTargetStatus::Pending->value)
            ->update(['status' => PostTargetStatus::Publishing->value]);

        if ($claimed !== 1) {
            return null;
        }

        $target->status = PostTargetStatus::Publishing;

        return $target->fresh() ?? $target;
    }

    /**
     * If the queue rejects the job, put back only our untouched claim. A worker
     * that already incremented attempts or moved the target wins this race.
     */
    private function restoreClaim(PostTarget $target, int $attempts): void
    {
        $restored = PostTarget::query()
            ->whereKey($target->getKey())
            ->where('status', PostTargetStatus::Publishing->value)
            ->where('attempts', $attempts)
            ->update(['status' => PostTargetStatus::Pending->value]);

        if ($restored === 1) {
            $target->status = PostTargetStatus::Pending;
            $this->rollup->recompute($target->post()->firstOrFail());
        }
    }

    /**
     * Record a precheck-blocked target as a terminal validation failure instead of
     * dispatching a job the connector would only reject at the platform API.
     *
     * @param  list<string>  $issues
     */
    private function markBlocked(PostTarget $target, array $issues): void
    {
        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_kind' => ErrorKind::Validation->value,
            'error_message' => $this->precheck->describe($issues, $target->platform),
            'next_attempt_at' => null,
        ])->save();
    }
}
