<?php

use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\BeginVideoUploadTool;
use App\Mcp\Tools\CompleteVideoUploadTool;
use App\Models\McpGrantWorkspace;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\VideoUploadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function mcpVideoUploadInput(): array
{
    return ['content_type' => 'video/mp4', 'size_bytes' => 1024, 'width' => 2160, 'height' => 3840, 'duration_seconds' => 42, 'sha256' => str_repeat('a', 64)];
}

test('video upload MCP tool names match the reusable client contract', function () {
    expect(app(BeginVideoUploadTool::class)->name())->toBe('begin_video_upload')
        ->and(app(CompleteVideoUploadTool::class)->name())->toBe('complete_video_upload');
});

test('MCP video uploads use the bound workspace and authenticated writer without creating posts', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => Workspace::factory()->create()->id])->save();
    bindTokenToWorkspace($user, $workspace);
    $uploadId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();
    Queue::fake();
    $service = $this->mock(VideoUploadService::class);
    $service->shouldReceive('begin')->once()->with($workspace->id, $user->id, mcpVideoUploadInput())->andReturn([
        'upload_id' => $uploadId, 'url' => 'https://storage.example.test/signed-upload',
        'headers' => ['Content-Type' => 'video/mp4'], 'expires_at' => now()->addMinutes(15)->toISOString(), 'max_size_bytes' => 4000000000,
    ]);
    $service->shouldReceive('complete')->once()->with($workspace->id, $user->id, $uploadId)->andReturn([
        'id' => $mediaId, 'mime' => 'video/mp4', 'kind' => 'video', 'size_bytes' => 1024,
        'width' => 2160, 'height' => 3840, 'duration_seconds' => 42, 'sha256' => str_repeat('a', 64),
    ]);

    ShoutrrrServer::actingAs($user)->tool(BeginVideoUploadTool::class, [
        ...mcpVideoUploadInput(), 'workspace_id' => 'ignored', 'user_id' => 'ignored',
    ])->assertOk()->assertSee($uploadId);
    ShoutrrrServer::actingAs($user)->tool(CompleteVideoUploadTool::class, ['upload_id' => $uploadId])
        ->assertOk()->assertSee($mediaId)->assertSee('3840');

    expect(Post::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('MCP video tools reject absent workspace bindings and revoked memberships', function (string $tool, string $revocation) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    if ($revocation === 'binding') {
        McpGrantWorkspace::query()->where('user_id', $user->id)->delete();
    } else {
        $workspace->members()->where('user_id', $user->id)->delete();
    }
    $this->mock(VideoUploadService::class)->shouldNotReceive('begin', 'complete');

    ShoutrrrServer::actingAs($user)->tool($tool, [...mcpVideoUploadInput(), 'upload_id' => (string) Str::uuid()])
        ->assertHasErrors()->assertSee('not bound to a workspace');
})->with([BeginVideoUploadTool::class, CompleteVideoUploadTool::class])->with(['binding', 'membership']);

test('MCP upload metadata is validated before issuing a signed URL', function (array $invalid) {
    $user = User::factory()->create();
    bindTokenToWorkspace($user, Workspace::factory()->create());
    $this->mock(VideoUploadService::class)->shouldNotReceive('begin');

    ShoutrrrServer::actingAs($user)->tool(BeginVideoUploadTool::class, array_replace(mcpVideoUploadInput(), $invalid))->assertHasErrors();
})->with([
    [['content_type' => 'video/quicktime']], [['size_bytes' => 4000000001]],
    [['width' => 0]], [['height' => -1]], [['duration_seconds' => 1.5]], [['sha256' => 'not-a-checksum']],
    [['alt_text' => str_repeat('a', 256)]],
]);

test('MCP completion hides foreign upload identifiers and handles expiry without leaking storage errors', function (int $status) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $uploadId = (string) Str::uuid();
    $exception = $status === 404 ? new ModelNotFoundException('private-storage-path') : new HttpException($status, 'The video upload has expired. Begin a new upload.');
    $this->mock(VideoUploadService::class)->shouldReceive('complete')->once()->with($workspace->id, $user->id, $uploadId)->andThrow($exception);

    ShoutrrrServer::actingAs($user)->tool(CompleteVideoUploadTool::class, ['upload_id' => $uploadId])
        ->assertHasErrors()->assertDontSee('private-storage-path')
        ->assertSee($status === 404 ? 'No video upload with that id exists' : 'expired');
})->with([404, 410]);

test('MCP completion requires a UUID rather than a storage path or URL', function () {
    $user = User::factory()->create();
    bindTokenToWorkspace($user, Workspace::factory()->create());
    $this->mock(VideoUploadService::class)->shouldNotReceive('complete');

    ShoutrrrServer::actingAs($user)->tool(CompleteVideoUploadTool::class, ['upload_id' => '../../private.mp4'])->assertHasErrors();
});
