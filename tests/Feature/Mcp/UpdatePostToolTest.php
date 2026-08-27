<?php

use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;

test('update_post edits a draft', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create(['base_text' => 'old']);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'new text',
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertOk()->assertSee('new text');
    expect($post->fresh()->base_text)->toBe('new text');
});

test('update_post reports a stale write conflict', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create(['base_text' => 'old']);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'new',
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => '2000-01-01T00:00:00+00:00', // wrong → stale
    ]);

    $response->assertHasErrors();
});

test('update_post preserves an explicit empty per-account media selection', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create(['base_text' => 'old']);
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $media = PostMedia::factory()->for($workspace)->create();

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'First\nSecond',
        'segments' => ['First', 'Second'],
        'destination' => ['kind' => 'all'],
        'media_ids' => [$media->id],
        'segment_breaks' => ['break-1'],
        'placements' => [[
            'media_id' => $media->id,
            'segment_ref' => '__head__',
            'position' => 0,
        ]],
        'targets' => [[
            'connected_account_id' => $account->id,
            'segment_breaks' => ['break-1'],
            'placements' => [],
        ]],
    ]);

    $response->assertOk();

    $target = $post->targets()->where('connected_account_id', $account->id)->sole();
    expect($target->placements_explicit)->toBeTrue()
        ->and($target->placements()->count())->toBe(0)
        ->and($post->media()->pluck('post_media.id')->all())->toBe([$media->id]);
});
