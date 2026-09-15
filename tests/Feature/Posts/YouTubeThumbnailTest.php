<?php

use App\Enums\Platform;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\DirectMessage;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\YouTubePostOptions;
use App\Services\Publishing\YouTubeThumbnail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

function youtubeThumbnailMember(): array
{
    [$user, $workspace, $token] = issuedKey();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$user, $workspace, $token];
}

function youtubeThumbnailMedia(string $workspaceId, array $attributes = []): PostMedia
{
    return PostMedia::factory()->create([
        'workspace_id' => $workspaceId, 'post_id' => null, 'direct_message_id' => null,
        'kind' => 'image', 'disk' => 'public', 'path' => 'media/youtube-cover.jpg',
        'mime' => 'image/jpeg', 'size_bytes' => 100, ...$attributes,
    ]);
}

function youtubeThumbnailChoices(string $privacy = 'public'): array
{
    return [
        'privacy_status' => $privacy, 'category_id' => '22', 'format_intent' => 'video',
        'made_for_kids' => false, 'contains_synthetic_media' => false,
        'has_paid_product_placement' => false, 'notify_subscribers' => false,
    ];
}

test('YouTube web and API drafts keep covers separate from video media and preserve omitted cover choices', function (string $surface) {
    [$user, $workspace, $token] = youtubeThumbnailMember();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::YouTube]);
    $cover = youtubeThumbnailMedia($workspace->id);
    $payload = [
        'segments' => ['Video'], 'base_text' => 'Video',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['youtube' => ['thumbnail_media_id' => $cover->id]]]],
    ];
    $response = $surface === 'web'
        ? $this->actingAs($user)->postJson(route('posts.store'), $payload)
        : $this->withToken($token)->postJson('/api/v1/posts', $payload);
    $response->assertCreated()->assertJsonPath('post.targets.0.content_override.youtube.thumbnail_media_id', $cover->id);
    $postId = $response->json('post.id');
    expect($cover->fresh()->post_id)->toBeNull()
        ->and(Post::findOrFail($postId)->media()->count())->toBe(0);

    foreach ([['segments' => ['Caption edit']], ['youtube' => ['title' => 'Title edit']]] as $override) {
        $payload['targets'][0]['content_override'] = $override;
        $response = $surface === 'web'
            ? $this->putJson(route('posts.update', $postId), $payload)
            : $this->patchJson('/api/v1/posts/'.$postId, $payload);
        $response->assertOk()->assertJsonPath('post.targets.0.content_override.youtube.thumbnail_media_id', $cover->id);
    }

    $payload['targets'][0]['content_override'] = ['youtube' => ['thumbnail_media_id' => null]];
    $response = $surface === 'web'
        ? $this->putJson(route('posts.update', $postId), $payload)
        : $this->patchJson('/api/v1/posts/'.$postId, $payload);
    $response->assertOk()->assertJsonPath('post.targets.0.content_override.youtube.thumbnail_media_id', null);
})->with(['web', 'api']);

test('YouTube cover selection rejects foreign video DM and invalid image references', function (string $invalid) {
    [$user, $workspace] = youtubeThumbnailMember();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::YouTube]);
    $cover = match ($invalid) {
        'foreign' => PostMedia::factory()->create(['mime' => 'image/jpeg']),
        'video' => youtubeThumbnailMedia($workspace->id, ['kind' => 'video', 'mime' => 'video/mp4']),
        'dm' => youtubeThumbnailMedia($workspace->id, ['direct_message_id' => DirectMessage::factory()->create()->id]),
        'oversized' => youtubeThumbnailMedia($workspace->id, ['size_bytes' => 8 * 1024 * 1024 + 1]),
        'empty' => youtubeThumbnailMedia($workspace->id, ['size_bytes' => 0]),
        'animated' => youtubeThumbnailMedia($workspace->id, ['mime' => 'image/gif']),
    };
    $this->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['Video'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['youtube' => ['thumbnail_media_id' => $cover->id]]]],
    ])->assertUnprocessable();
    expect(Post::where('workspace_id', $workspace->id)->exists())->toBeFalse();
})->with(['foreign', 'video', 'dm', 'oversized', 'empty', 'animated']);

test('YouTube cover ids must be nullable UUIDs in draft requests and queued options', function (mixed $id, bool $valid) {
    $options = [...youtubeThumbnailChoices(), 'thumbnail_media_id' => $id];
    $payload = ['targets' => [['content_override' => ['youtube' => $options]]]];
    expect(Validator::make($payload, YouTubePostOptions::draftRules())->passes())->toBe($valid);
    $target = new PostTarget(['content_override' => ['youtube' => $options]]);
    expect(app(YouTubePostOptions::class)->resolve($target) !== null)->toBe($valid);
})->with([
    'none' => [null, true],
    'uuid' => ['018fdeab-483a-72f3-9522-2fbf9df4faff', true],
    'url' => ['https://example.test/cover.jpg', false],
    'number' => [22, false],
    'array' => [['id' => 'something'], false],
]);

