<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PublishPrecheck;
use App\Services\Sync\SyncFanOutService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * A composer post already published to $source, plus a pipeline source->[dests].
 *
 * @param  list<ConnectedAccount>  $destinations
 * @return array{0: Post, 1: PostTarget}
 */
function publishedSourceWithPipeline(ConnectedAccount $source, array $destinations, string $workspaceId): array
{
    $pipeline = SyncPipeline::factory()->create([
        'workspace_id' => $workspaceId,
        'source_connected_account_id' => $source->id,
        'enabled' => true,
    ]);
    $pipeline->destinations()->attach(collect($destinations)->pluck('id')->all());

    $post = Post::factory()->create([
        'workspace_id' => $workspaceId,
        'origin' => PostOrigin::Composer->value,
        'status' => PostStatus::Published->value,
        'segments' => ['Hello world from the source platform'],
        'base_text' => 'Hello world from the source platform',
    ]);
    $target = PostTarget::create([
        'post_id' => $post->id,
        'connected_account_id' => $source->id,
        'platform' => $source->platform->value,
        'sections' => ['Hello world from the source platform'],
        'status' => PostTargetStatus::Published->value,
        'remote_id' => 'src-123',
    ]);

    return [$post, $target];
}

beforeEach(function () {
    Queue::fake();
    config(['sync.enabled' => true]);
});

test('fan-out creates one synced post with a target per destination and recomputed sections', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);

    $synced = Post::where('source_post_id', $post->id)->first();
    expect($synced)->not->toBeNull()
        ->and($synced->origin)->toBe(PostOrigin::Sync)
        ->and($synced->status)->toBe(PostStatus::Publishing)
        ->and($synced->targets)->toHaveCount(1)
        ->and($synced->targets->first()->connected_account_id)->toBe($dest->id)
        ->and($synced->targets->first()->sections)->not->toBeEmpty();
    Queue::assertPushed(PublishPostTarget::class);
});

test('fan-out excludes destinations already targeted by the source post', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    PostTarget::create([
        'post_id' => $post->id,
        'connected_account_id' => $dest->id,
        'platform' => $dest->platform->value,
        'sections' => ['Hello'],
        'status' => PostTargetStatus::Published->value,
    ]);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

test('fan-out is idempotent under repeated calls', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);
    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(1);
});

test('a synced post never triggers further fan-out', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $loopPipeline = SyncPipeline::factory()->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $dest->id,
        'enabled' => true,
    ]);
    $loopPipeline->destinations()->attach($source->id);

    $syncedPost = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'origin' => PostOrigin::Sync->value,
    ]);
    $syncedTarget = PostTarget::create([
        'post_id' => $syncedPost->id,
        'connected_account_id' => $dest->id,
        'platform' => $dest->platform->value,
        'sections' => ['x'],
        'status' => PostTargetStatus::Published->value,
        'remote_id' => 'dst-1',
    ]);

    app(SyncFanOutService::class)->fanOut($syncedTarget);

    expect(Post::where('origin', PostOrigin::Sync->value)->where('source_post_id', $syncedPost->id)->count())->toBe(0);
});

