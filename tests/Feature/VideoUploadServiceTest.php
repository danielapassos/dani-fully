<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\VideoUploadSession;
use App\Models\Workspace;
use App\Services\Posts\VideoUploadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config(['filesystems.default' => 'local', 'app.url' => 'https://shoutrrr.test']);
    Storage::fake('local');
});

function signedVideoBytes(string $body = 'unchanged original video bytes'): string
{
    return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2".$body;
}

/** @return array{0: User, 1: Workspace, 2: array<string, mixed>} */
function signedVideoSession(array $input = []): array
{
    [$user, $workspace] = ownerActingIn();
    $bytes = signedVideoBytes();
    $signed = app(VideoUploadService::class)->begin($workspace->id, $user->id, [
        'content_type' => 'video/mp4', 'size_bytes' => strlen($bytes),
        'width' => 7680, 'height' => 4320, 'duration_seconds' => 17,
        'sha256' => hash('sha256', $bytes), ...$input,
    ]);

    return [$user, $workspace, $signed];
}

test('signs a workspace and actor bound expiring upload without exposing a final path', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);

    expect($signed['url'])->toStartWith('https://shoutrrr.test/uploads/stream/tmp/media/'.$workspace->id.'/')
        ->toContain('signature=')
        ->and($signed['expires_at'])->toBe($session->expires_at->toISOString())
        ->and($signed['max_size_bytes'])->toBe(Platform::maxVideoBytesCeiling())
        ->and($signed['size_bytes'])->toBe(strlen(signedVideoBytes()))
        ->and($session->expires_at->diffInSeconds(now(), absolute: true))->toBeGreaterThanOrEqual(899)
        ->and($session->user_id)->toBe($user->id)
        ->and($session->workspace_id)->toBe($workspace->id)
        ->and($session->disk)->toBe('local')
        ->and($session->temporary_path)->toStartWith('tmp/media/'.$workspace->id.'/')
        ->and($session->final_path)->toStartWith('media/'.$workspace->id.'/')
        ->and(json_encode($signed))->not->toContain($session->final_path)
        ->and(PostMedia::count())->toBe(0);
});

test('finalizes original high resolution bytes once with observed hash and no draft attachment', function (): void {
    [$user, $workspace, $signed] = signedVideoSession(['sha256' => strtoupper(hash('sha256', signedVideoBytes())), 'alt_text' => 'Original 8K clip']);
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());

    $result = app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    $again = app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    $media = PostMedia::findOrFail($result['id']);

    expect($again)->toBe($result)
        ->and($result)->toMatchArray(['kind' => 'video', 'mime' => 'video/mp4', 'width' => 7680, 'height' => 4320,
            'duration_seconds' => 17, 'size_bytes' => strlen(signedVideoBytes()), 'alt_text' => 'Original 8K clip',
            'sha256' => hash('sha256', signedVideoBytes()), 'observed_sha256' => hash('sha256', signedVideoBytes())])
        ->and($media->post_id)->toBeNull()
        ->and($media->workspace_id)->toBe($workspace->id)
        ->and(Storage::disk('local')->get($media->path))->toBe(signedVideoBytes())
        ->and(Storage::disk('local')->getVisibility($media->path))->toBe('private')
        ->and(PostMedia::count())->toBe(1)
        ->and($session->fresh()->completed_at)->not->toBeNull();
});

test('reusing a still valid PUT after completion cannot modify finalized media', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());
    $result = app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes('replacement bytes'));

    expect(app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))->toBe($result)
        ->and(Storage::disk('local')->get($session->final_path))->toBe(signedVideoBytes())
        ->and(PostMedia::count())->toBe(1);
});

test('computes an observed digest when no optional checksum was supplied', function (): void {
    [$user, $workspace, $signed] = signedVideoSession(['sha256' => null]);
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());

    $result = app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    expect($result['observed_sha256'])->toBe(hash('sha256', signedVideoBytes()));
});

test('rejects foreign workspaces and other members before reading an upload', function (string $scope): void {
    [$user, $workspace, $signed] = signedVideoSession();
    [$other, $otherWorkspace] = ownerActingIn();
    if ($scope === 'actor') {
        $workspace->members()->create(['user_id' => $other->id, 'role' => 'member']);
    }
    expect(fn () => app(VideoUploadService::class)->complete($scope === 'workspace' ? $otherWorkspace->id : $workspace->id, $other->id, $signed['upload_id']))
        ->toThrow(ModelNotFoundException::class);
    expect(PostMedia::count())->toBe(0);
})->with(['actor', 'workspace']);

