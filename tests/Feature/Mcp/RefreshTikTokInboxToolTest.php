<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\RefreshTikTokInboxTool;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    bindTokenToWorkspace($this->user, $this->workspace);
    $account = ConnectedAccount::factory()->for($this->workspace)->create(['platform' => Platform::TikTok, 'token_expires_at' => now()->addHour()]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'token']);
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::AwaitingAction]);
    $this->target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::TikTok, 'status' => PostTargetStatus::AwaitingAction,
        'connected_account_id' => $account->id,
        'media_upload_state' => ['media-id' => ['remote_ref' => 'v_inbox_file~v2.123', 'metadata' => ['publish_mode' => 'inbox']]],
    ]);
});

test('MCP checks existing inbox status without publish confirmation and returns PostView tracking', function (): void {
    Http::fake([
        '*/status/fetch/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7688668628333643789']]]),
        '*/video/query/*' => Http::response(['error' => ['code' => 'ok'], 'data' => ['videos' => [['id' => '7688668628333643789', 'share_url' => 'https://www.tiktok.com/@mommygorl/video/7688668628333643789']]]]),
    ]);
    ShoutrrrServer::actingAs($this->user)->tool(RefreshTikTokInboxTool::class, ['post_id' => $this->target->post_id, 'target_id' => $this->target->id])
        ->assertOk()->assertSee('inbox_tracking')->assertSee('public_posts')->assertSee('7688668628333643789')->assertSee('verified_at');
    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Published);
    Http::assertSentCount(2);
    Queue::assertNothingPushed();
});

test('MCP refresh refuses foreign post or target ids', function (bool $foreignPost): void {
    $foreign = PostTarget::factory()->create();
    ShoutrrrServer::actingAs($this->user)->tool(RefreshTikTokInboxTool::class, [
        'post_id' => $foreignPost ? $foreign->post_id : $this->target->post_id,
        'target_id' => $foreign->id,
    ])->assertHasErrors();
    Http::assertNothingSent();
})->with([true, false]);

test('MCP refresh refuses removed membership and does not trust current web workspace', function (): void {
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    WorkspaceMembership::query()->where('user_id', $this->user->id)->where('workspace_id', $this->workspace->id)->delete();
    ShoutrrrServer::actingAs($this->user)->tool(RefreshTikTokInboxTool::class, ['post_id' => $this->target->post_id, 'target_id' => $this->target->id])->assertHasErrors();
    Http::assertNothingSent();
});

test('MCP refresh rejects unbound tokens and pending targets without saved uploads', function (): void {
    $this->target->update(['status' => PostTargetStatus::Pending, 'media_upload_state' => null]);
    ShoutrrrServer::actingAs($this->user)->tool(RefreshTikTokInboxTool::class, ['post_id' => $this->target->post_id, 'target_id' => $this->target->id])->assertHasErrors();
    $unbound = User::factory()->create();
    ShoutrrrServer::actingAs($unbound)->tool(RefreshTikTokInboxTool::class, ['post_id' => $this->target->post_id, 'target_id' => $this->target->id])->assertHasErrors();
    Http::assertNothingSent();
});
