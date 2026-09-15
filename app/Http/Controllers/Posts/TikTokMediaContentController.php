<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Models\PostMedia;
use App\Services\Media\PublicMediaUrl;
use Aws\Exception\AwsException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class TikTokMediaContentController extends Controller
{
    public function __invoke(Request $request, string $mediaId, PublicMediaUrl $urls): Response
    {
        // The signed stateless route authorizes one exact media record; workspace
        // session scoping and caller-supplied filesystem paths do not apply here.
        $media = PostMedia::withoutGlobalScopes()->findOrFail($mediaId);
        $source = $request->query('source');
        abort_unless(is_string($source) && hash_equals($urls->tikTokSourceVersion($media), $source), 404);
        abort_unless($media->isVideo() && in_array($media->mime, ['video/mp4', 'video/quicktime', 'video/webm'], true), 404);

        $disk = Storage::disk($media->disk);
        try {
            abort_unless($disk->exists($media->path), 404);
            $size = $disk->size($media->path);
        } catch (FilesystemException|AwsException) {
            abort(503, 'Media storage is temporarily unavailable.');
        }
        abort_unless($size > 0 && $size === (int) $media->size_bytes, 409);

        $headers = [
            'Content-Type' => $media->mime,
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'inline; filename="video.'.match ($media->mime) {
                'video/quicktime' => 'mov',
                'video/webm' => 'webm',
                default => 'mp4',
            }.'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        $range = $this->range($request->header('Range'), $size);
        if ($range === null) {
            return response('', 416, [...$headers, 'Content-Length' => '0', 'Content-Range' => "bytes */{$size}"]);
        }
        [$start, $end] = $range;
        $length = $end - $start + 1;
        $partial = $request->hasHeader('Range');
        if ($partial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
            $headers['Content-Length'] = (string) $length;
        }

        try {
            $body = $this->sourceStream($disk, $media, $start, $end, $size);
        } catch (FilesystemException|AwsException|RuntimeException) {
            abort(503, 'Media storage is temporarily unavailable.');
        }

        return response()->stream(function () use ($body, $length): void {
            try {
                $remaining = $length;
                while ($remaining > 0) {
                    $chunk = $body->read(min(1024 * 1024, $remaining));
                    if ($chunk === '') {
                        throw new RuntimeException('The media stream ended before its declared size.');
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                $body->close();
            }
        }, $partial ? 206 : 200, $headers);
    }

    /** @return array{int, int}|null */
    private function range(?string $value, int $size): ?array
    {
        if ($value === null) {
            return [0, $size - 1];
        }
        if (! preg_match('/\Abytes=(\d*)-(\d*)\z/', $value, $matches)
            || ($matches[1] === '' && $matches[2] === '')) {
            return null;
        }
        if ($matches[1] === '') {
            $suffix = (int) $matches[2];

            return $suffix > 0 ? [max(0, $size - $suffix), $size - 1] : null;
        }
        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);

        return $start < $size && $start <= $end ? [$start, $end] : null;
    }

    private function sourceStream(FilesystemAdapter $disk, PostMedia $media, int $start, int $end, int $size): StreamInterface
    {
        if ($disk instanceof AwsS3V3Adapter) {
            $result = $disk->getClient()->getObject([
                'Bucket' => $disk->getConfig()['bucket'],
                'Key' => $disk->path($media->path),
                'Range' => "bytes={$start}-{$end}",
                '@http' => ['stream' => true, 'timeout' => 3600, 'connect_timeout' => 10, 'read_timeout' => 30],
            ]);
            $body = $result['Body'];
            if (! $body instanceof StreamInterface) {
                throw new RuntimeException('The media stream could not be opened.');
            }
            if ($result['ContentRange'] !== "bytes {$start}-{$end}/{$size}"
                || (int) $result['ContentLength'] !== $end - $start + 1) {
                $body->close();
                throw new RuntimeException('Media storage did not return the requested range.');
            }

            return $body;
        }

        $resource = $disk->readStream($media->path);
        if (! is_resource($resource)) {
            throw new RuntimeException('The media stream could not be opened.');
        }
        $body = Utils::streamFor($resource);
        try {
            if ($start > 0) {
                $body->seek($start);
            }
        } catch (RuntimeException $exception) {
            $body->close();
            throw $exception;
        }

        return $body;
    }
}