test('rejects invalid begin declarations before storing an upload session', function (array $input): void {
    expect(fn () => signedVideoSession($input))->toThrow(ValidationException::class);
    expect(VideoUploadSession::count())->toBe(0);
})->with([
    [['content_type' => 'video/quicktime']], [['size_bytes' => 0]], [['size_bytes' => 4_000_000_001]],
    [['width' => 0]], [['height' => -1]], [['duration_seconds' => 2.5]], [['sha256' => 'invalid']],
    [['alt_text' => str_repeat('x', 256)]],
]);

test('refuses an explicitly public storage disk', function (): void {
    config(['filesystems.disks.local.visibility' => 'public']);
    expect(fn () => signedVideoSession())->toThrow(HttpException::class, 'Configure private video storage');
    expect(VideoUploadSession::count())->toBe(0);
});

test('cannot finish an expired upload but allows idempotent completed reads after expiry', function (bool $completed): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());
    $result = $completed ? app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id) : null;
    $this->travel(16)->minutes();
    if ($completed) {
        expect(app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))->toBe($result);
    } else {
        expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
            ->toThrow(HttpException::class, 'expired');
        expect(PostMedia::count())->toBe(0);
    }
})->with([false, true]);

test('revoked membership blocks both new uploads and completion including completed sessions', function (bool $completed): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());
    if ($completed) {
        app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    }
    $workspace->members()->where('user_id', $user->id)->delete();

    expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
        ->toThrow(HttpException::class, 'no longer allowed');
    expect(fn () => app(VideoUploadService::class)->begin($workspace->id, $user->id, [
        'content_type' => 'video/mp4', 'size_bytes' => 64, 'width' => 1, 'height' => 1, 'duration_seconds' => 1,
    ]))->toThrow(HttpException::class, 'no longer allowed');
})->with([false, true]);

test('a missing or incomplete PUT can be completed after uploading the correct object', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
        ->toThrow(ValidationException::class);
    Storage::disk('local')->put($session->temporary_path, substr(signedVideoBytes(), 0, -1));
    expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
        ->toThrow(ValidationException::class);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());
    $result = app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    expect($result['sha256'])->toBe(hash('sha256', signedVideoBytes()))->and(PostMedia::count())->toBe(1);
});

test('rejects changed bytes and image containers without keeping a final snapshot', function (string $bytes, ?string $digest): void {
    [$user, $workspace, $signed] = signedVideoSession(['size_bytes' => strlen($bytes), 'sha256' => $digest]);
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, $bytes);

    expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
        ->toThrow(ValidationException::class);
    expect(PostMedia::count())->toBe(0)
        ->and($session->fresh()->completed_at)->toBeNull()
        ->and(Storage::disk('local')->exists($session->final_path))->toBeFalse();
})->with([
    'changed checksum' => [signedVideoBytes('same file length but modified'), hash('sha256', signedVideoBytes())],
    'arbitrary bytes' => [str_repeat('x', 60), null],
    'avif image' => ["\x00\x00\x00\x18ftypavif\x00\x00\x00\x00avifmif1".str_repeat('x', 20), null],
    'invalid box length' => ["\x00\x00\xFF\xFFftypisom\x00\x00\x00\x00".str_repeat('x', 20), null],
]);

test('checks the copied snapshot even if staging changes during its copy', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    $disk = Storage::disk('local');
    $disk->put($session->temporary_path, signedVideoBytes());
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('copy')->once()->andReturnUsing(function (string $from, string $to) use ($disk): bool {
        $disk->put($from, signedVideoBytes('mutated at the copy boundary'));

        return $disk->copy($from, $to);
    });
    Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

    expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
        ->toThrow(ValidationException::class);
    expect(PostMedia::count())->toBe(0)->and($disk->exists($session->final_path))->toBeFalse();
});

test('a failed copy is sanitized and retrying the same session creates only one media record', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    $disk = Storage::disk('local');
    $disk->put($session->temporary_path, signedVideoBytes());
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('copy')->once()->andReturnUsing(function (string $from, string $to) use ($disk): never {
        $disk->put($to, 'partial');
        throw new RuntimeException('Secret presigned URL must not escape');
    });
    Storage::shouldReceive('disk')->with('local')->andReturn($proxy, $disk);

    expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
        ->toThrow(HttpException::class, 'Video storage is unavailable. Retry completing the same upload.');
    expect(PostMedia::count())->toBe(0)->and($disk->exists($session->final_path))->toBeFalse();
    app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    expect(PostMedia::count())->toBe(1);
});

test('a deleted completed media cannot be recreated by replaying completion', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());
    $result = app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    PostMedia::findOrFail($result['id'])->delete();

    expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
        ->toThrow(HttpException::class, 'no longer available unchanged');
    expect(PostMedia::count())->toBe(0);
});

