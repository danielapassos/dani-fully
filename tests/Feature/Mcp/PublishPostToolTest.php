<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\PublishPostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('MCP inbox submission confirms video only and returns manual caption instructions without claiming publication', function (bool $mixed): void {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('services.tiktok.publishing_provider', 'native');
    config()->set('services.tiktok.inbox_enabled', true);
    config()->set('services.tiktok.direct_post_enabled', false);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'Base caption']);
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.upload']],
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'sections' => ["Approved TikTok caption\n@woodchucksato"],
    ]);
    PostMedia::factory()->for($workspace)->video()->create(['post_id' => $post->id, 'duration_seconds' => 13]);
    if ($mixed) {
        $other = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X]);
        PostTarget::factory()->for($post)->create(['connected_account_id' => $other->id, 'platform' => Platform::X]);
    }

    ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors()->assertSee('manual completion')->assertDontSee('This will publicly publish');
    Queue::assertNothingPushed();

    ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, ['post_id' => $post->id, 'confirm' => true])
        ->assertOk()
        ->assertSee($mixed ? 'other targets follow their selected publishing settings' : 'Delivery is not yet confirmed')
        ->assertSee('manual_completion')->assertSee('Approved TikTok caption')->assertSee('@woodchucksato')
        ->assertSee('Replace any prefilled caption');
    Http::assertNothingSent();
})->with([false, true]);

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

test('publish_post_now rejects a failed post and points to guarded target retry', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::Failed->value]);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X->value]);
    $target = PostTarget::factory()->for($post)->failed()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
    ]);

    $response = ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'confirm' => true,
    ]);

    $response->assertHasErrors();
    expect($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Queue::assertNotPushed(PublishPostTarget::class);
});

test('MCP accepts photo inbox submission with caption and music reminder without claiming publication', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set(['services.tiktok.publishing_provider' => 'native', 'services.tiktok.inbox_enabled' => true, 'services.tiktok.direct_post_enabled' => false]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok, 'capabilities' => ['oauth_scopes' => ['video.upload']]]);
    PostTarget::factory()->for($post)->create(['connected_account_id' => $account->id, 'platform' => Platform::TikTok, 'sections' => ['Approved photo caption'], 'content_override' => ['music' => ['track' => 'Artist - Song']]]);
    PostMedia::factory()->for($workspace)->count(4)->create(['post_id' => $post->id]);
    ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, ['post_id' => $post->id, 'confirm' => true])
        ->assertOk()->assertSee('Approved photo caption')->assertSee('Artist - Song')
        ->assertSee('"media_type": "photo"')->assertSee('"caption_included": true');
    expect($post->fresh()->published_at)->toBeNull();
    Http::assertNothingSent();
});
