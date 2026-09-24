<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostDuplicator;
use App\Services\Publishing\PublishDispatcher;
use App\Services\Publishing\TargetMediaSelection;
use App\Support\InstanceSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SyncFanOutService
{
    public function __construct(
        private readonly PostDuplicator $duplicator,
        private readonly DraftService $drafts,
        private readonly PublishDispatcher $dispatcher,
    ) {}

    /**
     * Fan a just-published source target out to every enabled pipeline whose
     * source is that account. Idempotent per (source post, pipeline).
     */
    public function fanOut(PostTarget $sourceTarget): void
    {
        if (! config('sync.enabled')) {
            return;
        }

        $sourceTarget = $sourceTarget->fresh();
        if ($sourceTarget === null || $sourceTarget->publicationStatus() !== PostTargetStatus::Published) {
            return;
        }

        // Observing an inbox upload completed by its creator is status refresh,
        // never a new instruction to publish through a sync pipeline.
        if ($sourceTarget->platform === Platform::TikTok
            && data_get($sourceTarget->media_upload_state, '_tiktok_reconciliation.verified_at') !== null) {
            return;
        }

        $account = ConnectedAccount::withoutGlobalScopes()->find($sourceTarget->connected_account_id);
        if ($account === null || $account->isDisabled() || $account->status !== ConnectedAccountStatus::Active
            || ! app(InstanceSettings::class)->platformAvailable($account->platform)) {
            return;
        }

        $source = Post::withoutGlobalScopes()->find($sourceTarget->post_id);
        if ($source === null || $source->status === PostStatus::Deleted
            || $source->workspace_id !== $account->workspace_id || $source->origin === PostOrigin::Sync || $source->skip_sync) {
            return;
        }

        $source = $this->sourceForTarget($source, $sourceTarget);

        $pipelines = SyncPipeline::withoutGlobalScopes()
            ->where('workspace_id', $source->workspace_id)
            ->where('enabled', true)
            ->where('source_connected_account_id', $sourceTarget->connected_account_id)
            ->get();

        foreach ($pipelines as $pipeline) {
            $this->createSyncedPost($source, $pipeline);
        }
    }

    /**
     * Sync the content this account actually published. The shared composer may
     * contain other captions, covers or attachments intentionally excluded here.
     */
    private function sourceForTarget(Post $source, PostTarget $target): Post
    {
        $snapshot = clone $source;
        $snapshot->forceFill([
            'segments' => $target->sections,
            'base_text' => implode("\n\n", $target->sections),
            'mentions' => [],
        ]);

        $media = $source->media()->get();
        $selection = app(TargetMediaSelection::class)->resolve($target, $target->placements()->get());
        if ($selection['explicit']) {
            $byId = $media->keyBy('id');
            $selected = [];
            foreach ($selection['placements'] as $placement) {
                $item = $byId->get($placement['post_media_id']);
                if ($item === null || isset($selected[$item->id])) {
                    continue;
                }
                $copy = clone $item;
                $copy->position = count($selected);
                $selected[$item->id] = $copy;
            }
            $media = (new PostMedia)->newCollection(array_values($selected));
        }
        $snapshot->setRelation('media', $media);

        return $snapshot;
    }

    private function createSyncedPost(Post $source, SyncPipeline $pipeline): void
    {
        $alreadyTargeted = PostTarget::withoutGlobalScopes()
            ->where('post_id', $source->id)
            ->pluck('connected_account_id')
            ->all();

        $destinationIds = ConnectedAccount::withoutGlobalScopes()
            ->where('workspace_id', $source->workspace_id)
            ->whereIn('id', $pipeline->destinations()->pluck('connected_accounts.id')->all())
            ->whereNotIn('id', $alreadyTargeted)
            ->enabled()
            ->where('status', ConnectedAccountStatus::Active->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($destinationIds === []) {
            return;
        }

        // Claim the (source, pipeline) pair first so the unique index guards the
        // race between the immediate event and the reconcile backstop.
        try {
            $synced = Post::create([
                'workspace_id' => $source->workspace_id,
                'author_id' => $source->author_id,
                'origin' => PostOrigin::Sync->value,
                'sync_pipeline_id' => $pipeline->id,
                'source_post_id' => $source->id,
                'segments' => $source->segments,
                'base_text' => $source->base_text,
                'mentions' => $source->mentions,
                'external_media' => $source->origin === PostOrigin::External ? $source->external_media : null,
                'status' => PostStatus::Draft->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            return; // Another run already fanned this out.
        }

        // Track copied storage paths outside the transaction: a DB rollback drops the
        // media rows but leaves the physical files behind, so we delete them ourselves.
        /** @var list<array{0: string, 1: string}> $copiedPaths */
        $copiedPaths = [];
        try {
            DB::transaction(function () use ($synced, $source, $destinationIds, &$copiedPaths): void {
                $this->duplicator->copyMediaInto($synced, $source, $copiedPaths);
                // No DraftData => no per-segment placements; all media rides the head
                // section (SegmentMediaResolver falls back to [0 => allMedia]).
                $this->drafts->syncTargets($synced, array_values($destinationIds), $source->segments ?? [], [], [], $source->mentions ?? [], []);
                $synced->forceFill(['status' => PostStatus::Publishing->value])->save();
            });
        } catch (Throwable $e) {
            foreach ($copiedPaths as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            $synced->delete(); // Let the backstop retry a clean fan-out.

            throw $e;
        }

        // Media copies may take time. Serialize the final dispatch decision with
        // deletion of the source without holding its lock during storage I/O.
        $dispatched = DB::transaction(function () use ($source, $synced): bool {
            $currentSource = Post::withoutGlobalScopes()->lockForUpdate()->find($source->id);
            if ($currentSource === null || $currentSource->status === PostStatus::Deleted || $currentSource->skip_sync) {
                return false;
            }

            $this->dispatcher->dispatchForPost($synced->fresh(['targets', 'media']));

            return true;
        });

        if (! $dispatched) {
            foreach ($copiedPaths as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            $synced->delete();
        }
    }
}