test('skip_sync suppresses fan-out', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $post->update(['skip_sync' => true]);

    app(SyncFanOutService::class)->fanOut($target->fresh());

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

test('fan-out is inert when sync.enabled is false', function () {
    config(['sync.enabled' => false]);
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

test('copied media files are cleaned up when a later fan-out step fails', function () {
    Storage::fake('public');
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    $mediaPath = 'media/source.jpg';
    Storage::disk('public')->put($mediaPath, 'jpeg-bytes');
    PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'post_id' => $post->id,
        'disk' => 'public',
        'path' => $mediaPath,
    ]);

    // Blow up after copyMediaInto has already written the copied file.
    $this->mock(DraftService::class, function ($mock) {
        $mock->shouldReceive('syncTargets')->andThrow(new RuntimeException('boom'));
    });

    expect(fn () => app(SyncFanOutService::class)->fanOut($target))
        ->toThrow(RuntimeException::class);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([$mediaPath]);
});

test('disabled destination accounts are excluded', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->disabled()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

test('fan-out copies the source target caption and selected media rather than shared composer content', function () {
    Storage::fake('public');
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $target->update(['sections' => ['The account-specific public caption'], 'placements_explicit' => true]);

    foreach (['excluded.jpg', 'second.jpg', 'first.jpg'] as $position => $path) {
        Storage::disk('public')->put($path, $path);
        $media[$path] = PostMedia::factory()->create([
            'workspace_id' => $workspace->id, 'post_id' => $post->id,
            'disk' => 'public', 'path' => $path, 'position' => $position,
        ]);
    }
    foreach (['first.jpg', 'second.jpg'] as $position => $path) {
        $target->placements()->create([
            'post_media_id' => $media[$path]->id, 'segment_ref' => 'head', 'position' => $position,
        ]);
    }

    app(SyncFanOutService::class)->fanOut($target);

    $synced = Post::where('source_post_id', $post->id)->sole();
    expect($synced->base_text)->toBe('The account-specific public caption')
        ->and($synced->targets->sole()->sections)->toBe(['The account-specific public caption'])
        ->and($synced->media)->toHaveCount(2)
        ->and($synced->media->map(fn (PostMedia $media): string => Storage::disk('public')->get($media->path))->all())
        ->toBe(['first.jpg', 'second.jpg']);
});

test('fan-out respects an explicitly empty source media selection', function () {
    Storage::fake('public');
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $target->update(['placements_explicit' => true]);
    Storage::disk('public')->put('excluded.jpg', 'excluded');
    PostMedia::factory()->create([
        'workspace_id' => $workspace->id, 'post_id' => $post->id,
        'disk' => 'public', 'path' => 'excluded.jpg',
    ]);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->sole()->media)->toHaveCount(0);
});

test('fan-out does not turn an inbox or private completion into a public cross-post', function (PostTargetStatus $status) {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::TikTok]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $target->update(['status' => $status]);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([PostTargetStatus::AwaitingAction, PostTargetStatus::Completed, PostTargetStatus::Deleted]);

test('legacy published private YouTube rows cannot trigger cross-platform sync', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::YouTube]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $target->update(['media_upload_state' => ['video' => ['metadata' => ['privacy_status' => 'private']]]]);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('fan-out refuses destinations belonging to another workspace', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $other = ConnectedAccount::factory()->linkedin()->create();
    [$post, $target] = publishedSourceWithPipeline($source, [$other], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('native media missing from an import fails visibly instead of publishing only its caption', function (string $kind) {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $post->update([
        'origin' => PostOrigin::External,
        'external_media' => [['url' => 'https://example.test/original', 'kind' => $kind]],
    ]);

    app(SyncFanOutService::class)->fanOut($target);

    $synced = Post::where('source_post_id', $post->id)->sole();
    expect($synced->status)->toBe(PostStatus::Failed)
        ->and($synced->targets->sole()->error_message)->toContain('native source media could not be imported completely')
        ->and($synced->targets->sole()->error_message)->toContain('Duplicate this post');
    Queue::assertNothingPushed();

    $issues = app(PublishPrecheck::class)->blockingTargets($synced);
    expect($issues[0]['issues'])->toContain('sync_source_media_unavailable');
})->with(['video', 'image', 'unsupported']);

test('deletion while a source media copy is running prevents dispatch', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $this->mock(PostDuplicator::class, function ($mock) use ($post) {
        $mock->shouldReceive('copyMediaInto')->once()->andReturnUsing(function () use ($post) {
            $post->update(['status' => PostStatus::Deleted]);
        });
    });

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('an unsupported native media reference cannot pass validation with just its downloaded cover', function () {
    Storage::fake('public');
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $post->update(['origin' => PostOrigin::External, 'external_media' => [['url' => 'https://cdn/cover.jpg', 'kind' => 'unsupported']]]);
    Storage::disk('public')->put('cover.jpg', 'cover');
    PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => $post->id, 'disk' => 'public', 'path' => 'cover.jpg']);

    app(SyncFanOutService::class)->fanOut($target);

    $synced = Post::where('source_post_id', $post->id)->sole();
    expect($synced->media)->toHaveCount(1)
        ->and($synced->status)->toBe(PostStatus::Failed)
        ->and($synced->targets->sole()->error_message)->toContain('native source media could not be imported completely');
    Queue::assertNothingPushed();
});
