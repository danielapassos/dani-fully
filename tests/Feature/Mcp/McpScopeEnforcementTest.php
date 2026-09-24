<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\AddPostMediaTool;
use App\Mcp\Tools\BeginVideoUploadTool;
use App\Mcp\Tools\CompleteVideoUploadTool;
use App\Mcp\Tools\CreateAccountSetTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\CreateShareLinkTool;
use App\Mcp\Tools\DeleteAccountSetTool;
use App\Mcp\Tools\DeletePostTool;
use App\Mcp\Tools\DeleteShareTool;
use App\Mcp\Tools\GetCalendarTool;
use App\Mcp\Tools\GetPostingScheduleTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListAccountAnalyticsTool;
use App\Mcp\Tools\ListAccountSetsTool;
use App\Mcp\Tools\ListConnectedAccountsTool;
use App\Mcp\Tools\ListPostAnalyticsTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListSharesTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\PublishPostTool;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\RefreshTikTokInboxTool;
use App\Mcp\Tools\RemovePostMediaTool;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\UpdateAccountSetTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

dataset('MCP mutating tools', [
    'create post' => CreatePostTool::class,
    'update post' => UpdatePostTool::class,
    'schedule post' => SchedulePostTool::class,
    'queue post' => QueuePostTool::class,
    'add media' => AddPostMediaTool::class,
    'begin video upload' => BeginVideoUploadTool::class,
    'complete video upload' => CompleteVideoUploadTool::class,
    'remove media' => RemovePostMediaTool::class,
    'create account set' => CreateAccountSetTool::class,
    'update account set' => UpdateAccountSetTool::class,
    'delete account set' => DeleteAccountSetTool::class,
    'create share' => CreateShareLinkTool::class,
    'delete share' => DeleteShareTool::class,
    'publish post' => PublishPostTool::class,
    'retry target' => RetryPostTargetTool::class,
    'refresh TikTok inbox' => RefreshTikTokInboxTool::class,
    'delete post' => DeletePostTool::class,
]);

test('read-only MCP grants cannot invoke any mutation even with confirmation', function (string $tool): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace, ['mcp:use', 'read']);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'Unchanged']);
    Queue::fake();
    Http::preventStrayRequests();

    ShoutrrrServer::actingAs($user)->tool($tool, [
        'post_id' => $post->id,
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'all'],
        'confirm' => true,
    ])->assertHasErrors()->assertSee('This MCP connection does not have write access.');

    expect($post->fresh()->base_text)->toBe('Unchanged')
        ->and($post->fresh()->status)->toBe(PostStatus::Draft)
        ->and(Post::withoutGlobalScopes()->count())->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with('MCP mutating tools');

test('MCP grants without explicit write permission cannot create a draft', function (array $scopes): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace, $scopes);

    ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'all'],
    ])->assertHasErrors()->assertSee('This MCP connection does not have write access.');

    expect(Post::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'no scopes' => [[]],
    'MCP use only' => [['mcp:use']],
]);

test('an authenticated user without an OAuth token cannot invoke an MCP mutation', function (): void {
    $user = User::factory()->create();

    ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'all'],
    ])->assertHasErrors()->assertSee('This MCP connection does not have write access.');

    expect(Post::withoutGlobalScopes()->count())->toBe(0);
});

test('read-only MCP grants retain access to every read tool', function (string $tool): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace, ['mcp:use', 'read']);
    $post = Post::factory()->for($workspace)->create();

    ShoutrrrServer::actingAs($user)->tool($tool, [
        'post_id' => $post->id,
        'month' => '2026-09',
    ])->assertOk();
})->with([
    'get post' => GetPostTool::class,
    'list workspaces' => ListWorkspacesTool::class,
    'list posts' => ListPostsTool::class,
    'calendar' => GetCalendarTool::class,
    'connected accounts' => ListConnectedAccountsTool::class,
    'account sets' => ListAccountSetsTool::class,
    'account analytics' => ListAccountAnalyticsTool::class,
    'post analytics' => ListPostAnalyticsTool::class,
    'posting schedule' => GetPostingScheduleTool::class,
    'shares' => ListSharesTool::class,
]);

test('the MCP discovery default scope retains read access without granting writes', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace, ['mcp:use']);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'Readable with MCP use']);

    ShoutrrrServer::actingAs($user)->tool(GetPostTool::class, [
        'post_id' => $post->id,
    ])->assertOk()->assertSee('Readable with MCP use');

    ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'all'],
    ])->assertHasErrors()->assertSee('This MCP connection does not have write access.');

    expect($post->fresh()->base_text)->toBe('Readable with MCP use');
});

test('write-granted workspace members can still edit drafts', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace, ['mcp:use', 'read', 'write']);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'Original']);

    ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'Authorized edit',
        'destination' => ['kind' => 'all'],
    ])->assertOk();

    expect($post->fresh()->base_text)->toBe('Authorized edit');
});

test('write scope does not bypass workspace isolation', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace, ['mcp:use', 'read', 'write']);
    $foreignPost = Post::factory()->for(Workspace::factory())->create(['base_text' => 'Foreign post']);

    ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $foreignPost->id,
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'all'],
    ])->assertHasErrors()->assertSee('No post with that id exists in this workspace.');

    expect($foreignPost->fresh()->base_text)->toBe('Foreign post');
});

test('read-only confirmation cannot queue deletion of a published post', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace, ['mcp:use', 'read']);
    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::Published]);
    PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'published-video',
    ]);
    Queue::fake();
    Http::preventStrayRequests();

    ShoutrrrServer::actingAs($user)->tool(DeletePostTool::class, [
        'post_id' => $post->id,
        'confirm' => true,
    ])->assertHasErrors()->assertSee('This MCP connection does not have write access.');

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($post->fresh()->deleted_at)->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('new tools require write access even without workspace binding or with a read-only hint', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace, ['mcp:use', 'read']);

    McpScopeTestServer::actingAs($user)->tool(UnclassifiedMcpScopeTool::class)
        ->assertHasErrors()
        ->assertSee('This MCP connection does not have write access.')
        ->assertDontSee('The unclassified handler ran.');
});

class McpScopeTestServer extends ShoutrrrServer
{
    protected array $tools = [UnclassifiedMcpScopeTool::class];
}

#[IsReadOnly]
class UnclassifiedMcpScopeTool extends Tool
{
    public function handle(): Response
    {
        return Response::text('The unclassified handler ran.');
    }
}
