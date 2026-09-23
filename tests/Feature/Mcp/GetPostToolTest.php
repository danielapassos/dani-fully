<?php

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\GetPostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

test('MCP get post returns a legacy inbox completion caption without a publishing request', function (): void {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    config()->set('services.tiktok.publishing_provider', 'accounts_api');
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'status' => PostTargetStatus::AwaitingAction,
        'sections' => ['Approved saved caption @woodchucksato'],
    ]);

    ShoutrrrServer::actingAs($user)->tool(GetPostTool::class, ['post_id' => $post->id])
        ->assertOk()->assertSee('manual_completion')->assertSee('Approved saved caption @woodchucksato')
        ->assertSee('Open the upload notification')->assertSee('Replace any prefilled caption');
    Http::assertNothingSent();
});

test('get_post returns a post in the bound workspace', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create(['base_text' => 'hello world']);

    $response = ShoutrrrServer::actingAs($user)
        ->tool(GetPostTool::class, ['post_id' => $post->id]);

    $response->assertOk()->assertSee('hello world');
});

test('get_post errors for a post in another workspace', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    // Create a post in a completely separate workspace (no Context set at this point).
    $foreign = Post::factory()->for(Workspace::factory())->create();

    $response = ShoutrrrServer::actingAs($user)
        ->tool(GetPostTool::class, ['post_id' => $foreign->id]);

    $response->assertHasErrors();
});
