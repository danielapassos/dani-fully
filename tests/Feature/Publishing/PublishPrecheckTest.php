<?php

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\SegmentMediaResolver;

test('blockingTargets flags an over-limit Bluesky target', function () {
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'handle' => '@bsky']);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky->value,
        'sections' => [str_repeat('x', 400)],
        'auto_split' => false,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['platform'])->toBe('bluesky')
        ->and($blocked[0]['issues'])->toContain('section_too_long')
        ->and($blocked[0]['handle'])->toBe('@bsky');
});

test('blockingTargets passes a within-limit target', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello world'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets flags a target with no text and no media', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => [''],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toBe(['empty']);
});

test('blockingTargets flags a target whose sections are only whitespace', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['   ', "\n"],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toBe(['empty']);
});

test('blockingTargets flags an Instagram target with text but no media', function () {
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => '@insta']);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['Test'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['platform'])->toBe('instagram')
        ->and($blocked[0]['issues'])->toBe(['media_required']);
});

test('blockingTargets passes an Instagram target with text and media', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Instagram->value,
        'sections' => ['Test'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets requires video for a Meta reel format', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'format' => PostFormat::Reels->value,
        'sections' => ['Reel caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('video_required');
});

test('blockingTargets prevents single-media formats from silently dropping attachments', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->count(2)->create(['kind' => 'image']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'format' => PostFormat::Story->value,
        'sections' => [''],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('too_many_media');
});

test('blockingTargets requires a video for TikTok even when an image is attached', function () {
    config()->set('services.tiktok.inbox_enabled', true);
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.upload']],
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok->value,
        'sections' => ['TikTok caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toBe(['video_required']);
});

test('blockingTargets passes a publish-ready YouTube target with a video', function () {
    config()->set('services.youtube.publishing_enabled', true);
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::YouTube,
        'capabilities' => ['oauth_scopes' => ['https://www.googleapis.com/auth/youtube.upload']],
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::YouTube->value,
        'sections' => ['YouTube caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets rejects a read-only YouTube connection before dispatch', function () {
    config()->set('services.youtube.publishing_enabled', true);
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::YouTube,
        'capabilities' => ['oauth_scopes' => ['https://www.googleapis.com/auth/youtube.readonly']],
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::YouTube->value,
        'sections' => ['YouTube caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toBe(['publishing_unavailable']);
});

test('blockingTargets rejects TikTok while instance publishing is disabled', function () {
    config()->set('services.tiktok.inbox_enabled', false);
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.upload']],
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok->value,
        'sections' => ['TikTok caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toBe(['publishing_unavailable']);
});

test('blockingTargets passes a text-only target on a platform that does not require media', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['Test'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets passes a media-only target with no text', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => [''],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets flags a post mixing a video with an image', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('mixed_video_and_images');
});

test('blockingTargets allows a video and an image mixed across different thread segments on X', function () {
    $post = Post::factory()->create();
    $image = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $video = PostMedia::factory()->for($post)->video()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['first', 'second'],
        'segment_breaks' => ['break-1'],
        'section_sources' => [0, 1],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $video->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $image->id,
        'segment_ref' => 'break-1',
        'position' => 0,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets still flags a video and an image mixed within the same thread segment on X', function () {
    $post = Post::factory()->create();
    $image = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $video = PostMedia::factory()->for($post)->video()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['first', 'second'],
        'segment_breaks' => ['break-1'],
        'section_sources' => [0, 1],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $video->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $image->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 1,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('mixed_video_and_images');
});

test('blockingTargets allows a post mixing a video with an image on Instagram (real mixed carousel)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets allows a post mixing a video with an image on Threads (real mixed carousel)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Threads->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets allows a post mixing a video with an image on Discord (attaches every file)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Discord]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Discord->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets flags a post mixing a video with an image on LinkedIn', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::LinkedIn->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('mixed_video_and_images');
});

test('blockingTargets flags a video longer than the platform allows', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->video()->create([
        'duration_seconds' => Platform::X->maxVideoDurationSeconds() + 10,
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('video_too_long');
});

test('blockingTargets flags a video larger than the platform allows', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->video()->create([
        'size_bytes' => Platform::Bluesky->maxVideoBytes() + 1,
    ]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('video_too_large');
});

test('blockingTargets flags a GIF mixed with another image on X', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/gif']);
    PostMedia::factory()->for($post)->create(['mime' => 'image/jpeg']);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('gif_not_mixable');
});

test('blockingTargets allows a single GIF on X', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/gif']);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets passes a non-JPEG image on Instagram (converted at publish)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/png']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets passes a JPEG image on Instagram', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/jpeg']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets passes a PNG image on X (compressed at publish)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/png']);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets allows 4 images per section across two thread sections on X', function () {
    $post = Post::factory()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['first', 'second'],
        'segment_breaks' => ['break-1'],
        'section_sources' => [0, 1],
    ]);

    foreach (range(0, 3) as $position) {
        $media = PostMedia::factory()->for($post)->create(['kind' => 'image']);
        PostMediaPlacement::factory()->create([
            'post_target_id' => $target->id,
            'post_media_id' => $media->id,
            'segment_ref' => SegmentMediaResolver::HEAD,
            'position' => $position,
        ]);
    }

    foreach (range(0, 3) as $position) {
        $media = PostMedia::factory()->for($post)->create(['kind' => 'image']);
        PostMediaPlacement::factory()->create([
            'post_target_id' => $target->id,
            'post_media_id' => $media->id,
            'segment_ref' => 'break-1',
            'position' => $position,
        ]);
    }

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets flags 5 images placed on a single thread section as too_many_media', function () {
    $post = Post::factory()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['first'],
    ]);

    foreach (range(0, 4) as $position) {
        $media = PostMedia::factory()->for($post)->create(['kind' => 'image']);
        PostMediaPlacement::factory()->create([
            'post_target_id' => $target->id,
            'post_media_id' => $media->id,
            'segment_ref' => SegmentMediaResolver::HEAD,
            'position' => $position,
        ]);
    }

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('too_many_media');
});

