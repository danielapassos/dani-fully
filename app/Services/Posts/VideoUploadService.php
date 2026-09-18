<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\Platform;
use App\Models\PostMedia;
use App\Models\VideoUploadSession;
use App\Models\WorkspaceMembership;
use App\Support\FileStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class VideoUploadService
{
    /** @return array<string, list<string>> */
    public static function beginRules(): array
    {
        return [
            'content_type' => ['required', 'string', 'in:video/mp4'],
            'size_bytes' => ['required', 'integer', 'min:12', 'max:'.Platform::maxVideoBytesCeiling()],
            'width' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'height' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'sha256' => ['nullable', 'string', 'regex:/\A[0-9a-fA-F]{64}\z/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{upload_id: string, url: string, headers: array<string, string>, expires_at: string, max_size_bytes: int, size_bytes: int, sha256: string|null}
     */
    public function begin(string $workspaceId, string $userId, array $validated): array
    {
        $input = Validator::make($validated, self::beginRules())->validate();
        $disk = FileStorage::diskName();
        $this->requirePrivateDisk($disk);

        return DB::transaction(function () use ($workspaceId, $userId, $input, $disk): array {
            $this->requireMembership($workspaceId, $userId);
            $session = VideoUploadSession::create([
                'workspace_id' => $workspaceId, 'user_id' => $userId, 'disk' => $disk,
                'temporary_path' => 'tmp/media/'.$workspaceId.'/'.Str::uuid().'.mp4',
                'final_path' => 'media/'.$workspaceId.'/'.Str::uuid().'.mp4',
                'size_bytes' => (int) $input['size_bytes'],
                'width' => (int) $input['width'], 'height' => (int) $input['height'],
                'duration_seconds' => (int) $input['duration_seconds'],
                'alt_text' => $input['alt_text'] ?? null,
                'expected_sha256' => isset($input['sha256']) ? strtolower($input['sha256']) : null,
                'expires_at' => now()->addMinutes(15),
            ]);
            $signed = $this->sign($session);

            return [
                'upload_id' => $session->id, ...$signed,
                'expires_at' => $session->expires_at->toISOString(),
                'max_size_bytes' => Platform::maxVideoBytesCeiling(),
                'size_bytes' => $session->size_bytes, 'sha256' => $session->expected_sha256,
            ];
        });
    }

    /** @return array{id: string, mime: string, kind: string, size_bytes: int, width: int|null, height: int|null, duration_seconds: int|null, alt_text: string|null, sha256: string, observed_sha256: string} */
    public function complete(string $workspaceId, string $userId, string $uploadId): array
    {
        return DB::transaction(function () use ($workspaceId, $userId, $uploadId): array {
            $session = VideoUploadSession::withoutGlobalScope('workspace')
                ->where('workspace_id', $workspaceId)->where('user_id', $userId)
                ->lockForUpdate()->findOrFail($uploadId);
            $this->requireMembership($workspaceId, $userId);
            if ($session->completed_at !== null) {
                $media = PostMedia::withoutGlobalScope('workspace')->where('workspace_id', $workspaceId)->find($session->media_id);
                abort_unless($media !== null && $media->disk === $session->disk && $media->path === $session->final_path
                    && (int) $media->size_bytes === $session->size_bytes && $session->observed_sha256 !== null,
                    410, 'The finalized media is no longer available unchanged. Begin a new upload.');

                return $this->metadata($media, $session->observed_sha256);
            }
            abort_if($session->expires_at->isPast(), 410, 'This video upload expired. Begin a new upload.');
            $this->requirePrivateDisk($session->disk);
            $disk = Storage::disk($session->disk);
            $copied = false;
            try {
                if (! $disk->exists($session->temporary_path)) {
                    throw ValidationException::withMessages(['upload_id' => 'The video has not finished uploading. Finish the signed PUT before completing it.']);
                }
                $this->requireSize((int) $disk->size($session->temporary_path), $session->size_bytes);

                // The signed PUT remains reusable until expiry. Validate a private
                // snapshot, never promote its still-writable staging key as media.
                $copied = true;
                if (! $disk->copy($session->temporary_path, $session->final_path)) {
                    throw new HttpException(503, 'Video storage is unavailable. Retry completing the same upload.');
                }
                if (config("filesystems.disks.{$session->disk}.driver") === 'local'
                    && ! $disk->setVisibility($session->final_path, 'private')) {
                    throw new HttpException(503, 'Private video storage is unavailable. Retry completing the same upload.');
                }
                $digest = $this->inspectSnapshot($disk, $session);
                $this->requireMembership($workspaceId, $userId);
                abort_if($session->expires_at->isPast(), 410, 'This video upload expired. Begin a new upload.');
                $media = PostMedia::create([
                    'workspace_id' => $workspaceId, 'post_id' => null, 'disk' => $session->disk,
                    'path' => $session->final_path, 'mime' => 'video/mp4', 'kind' => 'video',
                    'size_bytes' => $session->size_bytes, 'width' => $session->width, 'height' => $session->height,
                    'duration_seconds' => $session->duration_seconds, 'alt_text' => $session->alt_text, 'position' => 0,
                ]);
                $session->forceFill(['media_id' => $media->id, 'observed_sha256' => $digest, 'completed_at' => now()])->save();

                return $this->metadata($media, $digest);
            } catch (Throwable $error) {
                if ($copied) {
                    $this->removeUncommittedSnapshot($disk, $session->final_path);
                }
                if ($error instanceof ValidationException || $error instanceof HttpException) {
                    throw $error;
                }

                throw new HttpException(503, 'Video storage is unavailable. Retry completing the same upload.');
            }
        });
    }

    /** Expired sessions retain enough state to clean up files after an interrupted finalization. */
    public function pruneExpired(): int
    {
        $cutoff = now()->subHours(6);
        $deleted = 0;
        foreach (VideoUploadSession::withoutGlobalScope('workspace')->where('expires_at', '<', $cutoff)->select('id')->lazyById(100) as $candidate) {
            try {
                $deleted += DB::transaction(function () use ($candidate, $cutoff): int {
                    $session = VideoUploadSession::withoutGlobalScope('workspace')->lockForUpdate()->find($candidate->id);
                    if ($session === null || $session->expires_at >= $cutoff) {
                        return 0;
                    }
                    $paths = [$session->temporary_path];
                    if ($session->completed_at === null) {
                        $paths[] = $session->final_path;
                    }
                    if (! Storage::disk($session->disk)->delete($paths)) {
                        return 0;
                    }
                    $session->delete();

                    return 1;
                });
            } catch (Throwable) {
                Log::warning('Video upload cleanup will retry after a storage or database error.');
            }
        }

        return $deleted;
    }

    private function requireMembership(string $workspaceId, string $userId): void
    {
        $membership = WorkspaceMembership::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)
            ->lockForUpdate()->first();
        abort_unless($membership !== null && in_array('workspace.read', $membership->permissions, true), 403, 'You are no longer allowed to upload media to this workspace.');
    }

    private function requirePrivateDisk(string $disk): void
    {
        abort_if(config("filesystems.disks.{$disk}.visibility") === 'public'
            || filled(config("filesystems.disks.{$disk}.public_url")),
            503, 'Configure private video storage before uploading videos.');
    }

    /** @return array{url: string, headers: array<string, string>} */
    private function sign(VideoUploadSession $session): array
    {
        try {
            if (config("filesystems.disks.{$session->disk}.driver") === 'local') {
                $signed = FileStorage::temporaryVideoUploadUrl($session->temporary_path, $session->expires_at);
                $signed['url'] = rtrim((string) config('app.url'), '/').'/'.ltrim($signed['url'], '/');
            } else {
                $signed = Storage::disk($session->disk)->temporaryUploadUrl($session->temporary_path, $session->expires_at, [
                    'ContentType' => 'video/mp4', 'ContentLength' => $session->size_bytes,
                ]);
            }
            $headers = [];
            foreach ($signed['headers'] as $name => $value) {
                $headers[$name] = is_array($value) ? implode(', ', $value) : $value;
            }

            return ['url' => $signed['url'], 'headers' => $headers];
        } catch (Throwable) {
            throw new HttpException(503, 'Video upload signing is unavailable. Try again later.');
        }
    }

    private function requireSize(int $actual, int $expected): void
    {
        if ($actual !== $expected || $actual < 12 || $actual > Platform::maxVideoBytesCeiling()) {
            throw ValidationException::withMessages(['size_bytes' => 'The uploaded video size does not match this upload session.']);
        }
    }

    private function inspectSnapshot(Filesystem $disk, VideoUploadSession $session): string
    {
        $this->requireSize((int) $disk->size($session->final_path), $session->size_bytes);
        $stream = $disk->readStream($session->final_path);
        if (! is_resource($stream)) {
            throw new HttpException(503, 'The uploaded video could not be read. Retry completing the same upload.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        $header = '';
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, max(1, min(1024 * 1024, $session->size_bytes + 1 - $bytes)));
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new HttpException(503, 'The uploaded video could not be read. Retry completing the same upload.');
                }
                $header .= substr($chunk, 0, max(0, 4096 - strlen($header)));
                $bytes += strlen($chunk);
                if ($bytes > $session->size_bytes) {
                    $this->requireSize($bytes, $session->size_bytes);
                }
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }
        $this->requireSize($bytes, $session->size_bytes);
        $boxSize = unpack('Nsize', substr($header, 0, 4));
        $boxSize = $boxSize === false ? 0 : $boxSize['size'];
        $brands = $boxSize >= 16 && $boxSize <= min($bytes, 4096)
            ? [substr($header, 8, 4), ...str_split(substr($header, 16, $boxSize - 16), 4)] : [];
        if (substr($header, 4, 4) !== 'ftyp' || ! collect($brands)->contains(static fn (string $brand): bool => preg_match('/\A(?:iso[m2-9]|mp4[12]|avc1|dash|M4V |MSNV)\z/', $brand) === 1)) {
            throw ValidationException::withMessages(['upload_id' => 'The uploaded file is not a supported MP4 container.']);
        }
        $digest = hash_final($hash);
        if ($session->expected_sha256 !== null && ! hash_equals($session->expected_sha256, $digest)) {
            throw ValidationException::withMessages(['sha256' => 'The uploaded video checksum does not match this upload session.']);
        }

        return $digest;
    }

    private function removeUncommittedSnapshot(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable) {
            Log::warning('An unfinished video upload will be removed by scheduled cleanup.');
        }
    }

    /** @return array{id: string, mime: string, kind: string, size_bytes: int, width: int|null, height: int|null, duration_seconds: int|null, alt_text: string|null, sha256: string, observed_sha256: string} */
    private function metadata(PostMedia $media, string $digest): array
    {
        return [
            'id' => $media->id, 'mime' => $media->mime, 'kind' => $media->kind, 'size_bytes' => (int) $media->size_bytes,
            'width' => $media->width, 'height' => $media->height, 'duration_seconds' => $media->duration_seconds,
            'alt_text' => $media->alt_text, 'sha256' => $digest, 'observed_sha256' => $digest,
        ];
    }
}
