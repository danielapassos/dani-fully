<?php

use App\Enums\ErrorKind;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

test('retry_post_target requires confirmation', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
    ]);

    $response->assertHasErrors();
    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
});

test('retry_post_target with confirm resets target to pending', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'confirm' => true,
    ]);

    $response->assertOk();
    expect($target->fresh()->status)->toBe(PostTargetStatus::Pending);
});

test('retry_post_target rejects a non-failed target', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Published->value]);

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'confirm' => true,
    ]);

    $response->assertHasErrors();
    expect($target->fresh()->status)->toBe(PostTargetStatus::Published);
});

test('retry_post_target requires manual review for an unconfirmed provider outcome', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create([
        'error_kind' => ErrorKind::Unknown->value,
    ]);

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'confirm' => true,
    ]);

    $response->assertHasErrors(['provider outcome is unconfirmed']);
    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Unknown);
    Queue::assertNotPushed(PublishPostTarget::class);
});

test('retry_post_target reruns publish prechecks before dispatch', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create([
        'connected_account_id' => $account->id,
        'sections' => [str_repeat('x', 281)],
    ]);

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'confirm' => true,
    ]);

    $response->assertHasErrors(["section is over X's length limit"]);
    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Queue::assertNotPushed(PublishPostTarget::class);
});
