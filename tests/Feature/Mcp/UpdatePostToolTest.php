<?php

use App\Enums\Platform;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

test('update_post persists declared publishing choices without filling missing declarations', function (Platform $platform, string $key, array $options): void {
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'old']);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => $platform]);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'Declared publishing choices',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'content_override' => [$key => $options],
        ]],
    ]);

    $response->assertOk();
    expect($post->targets()->where('connected_account_id', $account->id)->sole()->content_override[$key])->toBe($options);
    Http::assertNothingSent();
})->with('declared publishing options');

test('update_post rejects invalid publishing declarations without changing the draft', function (Platform $platform, string $key, array $options): void {
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'Unchanged']);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => $platform]);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'content_override' => [$key => $options],
        ]],
    ]);

    $response->assertHasErrors();
    expect($post->fresh()->base_text)->toBe('Unchanged')
        ->and($post->targets()->count())->toBe(0);
    Http::assertNothingSent();
})->with('invalid publishing options');

test('update_post caption edits preserve omitted TikTok choices and honor explicit replacements', function (array $stored, array $override, array $expected): void {
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'Original']);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'content_override' => ['segments' => ['Original'], 'tiktok' => $stored],
    ]);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'Edited',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'content_override' => $override,
        ]],
    ]);

    $response->assertOk();
    expect($target->fresh()->content_override['tiktok'])->toBe($expected)
        ->and($target->fresh()->sections)->toBe(['Edited']);
    Http::assertNothingSent();
})->with('TikTok publishing settings edits');

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
