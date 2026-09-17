<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TikTokPublishingRoute
{
    public const string DELETION_MESSAGE = 'Cancel or delete this TikTok post in Metricool or TikTok first. Shoutrrr cannot confirm remote deletion and will keep the publishing record.';

    public function usesMetricool(ConnectedAccount $account): bool
    {
        return $account->platform === Platform::TikTok
            && config('services.tiktok.publishing_provider', 'native') === 'metricool';
    }

    /** Read-only routing: a saved submission never changes provider with installation settings. */
    public function forTarget(PostTarget $target): string
    {
        if ($target->platform !== Platform::TikTok) {
            return 'native';
        }

        $state = $target->media_upload_state ?? [];
        $pinned = $state['_tiktok_provider'] ?? null;
        $metricool = array_key_exists('_metricool', $state);
        $native = false;
        foreach ($state as $key => $entry) {
            if (! str_starts_with((string) $key, '_') && $key !== 'publication' && is_array($entry) && $entry !== []) {
                $native = true;
            }
        }

        if (($pinned !== null && ! in_array($pinned, ['native', 'metricool'], true))
            || ($native && ($metricool || $pinned === 'metricool'))
            || ($metricool && $pinned === 'native')) {
            throw new RuntimeException('The saved TikTok publishing route is inconsistent. Reconcile the existing submission before retrying.');
        }

        if ($pinned !== null) {
            return $pinned;
        }
        if ($metricool) {
            return 'metricool';
        }
        if ($native || $target->remote_id !== null || ($target->remote_ids ?? []) !== []) {
            return 'native';
        }

        $provider = config('services.tiktok.publishing_provider', 'native');
        if (! in_array($provider, ['native', 'metricool'], true)) {
            throw new RuntimeException('Choose a supported TikTok publishing provider in the installation settings.');
        }

        return $provider;
    }

    public function pin(PostTarget $target): string
    {
        $provider = $this->forTarget($target);
        if ($target->platform === Platform::TikTok && ($target->media_upload_state['_tiktok_provider'] ?? null) === null) {
            $target->forceFill(['media_upload_state' => [...($target->media_upload_state ?? []), '_tiktok_provider' => $provider]])->save();
        }

        return $provider;
    }

    public function ready(ConnectedAccount $account): bool
    {
        return $this->unavailableReason($account) === null;
    }

    public function requiresProviderDeletion(PostTarget $target): bool
    {
        if ($target->platform !== Platform::TikTok) {
            return false;
        }
        try {
            if ($this->forTarget($target) !== 'metricool') {
                return false;
            }
        } catch (RuntimeException) {
            return true;
        }

        return $target->status === PostTargetStatus::Publishing
            || $this->hasAcceptedOperation($target)
            || $target->remote_id !== null || ($target->remote_ids ?? []) !== [];
    }

    public function hasAcceptedOperation(PostTarget $target): bool
    {
        $state = $target->media_upload_state['_metricool'] ?? null;

        return $target->platform === Platform::TikTok && is_array($state)
            && (array_key_exists('post_id', $state) || ($state['create_outcome_unknown'] ?? false) === true);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function withDeletionLock(Post $post, Closure $operation): mixed
    {
        return DB::transaction(function () use ($post, $operation) {
            $post->setRelation('targets', $post->targets()->orderBy('id')->lockForUpdate()->get());

            return $operation();
        });
    }

    /** Configuration readiness only; the provider verifies the live destination before submitting. */
    public function unavailableReason(ConnectedAccount $account): ?string
    {
        if ($account->platform !== Platform::TikTok) {
            return 'Choose a TikTok account for the Metricool publishing connection.';
        }
        if ($account->isDisabled()) {
            return 'This account is disabled. Re-enable it before posting.';
        }
        $workspace = config('services.metricool.workspace_id');
        if (! is_string($workspace) || $workspace === '' || $workspace !== $account->workspace_id) {
            return 'Configure the Metricool publishing connection for this workspace.';
        }
        $token = config('services.metricool.token');
        $userId = config('services.metricool.user_id');
        if (! is_string($token) || trim($token) === '' || ! $this->positiveId($userId)) {
            return 'Configure the Metricool publishing credentials on the server.';
        }
        $accounts = config('services.metricool.accounts', []);
        if (! is_array($accounts) || ! $this->positiveId($accounts[$account->id] ?? null)) {
            return "Map {$account->handle} to its Metricool brand before publishing.";
        }

        return null;
    }

    private function positiveId(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1);
    }
}
