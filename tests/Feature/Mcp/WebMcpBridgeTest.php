<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\CreateShareLinkTool;
use App\Mcp\Tools\DeleteAccountSetTool;
use App\Mcp\Tools\DeletePostTool;
use App\Mcp\Tools\DeleteShareTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\PublishPostTool;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\RemovePostMediaTool;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Posts\DraftService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Tool;

/**
 * @return array{user_id: string, workspace_id: string, arguments: array<string, mixed>}
 */
function webMcpEnvelope(User $user, Workspace $workspace, array $arguments = []): array
{
    return ['user_id' => $user->id, 'workspace_id' => $workspace->id, 'arguments' => $arguments];
}

function webMcpDiscoveryUrl(User $user, Workspace $workspace): string
{
    return '/webmcp/tools?'.http_build_query(['user_id' => $user->id, 'workspace_id' => $workspace->id]);
}

/**
 * @param  class-string<Tool>  $class
 */
function webMcpToolUrl(string $class): string
{
    return '/webmcp/tools/'.app($class)->name();
}

test('browser tool discovery and execution require a verified session', function (): void {
    $user = User::factory()->unverified()->create();
    $workspace = Workspace::factory()->create();

    $this->getJson(webMcpDiscoveryUrl($user, $workspace))->assertUnauthorized();
    $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($user, $workspace))->assertUnauthorized();

    $this->actingAs($user);
    $this->getJson(webMcpDiscoveryUrl($user, $workspace))->assertForbidden();
    $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($user, $workspace))->assertForbidden();
    $this->assertDatabaseCount('posts', 0);
});

