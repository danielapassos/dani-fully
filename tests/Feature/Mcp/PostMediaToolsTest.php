<?php

use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\AddPostMediaTool;
use App\Mcp\Tools\RemovePostMediaTool;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('add_post_media downloads a public image into the workspace', function (): void {
    Storage::fake('public');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    // A public IP avoids external DNS; every HTTP request is intercepted locally.
    Http::preventStrayRequests();
    Http::fake(['https://1.1.1.1/a.png' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $response = ShoutrrrServer::actingAs($user)->tool(AddPostMediaTool::class, [
        'url' => 'https://1.1.1.1/a.png', 'alt_text' => 'a dot',
    ]);

    $response->assertOk();
    expect(PostMedia::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('remove_post_media deletes a media row in the workspace', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $media = PostMedia::factory()->for($workspace)->create();

    $response = ShoutrrrServer::actingAs($user)->tool(RemovePostMediaTool::class, ['media_id' => $media->id]);

    $response->assertOk();
    expect(PostMedia::find($media->id))->toBeNull();
});
