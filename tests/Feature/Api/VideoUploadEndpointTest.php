<?php

use App\Models\Post;
use App\Models\Workspace;
use App\Services\Posts\VideoUploadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function apiVideoUploadInput(): array
{
    return ['content_type' => 'video/mp4', 'size_bytes' => 1024, 'width' => 2160, 'height' => 3840, 'duration_seconds' => 42, 'sha256' => str_repeat('a', 64)];
}

test('REST video uploads use the API key workspace and preserve high resolution metadata without posting', function () {
    [$user, $workspace, $token] = issuedKey();
    $user->forceFill(['current_workspace_id' => Workspace::factory()->create()->id])->save();
    $uploadId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();
    Queue::fake();
    $service = $this->mock(VideoUploadService::class);
    $service->shouldReceive('begin')->once()->with($workspace->id, $user->id, apiVideoUploadInput())->andReturn([
        'upload_id' => $uploadId, 'url' => 'https://storage.example.test/signed-upload',
        'headers' => ['Content-Type' => 'video/mp4'], 'expires_at' => now()->addMinutes(15)->toISOString(), 'max_size_bytes' => 4000000000,
    ]);
    $service->shouldReceive('complete')->once()->with($workspace->id, $user->id, $uploadId)->andReturn([
        'id' => $mediaId, 'mime' => 'video/mp4', 'kind' => 'video', 'size_bytes' => 1024,
        'width' => 2160, 'height' => 3840, 'duration_seconds' => 42, 'sha256' => str_repeat('a', 64),
    ]);

    $this->withToken($token)->postJson('/api/v1/media/video-uploads', [
        ...apiVideoUploadInput(), 'workspace_id' => 'ignored', 'user_id' => 'ignored',
    ])->assertCreated()->assertJsonPath('upload_id', $uploadId)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->withToken($token)->postJson("/api/v1/media/video-uploads/{$uploadId}/complete")
        ->assertOk()->assertJsonPath('id', $mediaId)->assertJsonPath('width', 2160)->assertJsonPath('height', 3840);
    expect(Post::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('REST read-only API keys cannot begin or complete video uploads', function (string $suffix) {
    [, , $token] = issuedKey('read');
    $this->mock(VideoUploadService::class)->shouldNotReceive('begin', 'complete');

    $this->withToken($token)->postJson('/api/v1/media/video-uploads'.$suffix, apiVideoUploadInput())->assertForbidden();
})->with(['', '/00000000-0000-4000-8000-000000000001/complete']);

test('REST upload access follows live membership and posting policy', function (string $restriction, string $suffix) {
    [$user, $workspace, $token] = issuedKey();
    if ($restriction === 'revoked') {
        $workspace->members()->where('user_id', $user->id)->delete();
    } else {
        config()->set('kit.workspaces.roles.admin.permissions', []);
    }
    $this->mock(VideoUploadService::class)->shouldNotReceive('begin', 'complete');

    $this->withToken($token)->postJson('/api/v1/media/video-uploads'.$suffix, apiVideoUploadInput())->assertForbidden();
})->with(['revoked', 'policy'])->with(['', '/00000000-0000-4000-8000-000000000001/complete']);

test('REST invalid video metadata does not issue an upload', function (array $invalid, string $field) {
    [, , $token] = issuedKey();
    $this->mock(VideoUploadService::class)->shouldNotReceive('begin');

    $this->withToken($token)->postJson('/api/v1/media/video-uploads', array_replace(apiVideoUploadInput(), $invalid))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['content_type' => 'video/quicktime'], 'content_type'], [['size_bytes' => 0], 'size_bytes'],
    [['size_bytes' => 4000000001], 'size_bytes'], [['duration_seconds' => 0], 'duration_seconds'],
    [['width' => 0], 'width'], [['height' => 0], 'height'], [['sha256' => 'invalid'], 'sha256'],
    [['alt_text' => str_repeat('a', 256)], 'alt_text'],
]);

test('REST upload completion preserves safe service status codes', function (int $status) {
    [$user, $workspace, $token] = issuedKey();
    $uploadId = (string) Str::uuid();
    $exception = $status === 404 ? new ModelNotFoundException : new HttpException($status, 'Video upload is unavailable.');
    $this->mock(VideoUploadService::class)->shouldReceive('complete')->once()->with($workspace->id, $user->id, $uploadId)->andThrow($exception);

    $this->withToken($token)->postJson("/api/v1/media/video-uploads/{$uploadId}/complete")->assertStatus($status);
})->with([404, 410, 503]);

test('REST video upload endpoints require authentication', function (string $suffix) {
    $this->mock(VideoUploadService::class)->shouldNotReceive('begin', 'complete');

    $this->postJson('/api/v1/media/video-uploads'.$suffix, apiVideoUploadInput())->assertUnauthorized();
})->with(['', '/00000000-0000-4000-8000-000000000001/complete']);
