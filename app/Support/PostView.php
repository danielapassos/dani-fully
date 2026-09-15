<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\ManualRetryEligibility;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\TargetMediaSelection;

final class PostView
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Post $post): array
    {
        $post->loadMissing(['targets.account', 'targets.placements', 'media']);

        $mediaSelection = app(TargetMediaSelection::class);
        $retryEligibility = app(ManualRetryEligibility::class);
        $status = app(PostStatusRollup::class)->displayStatus($post);
        $nonPublicOnly = $post->targets->contains(fn (PostTarget $target): bool => in_array($target->publicationStatus(), [PostTargetStatus::AwaitingAction, PostTargetStatus::Completed], true))
            && ! $post->targets->contains(fn (PostTarget $target): bool => $target->publicationStatus() === PostTargetStatus::Published);
        $issuesByAccount = collect(app(PublishPrecheck::class)->blockingTargets($post))
            ->keyBy('connected_account_id');
        $defaultAccountId = $post->workspace()->value('default_connected_account_id');
        $defaultTarget = $post->targets
            ->sortByDesc(fn (PostTarget $target): bool => $target->connected_account_id === $defaultAccountId)
            ->first();

        return [
            'id' => $post->id,
            'base_text' => $post->base_text,
            'segments' => $post->segments,
            'mentions' => $post->mentions ?? [],
            'status' => $status->value,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'auto_repost' => $post->auto_repost,
            'published_at' => $nonPublicOnly ? null : $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at->toIso8601String(),
            'destination' => self::destination($post),
            'targets' => $post->targets
                ->sortByDesc(fn (PostTarget $target): bool => $target->connected_account_id === $defaultAccountId)
                ->map(function (PostTarget $target) use ($issuesByAccount, $mediaSelection, $post, $retryEligibility): array {
                    $selection = $mediaSelection->resolve($target, $target->placements);
                    $retry = $retryEligibility->evaluate($target, $post);
                    $status = $target->publicationStatus();
                    $projected = $status !== $target->status;

                    return [
                        'id' => $target->id,
                        'connected_account_id' => $target->connected_account_id,
                        'platform' => $target->platform->value,
                        'handle' => $target->account?->handle,
                        'display_name' => $target->account?->display_name,
                        'avatar_url' => $target->account?->avatar_url,
                        'sections' => $target->sections,
                        'segment_breaks' => $target->segment_breaks ?? [],
                        'section_sources' => $target->section_sources ?? [],
                        'placements_explicit' => $selection['explicit'],
                        'placements' => array_map(static fn (array $placement): array => [
                            'media_id' => $placement['post_media_id'],
                            'segment_ref' => $placement['segment_ref'],
                            'position' => $placement['position'],
                        ], $selection['placements']),
                        'content_override' => $target->content_override,
                        'auto_split' => $target->auto_split,
                        'format' => $target->format->value,
                        'status' => $status->value,
                        'status_message' => $target->publicationMessage($status),
                        'error_kind' => $projected ? null : $target->error_kind?->value,
                        'error_message' => $projected ? null : $target->error_message,
                        'can_retry' => $retry['allowed'],
                        'retry_blocked_reason' => ! $retry['allowed'] && $target->status->isRetryable()
                            ? $retry['reason']
                            : null,
                        'retry_recovery_kind' => $retry['recovery_kind'],
                        'attempts' => $target->attempts,
                        'remote_id' => $target->remote_id,
                        'issues' => $issuesByAccount->get((string) $target->connected_account_id)['issues'] ?? [],
                    ];
                })->values()->all(),
            'media' => $post->media->map(fn (PostMedia $media): array => $media->toView())->values()->all(),
            'segment_breaks' => $defaultTarget?->segment_breaks ?? [], // @phpstan-ignore nullsafe.neverNull
            ...self::defaultPlacementView($defaultTarget, $mediaSelection),
        ];
    }

    /** @return array{placements_explicit: bool, placements: list<array{media_id: string, segment_ref: string, position: int}>} */
    private static function defaultPlacementView(?PostTarget $target, TargetMediaSelection $mediaSelection): array
    {
        if ($target === null) {
            return ['placements_explicit' => false, 'placements' => []];
        }

        $selection = $mediaSelection->resolve($target, $target->placements);

        return [
            'placements_explicit' => $selection['explicit'],
            'placements' => array_map(static fn (array $placement): array => [
                'media_id' => $placement['post_media_id'],
                'segment_ref' => $placement['segment_ref'],
                'position' => $placement['position'],
            ], $selection['placements']),
        ];
    }

    /**
     * @return array{kind: string, id: string|null, ids?: list<string>}
     */
    private static function destination(Post $post): array
    {
        if ($post->account_set_id !== null) {
            return ['kind' => 'set', 'id' => $post->account_set_id];
        }

        if ($post->targets->isEmpty()) {
            return ['kind' => 'none', 'id' => null];
        }

        if ($post->targets->count() === 1) {
            return ['kind' => 'account', 'id' => $post->targets->first()->connected_account_id];
        }

        $targetIds = $post->targets
            ->pluck('connected_account_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->sort()
            ->all();
        $allAccountIds = ConnectedAccount::withoutGlobalScopes()
            ->where('workspace_id', $post->workspace_id)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->sort()
            ->all();

        $targetIds = array_values($targetIds);
        $allAccountIds = array_values($allAccountIds);

        return $targetIds === $allAccountIds
            ? ['kind' => 'all', 'id' => null]
            : ['kind' => 'accounts', 'id' => null, 'ids' => $targetIds];
    }
}
