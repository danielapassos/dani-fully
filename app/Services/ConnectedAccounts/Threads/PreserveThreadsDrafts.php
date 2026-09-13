<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\Threads;

use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\DraftService;

class PreserveThreadsDrafts
{
    public function __construct(private readonly DraftService $drafts) {}

    public function preserve(ConnectedAccount $account): void
    {
        $targets = PostTarget::query()->where('connected_account_id', $account->id)->lockForUpdate()->get();

        foreach ($targets as $target) {
            $source = Post::withoutGlobalScope('workspace')->whereKey($target->post_id)->lockForUpdate()->firstOrFail();

            if ($source->deleted_at !== null || $source->status === PostStatus::Deleted) {
                continue;
            }

            $segments = $this->segments($target);
            $sourceSegments = $source->segments ?: [$source->base_text];

            if ($segments === [] || $segments === $sourceSegments || implode('', $segments) === '') {
                continue;
            }

            $this->drafts->createDraft(
                $source->workspace_id,
                $source->author()->firstOrFail(),
                ['kind' => 'none'],
                $segments,
                $source->mentions ?? [],
                autoRepost: false,
            );
        }
    }

    /** @return list<string> */
    private function segments(PostTarget $target): array
    {
        $override = $target->content_override;
        $segments = $override['segments'] ?? null;

        if (is_array($segments) && array_all($segments, static fn (mixed $segment): bool => is_string($segment))) {
            return array_values($segments);
        }

        if (is_string($override['text'] ?? null)) {
            return [$override['text']];
        }

        return $target->sections;
    }
}
