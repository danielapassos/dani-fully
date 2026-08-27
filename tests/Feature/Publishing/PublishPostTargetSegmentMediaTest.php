<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Jobs\PublishPostTarget;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;

/*
|--------------------------------------------------------------------------
| PublishPostTarget::context() wiring
|--------------------------------------------------------------------------
|
| Every other *SegmentMediaTest.php file hand-builds a PublishContext with
| mediaBySection injected directly, so none of them exercise the real,
| private PublishPostTarget::context() method: the code that loads
| PostMediaPlacement rows from the DB and feeds them through
| SegmentMediaResolver. These tests drive PublishPostTarget::handle() for
| real (via a capturing fake connector) to prove that pipeline — DB rows to
| resolver to PublishContext — is wired correctly end to end. They must NOT
| re-cover SegmentMediaResolverTest.php's unit coverage of the resolver's
| internal fallback/walk-back logic.
|
*/

test('handle() loads real placement rows and wires mediaBySection through the resolver', function (): void {
    $target = publishTarget(['First segment', '', 'Third segment, no media']);
    $post = $target->post()->firstOrFail();

    $mediaA = PostMedia::factory()->create(['post_id' => $post->id, 'workspace_id' => $post->workspace_id]);
    $mediaB = PostMedia::factory()->create(['post_id' => $post->id, 'workspace_id' => $post->workspace_id]);
    PostMedia::factory()->create(['post_id' => $post->id, 'workspace_id' => $post->workspace_id]);

    // Authored segments map 1:1 onto sections here (no auto-split), so section_sources
    // is the identity mapping and segment_breaks names the two non-head breaks — the
    // same shapes DraftService/PostSplitter produce for a real 3-part thread.
    $target->forceFill([
        'section_sources' => [0, 1, 2],
        'segment_breaks' => ['b1', 'b2'],
    ])->save();

    // Media A rides the head (authored segment 0, empty position ordering);
    // media B is placed on the second authored segment, which has no text of
    // its own — the media-only-segment case the review flagged as untested.
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $mediaA->id,
        'segment_ref' => '__head__',
        'position' => 0,
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $mediaB->id,
        'segment_ref' => 'b1',
        'position' => 0,
    ]);

    $captured = null;
    bindConnector(function (PublishContext $context) use (&$captured): PublishResult {
        $captured = $context;

        return PublishResult::success(['111', '222', '333']);
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($captured)->not->toBeNull();

    expect($captured->mediaForSection(0))->toHaveCount(1)
        ->and($captured->mediaForSection(0)[0]->id)->toBe($mediaA->id);

    expect($captured->mediaForSection(1))->toHaveCount(1)
        ->and($captured->mediaForSection(1)[0]->id)->toBe($mediaB->id);

    // The third segment has text but no placement targeting it.
    expect($captured->mediaForSection(2))->toBe([]);

    expect($captured->media)->toHaveCount(3);
    expect(array_map(fn (PostMedia $item): string => $item->id, $captured->effectiveMedia()))
        ->toBe([$mediaA->id, $mediaB->id]);
});

test('handle() with no placement rows falls back to all media on the first section', function (): void {
    $target = publishTarget(['Only segment']);
    $post = $target->post()->firstOrFail();

    $media = PostMedia::factory()->create(['post_id' => $post->id, 'workspace_id' => $post->workspace_id]);

    $captured = null;
    bindConnector(function (PublishContext $context) use (&$captured): PublishResult {
        $captured = $context;

        return PublishResult::success(['111']);
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($captured)->not->toBeNull();
    expect($captured->mediaForSection(0))->toHaveCount(1)
        ->and($captured->mediaForSection(0)[0]->id)->toBe($media->id);
});

test('handle() preserves an explicit empty placement set instead of restoring all media', function (): void {
    $target = publishTarget(['Text-only for this account']);
    $post = $target->post()->firstOrFail();
    PostMedia::factory()->create(['post_id' => $post->id, 'workspace_id' => $post->workspace_id]);
    $target->forceFill(['placements_explicit' => true])->save();

    $captured = null;
    bindConnector(function (PublishContext $context) use (&$captured): PublishResult {
        $captured = $context;

        return PublishResult::success(['111']);
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($captured)->not->toBeNull()
        ->and($captured->mediaForSection(0))->toBe([])
        ->and($captured->effectiveMedia())->toBe([])
        ->and($captured->media)->toHaveCount(1);
});

test('handle() honors a legacy per-account media subset when placement rows do not exist', function (): void {
    $target = publishTarget(['Legacy override']);
    $post = $target->post()->firstOrFail();
    $first = PostMedia::factory()->create(['post_id' => $post->id, 'workspace_id' => $post->workspace_id]);
    $second = PostMedia::factory()->create(['post_id' => $post->id, 'workspace_id' => $post->workspace_id]);
    $target->forceFill([
        'placements_explicit' => false,
        'content_override' => ['segments' => ['Legacy override'], 'media_ids' => [$second->id]],
    ])->save();

    $captured = null;
    bindConnector(function (PublishContext $context) use (&$captured): PublishResult {
        $captured = $context;

        return PublishResult::success(['111']);
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect(array_map(fn (PostMedia $item): string => $item->id, $captured->effectiveMedia()))
        ->toBe([$second->id])
        ->and($captured->effectiveMedia()[0]->id)->not->toBe($first->id);
});

test('handle() honors a legacy explicit empty media override', function (): void {
    $target = publishTarget(['Legacy text only']);
    $post = $target->post()->firstOrFail();
    PostMedia::factory()->create(['post_id' => $post->id, 'workspace_id' => $post->workspace_id]);
    $target->forceFill([
        'placements_explicit' => false,
        'content_override' => ['segments' => ['Legacy text only'], 'media_ids' => []],
    ])->save();

    $captured = null;
    bindConnector(function (PublishContext $context) use (&$captured): PublishResult {
        $captured = $context;

        return PublishResult::success(['111']);
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($captured->effectiveMedia())->toBe([]);
});
