<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\DirectMessage;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\InstagramConnector;
use App\Services\Publishing\InstagramReelCover;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function reelCoverMember(): array
{
    [$user, $workspace, $token] = issuedKey();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$user, $workspace, $token];
}

function reelCoverMedia(string $workspaceId, array $attributes = []): PostMedia
{
    return PostMedia::factory()->create([
        'workspace_id' => $workspaceId, 'post_id' => null, 'direct_message_id' => null,
        'kind' => 'image', 'disk' => 'public', 'path' => 'media/cover.jpg',
        'mime' => 'image/jpeg', 'size_bytes' => 100, ...$attributes,
    ]);
}

test('web and API drafts bind a cover without adding it to video media and preserve or clear the choice explicitly', function (string $surface) {
    [$user, $workspace, $token] = reelCoverMember();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $cover = reelCoverMedia($workspace->id);
    $payload = [
        'segments' => ['A Reel'], 'base_text' => 'A Reel',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['instagram' => ['cover_media_id' => $cover->id]]]],
    ];
    $response = $surface === 'web'
        ? $this->actingAs($user)->postJson(route('posts.store'), $payload)
        : $this->withToken($token)->postJson('/api/v1/posts', $payload);
    $response->assertCreated()->assertJsonPath('post.targets.0.content_override.instagram.cover_media_id', $cover->id);
    $postId = $response->json('post.id');
    expect($cover->fresh()->post_id)->toBeNull()
        ->and(Post::findOrFail($postId)->media()->count())->toBe(0);

    $payload['targets'][0]['content_override'] = ['segments' => ['Edited caption']];
    $response = $surface === 'web'
        ? $this->putJson(route('posts.update', $postId), $payload)
        : $this->patchJson('/api/v1/posts/'.$postId, $payload);
    $response->assertOk()->assertJsonPath('post.targets.0.content_override.instagram.cover_media_id', $cover->id);

    $payload['targets'][0]['content_override'] = ['instagram' => ['cover_media_id' => null]];
    $response = $surface === 'web'
        ? $this->putJson(route('posts.update', $postId), $payload)
        : $this->patchJson('/api/v1/posts/'.$postId, $payload);
    $response->assertOk()->assertJsonPath('post.targets.0.content_override.instagram.cover_media_id', null);
})->with(['web', 'api']);

test('draft cover selection rejects foreign video DM and invalid image references', function (string $invalid) {
    [$user, $workspace] = reelCoverMember();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $cover = match ($invalid) {
        'foreign' => PostMedia::factory()->create(['mime' => 'image/jpeg']),
        'video' => reelCoverMedia($workspace->id, ['kind' => 'video', 'mime' => 'video/mp4']),
        'dm' => reelCoverMedia($workspace->id, ['direct_message_id' => DirectMessage::factory()->create()->id]),
        'oversized' => reelCoverMedia($workspace->id, ['size_bytes' => 8 * 1024 * 1024 + 1]),
        'animated' => reelCoverMedia($workspace->id, ['mime' => 'image/gif']),
    };
    $this->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['A Reel'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['instagram' => ['cover_media_id' => $cover->id]]]],
    ])->assertUnprocessable();
    expect(Post::where('workspace_id', $workspace->id)->exists())->toBeFalse();
})->with(['foreign', 'video', 'dm', 'oversized', 'animated']);

test('the cover gallery is workspace scoped and includes an older selected image', function () {
    [$user, $workspace] = reelCoverMember();
    $post = Post::factory()->for($workspace)->create();
    $selected = reelCoverMedia($workspace->id);
    $visible = PostMedia::factory()->count(25)->create([
        'workspace_id' => $workspace->id, 'post_id' => null, 'mime' => 'image/jpeg', 'kind' => 'image', 'size_bytes' => 100,
    ]);
    $foreign = PostMedia::factory()->create(['mime' => 'image/jpeg']);
    $video = reelCoverMedia($workspace->id, ['kind' => 'video', 'mime' => 'video/mp4']);
    $response = $this->actingAs($user)->getJson(route('posts.instagram-covers.index', ['post' => $post, 'selected' => $selected->id]))->assertOk();
    $ids = array_column($response->json('media'), 'id');
    expect($ids)->toContain($selected->id)->not->toContain($foreign->id, $video->id)
        ->and($response->json('next_cursor'))->not->toBeNull()
        ->and(count($ids))->toBe(25);
    $otherPost = Post::factory()->create();
    $this->getJson(route('posts.instagram-covers.index', $otherPost))->assertNotFound();
});

test('MCP draft creation and update persist an Instagram cover reference', function () {
    [$user, $workspace] = reelCoverMember();
    bindTokenToWorkspace($user, $workspace);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $cover = reelCoverMedia($workspace->id);
    $targets = [['connected_account_id' => $account->id, 'content_override' => ['instagram' => ['cover_media_id' => $cover->id]]]];
    ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'Cover via MCP', 'destination' => ['kind' => 'account', 'id' => $account->id], 'targets' => $targets,
    ])->assertOk();
    $post = Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->sole();
    expect($post->targets->sole()->content_override['instagram']['cover_media_id'])->toBe($cover->id);
    $targets[0]['content_override']['instagram']['cover_media_id'] = null;
    ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id, 'base_text' => 'Cover via MCP',
        'destination' => ['kind' => 'account', 'id' => $account->id], 'targets' => $targets,
    ])->assertOk();
    expect($post->targets()->sole()->content_override['instagram']['cover_media_id'])->toBeNull();
});

