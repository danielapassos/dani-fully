<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\PublishPostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

test('publish_post_now requires confirmation', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create();

    $response = ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors();
    expect($post->fresh()->status)->not->toBe(PostStatus::Publishing);
});

test('publish_post_now with confirm sets status to publishing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X->value]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'status' => PostTargetStatus::Pending->value,
    ]);

    $response = ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'confirm' => true,
    ]);

    $response->assertOk();
    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
});