test('expired upload cleanup removes interrupted snapshots but keeps completed media', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $completed = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($completed->temporary_path, signedVideoBytes());
    $result = app(VideoUploadService::class)->complete($workspace->id, $user->id, $completed->id);
    $interrupted = VideoUploadSession::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    Storage::disk('local')->put($interrupted->temporary_path, 'staged');
    Storage::disk('local')->put($interrupted->final_path, 'interrupted snapshot');
    $this->travel(7)->hours();

    expect(app(VideoUploadService::class)->pruneExpired())->toBe(2)
        ->and(VideoUploadSession::count())->toBe(0)
        ->and(PostMedia::find($result['id']))->not->toBeNull()
        ->and(Storage::disk('local')->exists($completed->final_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($completed->temporary_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($interrupted->final_path))->toBeFalse();
});

test('recent sessions and failed storage cleanup keep their durable recovery state', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    expect(app(VideoUploadService::class)->pruneExpired())->toBe(0);
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    $this->travel(7)->hours();
    $proxy = Mockery::mock(Storage::disk('local'));
    $proxy->shouldReceive('delete')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

    expect(app(VideoUploadService::class)->pruneExpired())->toBe(0)
        ->and(VideoUploadSession::find($session->id))->not->toBeNull();
});

test('object storage signs the expected MP4 type and byte count without an ACL requirement', function (): void {
    config(['filesystems.default' => 's3']);
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('temporaryUploadUrl')->once()->withArgs(function (string $key, DateTimeInterface $expires, array $options): bool {
        expect($key)->toStartWith('tmp/media/')->toEndWith('.mp4')
            ->and($options)->toBe(['ContentType' => 'video/mp4', 'ContentLength' => strlen(signedVideoBytes())]);

        return true;
    })->andReturn(['url' => 'https://private.example.test/tmp/media/signed.mp4?signature=private', 'headers' => ['Content-Type' => ['video/mp4'], 'Content-Length' => [(string) strlen(signedVideoBytes())]]]);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

    [, , $signed] = signedVideoSession();
    expect($signed['headers'])->toBe(['Content-Type' => 'video/mp4', 'Content-Length' => (string) strlen(signedVideoBytes())]);
});

test('signing failures never return provider details or retain a usable session', function (): void {
    config(['filesystems.default' => 's3']);
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('temporaryUploadUrl')->once()->andThrow(new RuntimeException('secret credentials'));
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

    expect(fn () => signedVideoSession())->toThrow(HttpException::class, 'Video upload signing is unavailable. Try again later.');
    expect(VideoUploadSession::count())->toBe(0);
});

test('completion uses the session storage disk after the installation default changes', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());
    config(['filesystems.default' => 's3']);

    $result = app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    expect(PostMedia::findOrFail($result['id'])->disk)->toBe('local')
        ->and(Storage::disk('local')->get($session->final_path))->toBe(signedVideoBytes());
});

test('membership removal during storage work aborts completion and clears its snapshot', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    $disk = Storage::disk('local');
    $disk->put($session->temporary_path, signedVideoBytes());
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('copy')->once()->andReturnUsing(function (string $from, string $to) use ($disk, $user, $workspace): bool {
        $workspace->members()->where('user_id', $user->id)->delete();

        return $disk->copy($from, $to);
    });
    Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

    expect(fn () => app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id))
        ->toThrow(HttpException::class, 'no longer allowed');
    expect(PostMedia::count())->toBe(0)->and($disk->exists($session->final_path))->toBeFalse();
});

test('streaming checksum validation preserves bytes larger than a processing chunk', function (): void {
    $bytes = signedVideoBytes(str_repeat('original', 300000));
    [$user, $workspace, $signed] = signedVideoSession(['size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, $bytes);

    $result = app(VideoUploadService::class)->complete($workspace->id, $user->id, $session->id);
    expect($result['sha256'])->toBe(hash('sha256', $bytes))
        ->and(Storage::disk('local')->get($session->final_path))->toBe($bytes);
});

test('the existing scheduled prune cleans up expired interrupted upload sessions', function (): void {
    [$user, $workspace, $signed] = signedVideoSession();
    $session = VideoUploadSession::findOrFail($signed['upload_id']);
    Storage::disk('local')->put($session->temporary_path, signedVideoBytes());
    Storage::disk('local')->put($session->final_path, 'abandoned snapshot');
    $this->travel(7)->hours();

    $this->artisan('media:prune-uploads')->assertSuccessful();

    expect(VideoUploadSession::find($session->id))->toBeNull()
        ->and(Storage::disk('local')->exists($session->temporary_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($session->final_path))->toBeFalse();
});