test('a Reel uses the chosen existing cover URL without adding image media or changing its video URL', function (PostFormat $format) {
    Storage::fake('public');
    [$user, $workspace] = reelCoverMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram, 'remote_account_id' => 'ig-cover-user']);
    $video = PostMedia::factory()->for($post)->video()->create(['disk' => 'public', 'path' => 'media/unchanged.mp4']);
    $cover = reelCoverMedia($workspace->id);
    Storage::disk('public')->put($cover->path, 'cover-bytes');
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram, 'format' => $format,
        'content_override' => ['instagram' => ['cover_media_id' => $cover->id]],
    ]);
    Http::fake([
        'https://graph.facebook.com/*/ig-cover-user/media' => Http::response(['id' => 'cover-container']),
        'https://graph.facebook.com/*/cover-container*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig-cover-user/media_publish' => Http::response(['id' => 'published-reel']),
    ]);
    $result = app(InstagramConnector::class)->publish(new PublishContext($target, ['Caption'], [$video], $account, ['access_token' => 'fake']));
    expect($result->isSuccessful())->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ig-cover-user/media')
        && $request['media_type'] === 'REELS' && str_contains($request['cover_url'], 'media/cover.jpg')
        && str_contains($request['video_url'], 'media/unchanged.mp4') && ! isset($request['image_url']));
    expect($cover->fresh()->post_id)->toBeNull()->and($post->media()->count())->toBe(1);
})->with([PostFormat::Feed, PostFormat::Reels]);

test('a queued foreign cover is rejected before any platform request', function () {
    [$user, $workspace] = reelCoverMember();
    $post = Post::factory()->for($workspace)->create();
    $cover = PostMedia::factory()->create(['mime' => 'image/jpeg']);
    $video = PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->for($post)->create(['platform' => Platform::Instagram, 'content_override' => ['instagram' => ['cover_media_id' => $cover->id]]]);
    Http::fake();
    $result = app(InstagramConnector::class)->publish(new PublishContext($target, ['Caption'], [$video], $account, ['access_token' => 'fake']));
    expect($result->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

test('cover selection is rejected for Stories photos and mixed media', function (PostFormat $format, string $mediaKind) {
    [$user, $workspace] = reelCoverMember();
    $post = Post::factory()->for($workspace)->create();
    $cover = reelCoverMedia($workspace->id);
    $video = PostMedia::factory()->for($post)->video()->create();
    $photo = PostMedia::factory()->for($post)->create();
    $target = PostTarget::factory()->for($post)->create(['platform' => Platform::Instagram, 'format' => $format, 'content_override' => ['instagram' => ['cover_media_id' => $cover->id]]]);
    $media = match ($mediaKind) {
        'video' => [$video], 'photo' => [$photo], 'mixed' => [$video, $photo]
    };
    expect(app(InstagramReelCover::class)->issues($target, $media))->toBe(['instagram_cover_requires_reel']);
})->with([[PostFormat::Story, 'video'], [PostFormat::Feed, 'photo'], [PostFormat::Reels, 'mixed']]);

test('an existing container resumes without creating a replacement for a later cover choice', function () {
    [$user, $workspace] = reelCoverMember();
    $post = Post::factory()->for($workspace)->create();
    $video = PostMedia::factory()->for($post)->video()->create();
    $cover = reelCoverMedia($workspace->id);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram, 'remote_account_id' => 'ig-cover-user']);
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::Instagram,
        'content_override' => ['instagram' => ['cover_media_id' => $cover->id]],
        'media_upload_state' => ['container' => ['remote_ref' => 'original-container', 'uploaded_at' => now()->toIso8601String()]],
    ]);
    Http::fake([
        'https://graph.facebook.com/*/original-container*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig-cover-user/media_publish' => Http::response(['id' => 'published-original']),
    ]);
    expect(app(InstagramConnector::class)->publish(new PublishContext($target, ['Caption'], [$video], $account, ['access_token' => 'fake']))->isSuccessful())->toBeTrue();
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/ig-cover-user/media'));
});

test('a selected cover survives orphan pruning and cannot be deleted until its reference is removed', function () {
    Storage::fake('public');
    config()->set('filesystems.default', 'public');
    [$user, $workspace] = reelCoverMember();
    $cover = reelCoverMedia($workspace->id, ['created_at' => now()->subHours(7)]);
    $orphan = reelCoverMedia($workspace->id, ['path' => 'media/orphan.jpg', 'created_at' => now()->subHours(7)]);
    Storage::disk('public')->put($cover->path, 'keep');
    Storage::disk('public')->put($orphan->path, 'remove');
    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->create(['platform' => Platform::Instagram, 'content_override' => ['instagram' => ['cover_media_id' => $cover->id]]]);
    $this->artisan('media:prune-uploads')->assertSuccessful();
    expect($cover->fresh())->not->toBeNull()->and($orphan->fresh())->toBeNull();
    expect(fn () => $cover->delete())->toThrow(ValidationException::class);
    Storage::disk('public')->assertExists($cover->path);
    $target->update(['content_override' => ['instagram' => ['cover_media_id' => null]]]);
    $cover->delete();
    Storage::disk('public')->assertMissing($cover->path);
});

test('a cover cannot change after its Instagram container has already been created', function () {
    [$user, $workspace] = reelCoverMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $original = reelCoverMedia($workspace->id);
    $replacement = reelCoverMedia($workspace->id, ['path' => 'media/replacement.jpg']);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram,
        'content_override' => ['instagram' => ['cover_media_id' => $original->id]],
        'media_upload_state' => ['container' => ['remote_ref' => 'existing-container', 'state' => 'processing']],
    ]);
    $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Caption'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['instagram' => ['cover_media_id' => $replacement->id]]]],
    ])->assertUnprocessable();
    expect($target->fresh()->content_override['instagram']['cover_media_id'])->toBe($original->id)
        ->and($target->fresh()->media_upload_state['container']['remote_ref'])->toBe('existing-container');
});
