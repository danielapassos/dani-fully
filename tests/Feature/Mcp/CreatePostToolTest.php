<?php

use App\Enums\Platform;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('create_post creates a draft in the bound workspace', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $response = ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'my first draft',
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertOk()->assertSee('my first draft');
    expect(Post::query()->where('workspace_id', $workspace->id)->where('base_text', 'my first draft')->exists())->toBeTrue();
});

test('create_post persists declared publishing choices without filling missing declarations', function (Platform $platform, string $key, array $options): void {
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => $platform]);

    $response = ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'Declared publishing choices',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'content_override' => [$key => $options],
        ]],
    ]);

    $response->assertOk();
    $post = Post::query()->where('workspace_id', $workspace->id)->sole();
    expect($post->targets()->where('connected_account_id', $account->id)->sole()->content_override[$key])->toBe($options);
    Http::assertNothingSent();
})->with('declared publishing options');

test('create_post rejects invalid publishing declarations', function (Platform $platform, string $key, array $options): void {
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => $platform]);

    $response = ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'content_override' => [$key => $options],
        ]],
    ]);

    $response->assertHasErrors();
    expect(Post::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
    Http::assertNothingSent();
})->with('invalid publishing options');