test('browser discovery serializes the reviewed schemas and consequence hints without credentials', function (): void {
    [$user, $workspace] = ownerActingIn();
    config()->set('services.tiktok.client_secret', 'private-discovery-secret');

    $response = $this->getJson(webMcpDiscoveryUrl($user, $workspace))
        ->assertOk()
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('workspace_id', $workspace->id)
        ->assertJsonCount(27, 'tools')
        ->assertDontSee('private-discovery-secret');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private')
        ->and($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();

    $tools = collect($response->json('tools'))->keyBy('name');
    expect($tools->keys()->unique())->toHaveCount(27)
        ->and($tools->keys()->all())->toContain('begin_video_upload', 'complete_video_upload', 'list_account_analytics', 'list_post_analytics', 'refresh_tiktok_inbox');

    $create = $tools[app(CreatePostTool::class)->name()];
    $originalSchema = json_decode(json_encode(app(CreatePostTool::class)->toArray()['inputSchema'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    expect($create['inputSchema'])->toBe($originalSchema)
        ->and($create['inputSchema']['properties']['destination']['type'])->toBe('object')
        ->and($create['annotations'])->toBe(['readOnlyHint' => false, 'consequentialHint' => false, 'untrustedContentHint' => true])
        ->and($create['inputSchema']['properties'])->not->toHaveKey('confirm');

    foreach ([PublishPostTool::class, RetryPostTargetTool::class, DeletePostTool::class, SchedulePostTool::class, QueuePostTool::class, CreateShareLinkTool::class, DeleteShareTool::class, DeleteAccountSetTool::class, RemovePostMediaTool::class] as $class) {
        $tool = $tools[app($class)->name()];
        expect($tool['inputSchema']['required'])->toContain('confirm')
            ->and($tool['inputSchema']['properties']['confirm']['const'])->toBeTrue()
            ->and($tool['annotations']['consequentialHint'])->toBeTrue()
            ->and($tool['annotations']['readOnlyHint'])->toBeFalse();
    }

    $workspaces = $tools[app(ListWorkspacesTool::class)->name()];
    expect($workspaces['description'])->toContain('workspace switcher')->not->toContain('Reconnect')
        ->and($workspaces['inputSchema']['type'])->toBe('object')
        ->and($workspaces['annotations']['readOnlyHint'])->toBeTrue();
});

test('browser envelope requires actor and workspace and rejects identity arguments even when empty', function (array $arguments): void {
    [$user, $workspace] = ownerActingIn();

    $this->getJson('/webmcp/tools')->assertUnprocessable()->assertJsonPath('isError', true);
    $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($user, $workspace, $arguments))
        ->assertUnprocessable()->assertJsonPath('isError', true);

    $this->assertDatabaseCount('posts', 0);
})->with([
    'workspace override' => [['workspace_id' => 'different-workspace']],
    'null workspace override' => [['workspace_id' => null]],
    'empty workspace override' => [['workspace_id' => []]],
    'actor override' => [['user_id' => 'different-user']],
]);

test('browser tools recheck removed membership before discovery and mutation', function (): void {
    [$user, $workspace] = ownerActingIn();
    WorkspaceMembership::query()->where('user_id', $user->id)->where('workspace_id', $workspace->id)->delete();

    $this->getJson(webMcpDiscoveryUrl($user, $workspace))->assertForbidden()->assertJsonPath('isError', true);
    $response = $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($user, $workspace, [
        'base_text' => 'must not create', 'destination' => ['kind' => 'all'],
    ]))->assertForbidden()->assertJsonPath('isError', true);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->assertDatabaseCount('posts', 0);
});

test('browser tools reject a stale workspace even when the user still belongs to both workspaces', function (): void {
    [$user, $workspace] = ownerActingIn();
    $other = Workspace::factory()->create();
    WorkspaceMembership::factory()->create(['user_id' => $user->id, 'workspace_id' => $other->id]);
    User::query()->whereKey($user->id)->update(['current_workspace_id' => $other->id]);

    $this->getJson(webMcpDiscoveryUrl($user, $workspace))->assertConflict()->assertJsonPath('isError', true);
    $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($user, $workspace, [
        'base_text' => 'stale tab', 'destination' => ['kind' => 'all'],
    ]))->assertConflict()->assertJsonPath('isError', true);
    $this->getJson(webMcpDiscoveryUrl($user, $other))->assertOk()->assertJsonPath('workspace_id', $other->id);

    $this->assertDatabaseCount('posts', 0);
});

test('browser tools reject a stale actor even when both sessions can access the same workspace', function (): void {
    [$oldUser, $workspace] = ownerActingIn();
    $newUser = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['user_id' => $newUser->id, 'workspace_id' => $workspace->id]);
    $this->actingAs($newUser);

    $this->getJson(webMcpDiscoveryUrl($oldUser, $workspace))->assertConflict()->assertJsonPath('isError', true);
    $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($oldUser, $workspace, [
        'base_text' => 'old actor', 'destination' => ['kind' => 'all'],
    ]))->assertConflict()->assertJsonPath('isError', true);

    $this->assertDatabaseCount('posts', 0);
});

test('browser tools reject another workspace envelope and arbitrary tool dispatch', function (): void {
    [$user, $workspace] = ownerActingIn();
    $other = Workspace::factory()->create();

    $this->getJson(webMcpDiscoveryUrl($user, $other))->assertForbidden();
    $this->postJson('/webmcp/tools/not-a-reviewed-tool', webMcpEnvelope($user, $workspace))
        ->assertNotFound()->assertJsonPath('isError', true);
    $this->postJson('/webmcp/tools/handle', webMcpEnvelope($user, $workspace))
        ->assertNotFound()->assertJsonPath('isError', true);

    $this->assertDatabaseCount('posts', 0);
});

test('browser read tools use the bound workspace without an OAuth token', function (): void {
    [$user, $workspace] = ownerActingIn();
    $own = Post::factory()->for($workspace)->create(['base_text' => 'this workspace']);
    Post::factory()->create(['base_text' => 'another workspace']);

    $result = $this->postJson(webMcpToolUrl(ListPostsTool::class), webMcpEnvelope($user, $workspace))
        ->assertOk()->assertJsonPath('isError', false);
    $posts = json_decode($result->json('content.0.text'), true, 512, JSON_THROW_ON_ERROR)['posts'];
    expect(array_column($posts, 'id'))->toBe([$own->id]);

    $result = $this->postJson(webMcpToolUrl(ListWorkspacesTool::class), webMcpEnvelope($user, $workspace))
        ->assertOk()->assertJsonPath('isError', false);
    expect(json_decode($result->json('content.0.text'), true, 512, JSON_THROW_ON_ERROR)['bound_workspace_id'])->toBe($workspace->id);
});

