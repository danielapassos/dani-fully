<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

class PostStatusRollup
{
    public function recompute(Post $post): void
    {
        $statuses = $post->targets()->get()->map(fn (PostTarget $target): PostTargetStatus => $target->publicationStatus());
        $status = $this->aggregate($statuses);
        $post->status = $status;

        $hasPublished = $statuses->contains(PostTargetStatus::Published);
        if ($hasPublished && $status !== PostStatus::Publishing && $post->published_at === null) {
            $post->published_at = Date::now();
        } elseif (! $hasPublished) {
            $post->published_at = null;
        }

        $post->save();
    }

    /** Project older confirmed inbox deliveries without changing rows during a read. */
    public function displayStatus(Post $post): PostStatus
    {
        if (in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Deleted], true)) {
            return $post->status;
        }

        $post->loadMissing('targets');
        $statuses = $post->targets->map(fn (PostTarget $target): PostTargetStatus => $target->publicationStatus());

        return $statuses->contains(fn (PostTargetStatus $status): bool => in_array($status, [PostTargetStatus::AwaitingAction, PostTargetStatus::Completed], true))
            ? $this->aggregate($statuses)
            : $post->status;
    }

    /** @param Collection<int, PostTargetStatus> $statuses */
    private function aggregate(Collection $statuses): PostStatus
    {

        $total = $statuses->count();
        $hasInFlight = $statuses->contains(fn (PostTargetStatus $s): bool => in_array($s, [PostTargetStatus::Pending, PostTargetStatus::Publishing], true));
        $published = $statuses->filter(fn (PostTargetStatus $s): bool => $s === PostTargetStatus::Published)->count();
        $failed = $statuses->filter(fn (PostTargetStatus $s): bool => $s === PostTargetStatus::Failed)->count();
        $skipped = $statuses->filter(fn (PostTargetStatus $s): bool => $s === PostTargetStatus::Skipped)->count();
        $completed = $statuses->filter(fn (PostTargetStatus $s): bool => $s === PostTargetStatus::Completed)->count();

        $allPublished = $total > 0 && $published === $total;
        $noneReached = $total > 0 && $published === 0 && ($failed + $skipped) === $total;

        return match (true) {
            $hasInFlight => PostStatus::Publishing,
            $statuses->contains(PostTargetStatus::AwaitingAction) => PostStatus::AwaitingAction,
            $allPublished => PostStatus::Published,
            $completed > 0 && $completed + $published === $total => PostStatus::Completed,
            $noneReached => PostStatus::Failed,
            default => PostStatus::Partial,
        };
    }
}
