<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Billing\WorkspaceSubscriptionGate;

final class ManualRetryEligibility
{
    public function __construct(private readonly WorkspaceSubscriptionGate $subscriptions) {}

    /**
     * Return the complete server-authoritative retry decision used by both the
     * mutation and every UI payload. This keeps Retry hidden while account,
     * subscription, or X-budget recovery is still required.
     *
     * @return array{allowed: bool, reason: string|null, recovery_kind: 'enable_account'|'reconnect'|'operator_configuration'|'billing'|null}
     */
    public function evaluate(PostTarget $target, Post $post): array
    {
        if (! $target->canRetryManually()) {
            return [
                'allowed' => false,
                'reason' => $target->manualRetryBlockedReason()
                    ?? 'Only failed or skipped targets can be retried.',
                'recovery_kind' => $target->manualRetryRecoveryKind(),
            ];
        }

        $workspace = $post->relationLoaded('workspace')
            ? $post->workspace
            : $post->workspace()->firstOrFail();

        if (! $this->subscriptions->canPublish($workspace)) {
            return [
                'allowed' => false,
                'reason' => 'Subscribe to publish this post.',
                'recovery_kind' => 'billing',
            ];
        }

        if ($target->platform === Platform::X && ! $this->subscriptions->canPublishX($workspace)) {
            return [
                'allowed' => false,
                'reason' => $this->subscriptions->remainingXPosts($workspace) > 0
                    ? 'Monthly X API budget exceeded. Upgrade or wait for the next billing period.'
                    : 'Monthly X publishing quota exceeded. Upgrade or wait for the next billing period.',
                'recovery_kind' => 'billing',
            ];
        }

        return ['allowed' => true, 'reason' => null, 'recovery_kind' => null];
    }
}