test('blockingTargets treats media omitted from explicit placements as a target-specific exclusion', function () {
    $post = Post::factory()->create();
    $placed = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $placed->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets validates TikTok and YouTube against each targets placed video subset', function (
    Platform $platform,
    string $configKey,
    string $scope,
) {
    config()->set($configKey, true);
    $post = Post::factory()->create();
    $video = PostMedia::factory()->for($post)->video()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $account = ConnectedAccount::factory()->create([
        'platform' => $platform,
        'capabilities' => ['oauth_scopes' => [$scope]],
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => $platform,
        'sections' => ['Video caption'],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $video->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets(
        $post->fresh(['targets.account', 'targets.placements', 'media']),
    );

    expect($blocked)->toBe([]);
})->with([
    'TikTok' => [Platform::TikTok, 'services.tiktok.inbox_enabled', 'video.upload'],
    'YouTube' => [Platform::YouTube, 'services.youtube.publishing_enabled', 'https://www.googleapis.com/auth/youtube.upload'],
]);

test('blockingTargets passes a target whose placements cover all attached media', function () {
    $post = Post::factory()->create();
    $first = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $second = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $first->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $second->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 1,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets treats an explicit empty placement set as text-only while preserving the legacy fallback', function (): void {
    $post = Post::factory()->create(['segments' => ['hello'], 'base_text' => 'hello']);
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
        'placements_explicit' => true,
    ]);

    $explicit = app(PublishPrecheck::class)->blockingTargets(
        $post->fresh(['targets.account', 'targets.placements', 'media']),
    );
    expect($explicit)->toBe([]);

    $target->forceFill(['placements_explicit' => false])->save();
    $legacy = app(PublishPrecheck::class)->blockingTargets(
        $post->fresh(['targets.account', 'targets.placements', 'media']),
    );
    expect($legacy)->toHaveCount(1)
        ->and($legacy[0]['issues'])->toContain('mixed_video_and_images');
});

test('blockingTargets honors legacy per-account media subsets and empty selections', function (): void {
    $post = Post::factory()->create(['segments' => ['hello'], 'base_text' => 'hello']);
    $image = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
        'placements_explicit' => false,
        'content_override' => ['segments' => ['hello'], 'media_ids' => [$image->id]],
    ]);

    $subset = app(PublishPrecheck::class)->blockingTargets(
        $post->fresh(['targets.account', 'targets.placements', 'media']),
    );
    expect($subset)->toBe([]);

    $target->forceFill([
        'content_override' => ['segments' => ['hello'], 'media_ids' => []],
    ])->save();
    $empty = app(PublishPrecheck::class)->blockingTargets(
        $post->fresh(['targets.account', 'targets.placements', 'media']),
    );
    expect($empty)->toBe([]);
});

test('blockingTargets allows a GIF mixed with an image on LinkedIn', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/gif']);
    PostMedia::factory()->for($post)->create(['mime' => 'image/jpeg']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::LinkedIn->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});