test('browser draft creation and update reuse the existing handlers and validation', function (): void {
    Http::preventStrayRequests();
    [$user, $workspace] = ownerActingIn();
    $result = $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($user, $workspace, [
        'base_text' => 'first draft', 'destination' => ['kind' => 'all'],
    ]))->assertOk()->assertJsonPath('isError', false)->assertJsonPath('content.0.type', 'text');
    $created = json_decode($result->json('content.0.text'), true, 512, JSON_THROW_ON_ERROR);

    $this->assertDatabaseHas('posts', [
        'id' => $created['id'], 'workspace_id' => $workspace->id, 'author_id' => $user->id, 'base_text' => 'first draft', 'status' => PostStatus::Draft->value,
    ]);
    $this->postJson(webMcpToolUrl(UpdatePostTool::class), webMcpEnvelope($user, $workspace, [
        'post_id' => $created['id'], 'base_text' => 'updated draft', 'destination' => ['kind' => 'all'],
    ]))->assertOk()->assertJsonPath('isError', false);
    $this->assertDatabaseHas('posts', ['id' => $created['id'], 'base_text' => 'updated draft', 'status' => PostStatus::Draft->value]);

    $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($user, $workspace, [
        'destination' => ['kind' => 'invalid'],
    ]))->assertUnprocessable()->assertJsonPath('isError', true);
    $this->assertDatabaseCount('posts', 1);
    Http::assertNothingSent();
});

test('browser tools cannot read or modify a foreign post by id', function (): void {
    [$user, $workspace] = ownerActingIn();
    $foreign = Post::factory()->create(['base_text' => 'untouched']);

    $this->postJson(webMcpToolUrl(GetPostTool::class), webMcpEnvelope($user, $workspace, ['post_id' => $foreign->id]))
        ->assertOk()->assertJsonPath('isError', true)->assertDontSee('untouched');
    $this->postJson(webMcpToolUrl(UpdatePostTool::class), webMcpEnvelope($user, $workspace, [
        'post_id' => $foreign->id, 'base_text' => 'changed', 'destination' => ['kind' => 'all'],
    ]))->assertOk()->assertJsonPath('isError', true);

    $this->assertDatabaseHas('posts', ['id' => $foreign->id, 'base_text' => 'untouched']);
});