test('the YouTube cover gallery is workspace scoped with a selected older image and cursor', function () {
    [$user, $workspace] = youtubeThumbnailMember();
    $post = Post::factory()->for($workspace)->create();
    $selected = youtubeThumbnailMedia($workspace->id);
    PostMedia::factory()->count(25)->create([
        'workspace_id' => $workspace->id, 'post_id' => null, 'mime' => 'image/png', 'kind' => 'image', 'size_bytes' => 100,
    ]);
    $foreign = PostMedia::factory()->create(['mime' => 'image/jpeg']);
    $video = youtubeThumbnailMedia($workspace->id, ['kind' => 'video', 'mime' => 'video/mp4']);
    $response = $this->actingAs($user)->getJson(route('posts.youtube-covers.index', ['post' => $post, 'selected' => $selected->id]))->assertOk();
    $ids = array_column($response->json('media'), 'id');
    expect($ids)->toContain($selected->id)->not->toContain($foreign->id, $video->id)
        ->and($response->json('next_cursor'))->not->toBeNull()
        ->and(count($ids))->toBe(25);
    $this->getJson(route('posts.youtube-covers.index', Post::factory()->create()))->assertNotFound();
});

test('MCP creates preserves and explicitly clears a YouTube cover reference', function () {
    [$user, $workspace] = youtubeThumbnailMember();
    bindTokenToWorkspace($user, $workspace);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::YouTube]);
    $cover = youtubeThumbnailMedia($workspace->id);
    $targets = [['connected_account_id' => $account->id, 'content_override' => ['youtube' => ['thumbnail_media_id' => $cover->id]]]];
    $payload = ['base_text' => 'Cover via MCP', 'destination' => ['kind' => 'account', 'id' => $account->id], 'targets' => $targets];
    ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, $payload)->assertOk();
    $post = Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->sole();
    expect($post->targets->sole()->content_override['youtube']['thumbnail_media_id'])->toBe($cover->id);
    $payload['post_id'] = $post->id;
    $payload['targets'][0]['content_override']['youtube'] = ['title' => 'MCP title edit'];
    ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, $payload)->assertOk();
    expect($post->targets()->sole()->content_override['youtube']['thumbnail_media_id'])->toBe($cover->id);
    $payload['targets'][0]['content_override']['youtube']['thumbnail_media_id'] = null;
    ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, $payload)->assertOk();
    expect($post->targets()->sole()->content_override['youtube']['thumbnail_media_id'])->toBeNull();
});

test('YouTube selected covers survive orphan pruning and deletion requires removing the reference', function () {
    Storage::fake('public');
    config()->set('filesystems.default', 'public');
    [, $workspace] = youtubeThumbnailMember();
    $cover = youtubeThumbnailMedia($workspace->id, ['created_at' => now()->subHours(7)]);
    $orphan = youtubeThumbnailMedia($workspace->id, ['path' => 'media/orphan.jpg', 'created_at' => now()->subHours(7)]);
    Storage::disk('public')->put($cover->path, 'keep');
    Storage::disk('public')->put($orphan->path, 'remove');
    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->create(['platform' => Platform::YouTube, 'content_override' => ['youtube' => ['thumbnail_media_id' => $cover->id]]]);
    $this->artisan('media:prune-uploads')->assertSuccessful();
    expect($cover->fresh())->not->toBeNull()->and($orphan->fresh())->toBeNull();
    expect(fn () => $cover->delete())->toThrow(ValidationException::class);
    Storage::disk('public')->assertExists($cover->path);
    $target->update(['content_override' => ['youtube' => ['thumbnail_media_id' => null]]]);
    $cover->delete();
    Storage::disk('public')->assertMissing($cover->path);
});

test('a YouTube cover cannot change or clear after its upload session or remote video exists', function (string $started, bool $clear) {
    [$user, $workspace] = youtubeThumbnailMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::YouTube]);
    $original = youtubeThumbnailMedia($workspace->id);
    $replacement = youtubeThumbnailMedia($workspace->id, ['path' => 'media/replacement.jpg']);
    $remoteState = match ($started) {
        'session' => ['video-media-id' => ['remote_ref' => 'existing-session']],
        'binding' => ['video-media-id' => ['metadata' => ['thumbnail' => ['media_id' => $original->id, 'sha256' => str_repeat('a', 64)]]]],
        'pending' => ['video-media-id' => ['metadata' => ['thumbnail_session_pending' => true]]],
        default => [],
    };
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::YouTube,
        'content_override' => ['youtube' => ['thumbnail_media_id' => $original->id]],
        ...($started !== 'remote' ? ['media_upload_state' => $remoteState] : ['remote_id' => 'video-id']),
    ]);
    $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Caption'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['youtube' => ['thumbnail_media_id' => $clear ? null : $replacement->id]]]],
    ])->assertUnprocessable();
    expect($target->fresh()->content_override['youtube']['thumbnail_media_id'])->toBe($original->id);
    if ($started !== 'remote') {
        expect($target->fresh()->media_upload_state)->toBe($remoteState);
    } else {
        expect($target->fresh()->remote_id)->toBe('video-id');
    }
})->with([['session', false], ['session', true], ['remote', false], ['remote', true], ['binding', false], ['binding', true], ['pending', false], ['pending', true]]);

