<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\ManualRetryEligibility;
use App\Services\Publishing\PostStatusRollup;

final class PostListItem
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Post $post): array
    {
        $retryEligibility = app(ManualRetryEligibility::class);
        $status = app(PostStatusRollup::class)->displayStatus($post);
        $nonPublicOnly = $post->targets->contains(fn (PostTarget $target): bool => in_array($target->publicationStatus(), [PostTargetStatus::AwaitingAction, PostTargetStatus::Completed], true))
            && ! $post->targets->contains(fn (PostTarget $target): bool => $target->publicationStatus() === PostTargetStatus::Published);

        return [
            'id' => $post->id,
            'base_text' => $post->base_text,
            'origin' => $post->origin->value,
            'source_post_id' => $post->source_post_id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'author' => $post->author?->name,
            'target_count' => $post->targets->count(),
            'media_count' => $post->media->count(),
            'media_preview' => self::mediaPreview($post),
            'updated_at' => $post->updated_at->toIso8601String(),
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $nonPublicOnly ? null : $post->published_at?->toIso8601String(),
            'platforms' => $post->targets->pluck('platform')
                ->map(fn ($p): string => $p->value)->unique()->values()->all(),
            'targets' => $post->targets->map(function (PostTarget $target) use ($post, $retryEligibility): array {
                $retry = $retryEligibility->evaluate($target, $post);
                $status = $target->publicationStatus();
                $projected = $status !== $target->status;

                return [
                    'id' => $target->id,
                    'platform' => $target->platform->value,
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
                ];
            })->all(),
        ];
    }

    /**
     * The first attachment, used for a glanceable list thumbnail. Videos live on
     * a private disk behind a signed, expiring URL that an `<img>` can't render,
     * so they carry no URL — the list shows an icon tile for them instead.
     *
     * @return array{kind: string, url: string|null}|null
     */
    private static function mediaPreview(Post $post): ?array
    {
        $first = $post->media->first();
        if (! $first instanceof PostMedia) {
            return null;
        }

        return [
            'kind' => $first->kind,
            'url' => $first->isVideo() ? null : $first->url(),
        ];
    }
}