test('browser publish and retry require literal confirmation before network or dispatch', function (mixed $confirm): void {
    Http::preventStrayRequests();
    Queue::fake();
    [$user, $workspace] = ownerActingIn();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::X, 'status' => PostTargetStatus::Failed,
    ]);
    $arguments = ['post_id' => $post->id, 'target_id' => $target->id];
    if ($confirm !== null) {
        $arguments['confirm'] = $confirm;
    }

    foreach ([PublishPostTool::class, RetryPostTargetTool::class] as $class) {
        $this->postJson(webMcpToolUrl($class), webMcpEnvelope($user, $workspace, $arguments))
            ->assertUnprocessable()->assertJsonPath('isError', true);
    }

    expect($post->fresh()->status)->toBe(PostStatus::Draft)
        ->and($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([null, false, 1, 'true', '1']);

test('browser schedule and delete require confirmation even when the original tool does not', function (): void {
    [$user, $workspace] = ownerActingIn();
    $post = Post::factory()->for($workspace)->create();
    $arguments = ['post_id' => $post->id, 'scheduled_at' => now()->addDay()->toIso8601String()];

    $this->postJson(webMcpToolUrl(SchedulePostTool::class), webMcpEnvelope($user, $workspace, $arguments))
        ->assertUnprocessable()->assertJsonPath('isError', true);
    $this->postJson(webMcpToolUrl(DeletePostTool::class), webMcpEnvelope($user, $workspace, ['post_id' => $post->id]))
        ->assertUnprocessable()->assertJsonPath('isError', true);
    expect($post->fresh()->status)->toBe(PostStatus::Draft)->and($post->fresh()->scheduled_at)->toBeNull();

    $this->postJson(webMcpToolUrl(SchedulePostTool::class), webMcpEnvelope($user, $workspace, [...$arguments, 'confirm' => true]))
        ->assertOk()->assertJsonPath('isError', false);
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('browser media deletion preserves the media row and files without confirmation', function (?bool $confirm): void {
    Storage::fake('public');
    [$user, $workspace] = ownerActingIn();
    $media = PostMedia::factory()->for($workspace)->create([
        'disk' => 'public',
        'path' => 'media/browser-delete.png',
        'mime' => 'image/png',
        'source_disk' => 'public',
        'source_path' => 'media/browser-delete-source.png',
    ]);
    $bytes = transparentPng();
    Storage::disk('public')->put($media->path, $bytes);
    Storage::disk('public')->put($media->source_path, $bytes);
    $arguments = ['media_id' => $media->id];
    if ($confirm !== null) {
        $arguments['confirm'] = $confirm;
    }

    $this->postJson(webMcpToolUrl(RemovePostMediaTool::class), webMcpEnvelope($user, $workspace, $arguments))
        ->assertUnprocessable()->assertJsonPath('isError', true);

    $this->assertDatabaseHas('post_media', ['id' => $media->id, 'workspace_id' => $workspace->id]);
    Storage::disk('public')->assertExists([$media->path, $media->source_path]);
    expect(Storage::disk('public')->get($media->path))->toBe($bytes)
        ->and(Storage::disk('public')->get($media->source_path))->toBe($bytes);
})->with(['missing confirmation' => [null], 'false confirmation' => [false]]);

test('confirmed browser publishing still respects the existing per-post checks', function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    [$user, $workspace] = ownerActingIn();
    $post = Post::factory()->for($workspace)->create();

    $this->postJson(webMcpToolUrl(PublishPostTool::class), webMcpEnvelope($user, $workspace, ['post_id' => $post->id, 'confirm' => true]))
        ->assertOk()->assertJsonPath('isError', true);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('ordinary MCP requests cannot borrow the browser session workspace', function (): void {
    [$user, $workspace] = ownerActingIn();
    $result = app()->call([app(CreatePostTool::class), 'handle'], ['request' => new McpRequest([
        'workspace_id' => $workspace->id, 'user_id' => $user->id,
        'base_text' => 'not browser bound', 'destination' => ['kind' => 'all'],
    ])]);

    expect($result->isError())->toBeTrue();
    $this->assertDatabaseCount('posts', 0);
});

test('browser mutation retains normal web CSRF protection', function (): void {
    [$user, $workspace] = ownerActingIn();
    $this->app->instance(PreventRequestForgery::class, new class($this->app, app(Encrypter::class)) extends PreventRequestForgery
    {
        #[Override]
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $payload = webMcpEnvelope($user, $workspace, ['base_text' => 'csrf protected', 'destination' => ['kind' => 'all']]);

    $this->postJson(webMcpToolUrl(CreatePostTool::class), $payload)->assertStatus(419);
    $this->assertDatabaseCount('posts', 0);

    $this->withSession(['_token' => 'browser-csrf-test-token'])
        ->withHeader('X-CSRF-TOKEN', 'browser-csrf-test-token')
        ->postJson(webMcpToolUrl(CreatePostTool::class), $payload)->assertOk()->assertJsonPath('isError', false);
    $this->assertDatabaseCount('posts', 1);
});

test('unexpected browser tool failures do not expose internal error details', function (): void {
    [$user, $workspace] = ownerActingIn();
    $this->mock(DraftService::class)->shouldReceive('createDraft')->once()->andThrow(new RuntimeException('private internal details'));

    $result = $this->postJson(webMcpToolUrl(CreatePostTool::class), webMcpEnvelope($user, $workspace, [
        'base_text' => 'cannot complete', 'destination' => ['kind' => 'all'],
    ]))->assertStatus(500)->assertJsonPath('isError', true)->assertDontSee('private internal details')->assertDontSee('trace');

    expect($result->headers->get('Cache-Control'))->toContain('no-store');
    $this->assertDatabaseCount('posts', 0);
});