test('YouTube cover precheck requires management permission only for a chosen nonprivate cover', function (string $privacy, bool $coverSelected, bool $blocked) {
    config()->set('services.youtube.publishing_enabled', true);
    [, $workspace] = youtubeThumbnailMember();
    $post = Post::factory()->for($workspace)->create();
    $cover = youtubeThumbnailMedia($workspace->id);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::YouTube,
        'capabilities' => ['oauth_scopes' => ['https://www.googleapis.com/auth/youtube.upload']],
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::YouTube, 'sections' => ['Video'],
        'content_override' => ['youtube' => [...youtubeThumbnailChoices($privacy), 'thumbnail_media_id' => $coverSelected ? $cover->id : null]],
    ]);
    expect($account->canPublish())->toBeTrue();
    $issues = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));
    if ($blocked) {
        expect($issues[0]['issues'])->toContain('youtube_thumbnail_release_scope_required')
            ->and(app(PublishPrecheck::class)->describe($issues[0]['issues'], Platform::YouTube))->toContain('Reconnect');
    } else {
        expect($issues)->toBe([]);
    }
})->with([
    ['public', true, true], ['unlisted', true, true], ['private', true, false],
    ['public', false, false], ['unlisted', false, false],
]);

test('YouTube cover release accepts only granted management scopes with existing verification semantics', function (array $capabilities, bool $allowed) {
    $account = new ConnectedAccount(['platform' => Platform::YouTube, 'capabilities' => $capabilities]);
    expect(app(YouTubeThumbnail::class)->canRelease($account))->toBe($allowed);
})->with([
    'force-ssl' => [['oauth_scopes_verified' => true, 'oauth_scopes' => ['https://www.googleapis.com/auth/youtube.force-ssl']], true],
    'youtube equivalent' => [['oauth_scopes_verified' => true, 'oauth_scopes' => ['https://www.googleapis.com/auth/youtube']], true],
    'legacy actual scopes' => [['oauth_scopes' => 'https://www.googleapis.com/auth/youtube.force-ssl'], true],
    'unverified requested scopes' => [['oauth_scopes_verified' => false, 'oauth_scopes' => ['https://www.googleapis.com/auth/youtube.force-ssl']], false],
    'upload only' => [['oauth_scopes_verified' => true, 'oauth_scopes' => ['https://www.googleapis.com/auth/youtube.upload']], false],
    'missing scopes' => [[], false],
]);

test('a frozen YouTube thumbnail remains protected if its draft override is later cleared', function () {
    Storage::fake('public');
    config()->set('filesystems.default', 'public');
    [, $workspace] = youtubeThumbnailMember();
    $cover = youtubeThumbnailMedia($workspace->id, ['created_at' => now()->subHours(7)]);
    Storage::disk('public')->put($cover->path, 'keep');
    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::YouTube,
        'content_override' => ['youtube' => ['thumbnail_media_id' => null]],
        'media_upload_state' => ['video-media-id' => ['metadata' => ['thumbnail' => ['media_id' => $cover->id]]]],
    ]);
    $this->artisan('media:prune-uploads')->assertSuccessful();
    expect($cover->fresh())->not->toBeNull()
        ->and(fn () => $cover->delete())->toThrow(ValidationException::class);
    Storage::disk('public')->assertExists($cover->path);
    $target->delete();
    $cover->delete();
    Storage::disk('public')->assertMissing($cover->path);
});

test('YouTube cover precheck rejects an unavailable choice and nonvideo content', function () {
    [, $workspace] = youtubeThumbnailMember();
    $post = Post::factory()->for($workspace)->create();
    $video = PostMedia::factory()->for($post)->video()->create();
    $photo = PostMedia::factory()->for($post)->create();
    $foreign = PostMedia::factory()->create(['mime' => 'image/jpeg']);
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::YouTube,
        'content_override' => ['youtube' => [...youtubeThumbnailChoices('private'), 'thumbnail_media_id' => $foreign->id]],
    ]);
    expect(app(YouTubeThumbnail::class)->issues($target, [$video]))->toBe(['youtube_thumbnail_unavailable'])
        ->and(app(YouTubeThumbnail::class)->issues($target, [$photo]))->toBe(['youtube_thumbnail_requires_video']);
});
