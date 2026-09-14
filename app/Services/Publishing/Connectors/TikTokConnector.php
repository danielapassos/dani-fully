<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\UsageCategory;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Aws\Exception\AwsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Transfers one video to the creator's TikTok inbox. TikTok, not this app,
 * owns the final caption/privacy/music choices and the creator must publish it
 * natively. The connector keeps polling until TikTok reports the final post.
 */
class TikTokConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    private const string BASE_URL = 'https://open.tiktokapis.com/v2/post/publish';

    private const int CHUNK_BYTES = 8 * 1024 * 1024;

    private const int MAX_VIDEO_BYTES = 4 * 1024 * 1024 * 1024;

    public function __construct(private readonly HttpFactory $http) {}

    public function publish(PublishContext $context): PublishResult
    {
        if (! config('services.tiktok.inbox_enabled')) {
            return PublishResult::failure(
                ErrorKind::Unsupported,
                'TikTok inbox publishing is disabled until the developer app and upload permission are ready.',
            );
        }

        $token = (string) ($context->credentials['access_token'] ?? '');
        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'TikTok access token unavailable; reconnect the account.');
        }

        $media = $context->effectiveMedia();

        if (count($media) !== 1 || ! $media[0]->isVideo()) {
            return PublishResult::failure(ErrorKind::Validation, 'TikTok inbox publishing requires exactly one video.');
        }

        $media = $media[0];
        $state = new MediaUploadState($context->target->media_upload_state);
        $publishId = $state->remoteRef($media->id);

        try {
            if ($publishId === null) {
                if ($this->initOutcomeUnknown($state, $media)) {
                    return $this->initNeedsReview();
                }

                if ($media->size_bytes < 1 || $media->size_bytes > self::MAX_VIDEO_BYTES
                    || ! in_array($media->mime, ['video/mp4', 'video/quicktime', 'video/webm'], true)) {
                    return PublishResult::failure(
                        ErrorKind::Validation,
                        'TikTok requires an MP4, MOV, or WebM video between 1 byte and 4 GB.',
                    );
                }

                $this->validateStoredSize($media);
                $chunkSize = min(self::CHUNK_BYTES, $media->size_bytes);
                // TikTok merges the remainder into the final chunk, rather than
                // accepting an additional chunk smaller than its 5 MiB minimum.
                $chunkCount = max(1, intdiv($media->size_bytes, $chunkSize));

                // An inbox-init response can be lost after TikTok creates the
                // transfer. Persist the mutation boundary first so a retry cannot
                // silently create a second inbox notification.
                $this->setInitOutcomeUnknown($state, $media, true);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();

                $response = $this->request($token)->post(self::BASE_URL.'/inbox/video/init/', [
                    'source_info' => [
                        'source' => 'FILE_UPLOAD',
                        'video_size' => $media->size_bytes,
                        'chunk_size' => $chunkSize,
                        'total_chunk_count' => $chunkCount,
                    ],
                ]);

                $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

                if ($failure = $this->failure($response, 'TikTok could not transfer the video to the creator inbox.')) {
                    if ($response->serverError()) {
                        return $this->initNeedsReview();
                    }

                    $this->setInitOutcomeUnknown($state, $media, false);
                    $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();

                    return PublishResult::failure($failure->errorKind ?? ErrorKind::Unknown, 'TikTok could not initialize the inbox upload. Check the upload permission and developer app status.', $failure->httpStatus, retryAfter: $failure->retryAfter);
                }

                $publishId = (string) $response->json('data.publish_id');
                if ($publishId === '') {
                    return $this->initNeedsReview();
                }

                $state->markUploaded($media->id, $publishId);
                $state->setMetadata($media->id, [
                    'source' => 'FILE_UPLOAD',
                    'total_bytes' => $media->size_bytes,
                    'content_type' => $media->mime,
                    'chunk_size' => $chunkSize,
                    'chunk_count' => $chunkCount,
                    'uploaded_bytes' => 0,
                    'upload_expires_at' => now()->addHour()->timestamp,
                ]);
                // Save the known operation even if its upload URL is malformed:
                // a later job must never create a duplicate inbox transfer.
                $this->persistState($context, $state);

                $uploadUrl = $this->uploadUrl((string) $response->json('data.upload_url'));
                $metadata = $state->metadata($media->id);
                $metadata['upload_url'] = Crypt::encryptString($uploadUrl);
                $state->setMetadata($media->id, $metadata);
                $this->persistState($context, $state);
            }

            $metadata = $state->metadata($media->id);
            if (($metadata['source'] ?? null) === 'FILE_UPLOAD' && ($metadata['upload_complete'] ?? false) !== true) {
                return $this->upload($context, $token, $publishId, $media, $state);
            }

            // Previously initialized PULL_FROM_URL transfers only have a
            // publish_id. They continue polling without starting a file upload.
            return $this->status($context, $token, $publishId, $media, $state);
        } catch (ConnectionException) {
            if ($this->initOutcomeUnknown($state, $media)) {
                return $this->initNeedsReview();
            }

            // HTTP exceptions can contain the signed upload URL. Never expose
            // that bearer-like credential in the target's error or diagnostics.
            return PublishResult::failure(ErrorKind::Network, 'The video transfer connection failed. The existing transfer will be checked before retrying.', retryAfter: 10);
        } catch (RuntimeException|FilesystemException $exception) {
            // Flysystem may wrap an SDK exception during the size lookup.
            // Storage throttling and outages are safe to retry at the same
            // offset, without repeating the TikTok inbox initialization.
            $cause = $exception;
            do {
                if ($cause instanceof AwsException && ($cause->isConnectionError()
                    || ($cause->getStatusCode() ?? 0) >= 500 || $cause->getStatusCode() === 429
                    || in_array($cause->getAwsErrorCode(), ['SlowDown', 'RequestTimeout', 'Throttling'], true))) {
                    return PublishResult::failure(ErrorKind::Network, 'Private video storage is temporarily unavailable. The existing TikTok transfer will resume when storage recovers.', retryAfter: 15);
                }
                $cause = $cause->getPrevious();
            } while ($cause !== null);

            return PublishResult::failure(ErrorKind::Validation, 'The TikTok video could not be read or its saved upload session is invalid. Check the video and existing inbox transfer before retrying.');
        }
    }

    private function upload(PublishContext $context, string $token, string $publishId, PostMedia $media, MediaUploadState $state): PublishResult
    {
        $metadata = $state->metadata($media->id);
        $expired = (int) ($metadata['upload_expires_at'] ?? 0) <= now()->timestamp;

        if (($metadata['outcome_unknown'] ?? false) === true || $expired) {
            $response = $this->statusResponse($context, $token, $publishId);
            if ($failure = $this->failure($response, 'TikTok could not check the interrupted video upload.')) {
                return $failure;
            }

            if ($response->json('data.status') !== 'PROCESSING_UPLOAD') {
                return $this->status($context, $token, $publishId, $media, $state, $response);
            }

            $uploaded = $response->json('data.uploaded_bytes');
            $previous = (int) ($metadata['uploaded_bytes'] ?? 0);
            $pendingEnd = (int) ($metadata['pending_end'] ?? $previous);
            // Missing progress is not zero. Only whole acknowledged chunks can
            // be resumed; TikTok does not document rechunking partial chunks.
            if (! is_int($uploaded) || $uploaded < $previous || $uploaded > $pendingEnd
                || ($uploaded !== $previous && $uploaded !== $pendingEnd)) {
                return PublishResult::failure(ErrorKind::Unknown, 'TikTok could not confirm the uploaded byte range. Check the existing inbox transfer before retrying.');
            }

            $metadata['uploaded_bytes'] = $uploaded;
            $metadata['outcome_unknown'] = false;
            unset($metadata['pending_end']);
            if ($uploaded === $media->size_bytes) {
                $metadata['upload_complete'] = true;
                unset($metadata['upload_url']);
            }
            $state->setMetadata($media->id, $metadata);
            $this->persistState($context, $state);

            if (($metadata['upload_complete'] ?? false) === true) {
                return PublishResult::failure(ErrorKind::MediaProcessing, 'TikTok is processing the video transfer.', retryAfter: 15);
            }
            if ($expired) {
                return PublishResult::failure(ErrorKind::Unknown, 'The TikTok upload session expired before all bytes were confirmed. Check the existing inbox transfer before starting another.');
            }
        }

        $total = $media->size_bytes;
        $offset = (int) ($metadata['uploaded_bytes'] ?? 0);
        $chunkSize = (int) ($metadata['chunk_size'] ?? 0);
        $chunkCount = (int) ($metadata['chunk_count'] ?? 0);
        if (($metadata['total_bytes'] ?? null) !== $total || ($metadata['content_type'] ?? null) !== $media->mime
            || $chunkSize !== min(self::CHUNK_BYTES, $total) || $total < 1 || $total > self::MAX_VIDEO_BYTES
            || $chunkCount !== max(1, intdiv($total, $chunkSize)) || $chunkCount > 1000
            || $offset < 0 || $offset >= $total || $offset % $chunkSize !== 0) {
            throw new RuntimeException('Invalid saved TikTok upload metadata.');
        }

        $uploadUrl = $this->uploadUrl(Crypt::decryptString((string) ($metadata['upload_url'] ?? '')));
        $this->validateStoredSize($media);
        $length = $offset + $chunkSize >= $chunkSize * $chunkCount ? $total - $offset : $chunkSize;
        $bytes = $this->readChunk($media, $offset, $length);
        $end = $offset + $length;

        // This survives both lost HTTP responses and a killed queue worker.
        // The next invocation must query uploaded_bytes before any new PUT.
        $metadata['outcome_unknown'] = true;
        $metadata['pending_end'] = $end;
        $state->setMetadata($media->id, $metadata);
        $this->persistState($context, $state);

        $response = $this->http
            ->timeout(120)
            ->connectTimeout(10)
            ->withoutRedirecting()
            ->withHeaders([
                'Content-Length' => (string) $length,
                'Content-Range' => 'bytes '.$offset.'-'.($end - 1).'/'.$total,
            ])
            ->withBody($bytes, $media->mime)
            ->put($uploadUrl);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        $final = $end === $total;
        if ($response->status() !== ($final ? 201 : 206)) {
            // Even a range error or expired URL can follow a previously accepted
            // chunk. Keep the uncertainty marker and never initialize again.
            $kind = match (true) {
                $response->status() === 429 => ErrorKind::RateLimited,
                $response->status() >= 500 => ErrorKind::ServerError,
                in_array($response->status(), [201, 206, 403, 404, 416], true) => ErrorKind::MediaProcessing,
                default => ErrorKind::Validation,
            };
            if (in_array($response->status(), [403, 404], true)) {
                $metadata['upload_expires_at'] = now()->timestamp;
                $state->setMetadata($media->id, $metadata);
                $this->persistState($context, $state);
            }

            return PublishResult::failure($kind, 'TikTok did not confirm the video chunk. The existing transfer must be checked before retrying.', $response->status(), retryAfter: $this->retryAfter($response) ?? 10);
        }

        $metadata['uploaded_bytes'] = $end;
        $metadata['outcome_unknown'] = false;
        unset($metadata['pending_end']);
        if ($final) {
            $metadata['upload_complete'] = true;
            unset($metadata['upload_url']);
        }
        $state->setMetadata($media->id, $metadata);
        $this->persistState($context, $state);

        return $final
            ? $this->status($context, $token, $publishId, $media, $state)
            : PublishResult::failure(ErrorKind::MediaProcessing, 'Uploading the next TikTok video chunk.', retryAfter: 1);
    }

    private function validateStoredSize(PostMedia $media): void
    {
        if (Storage::disk($media->disk)->size($media->path) !== $media->size_bytes) {
            throw new RuntimeException('The TikTok source size changed.');
        }
    }

    /** Read at most one chunk (less than 16 MiB), never the entire video. */
    private function readChunk(PostMedia $media, int $offset, int $length): string
    {
        $disk = Storage::disk($media->disk);
        if ($disk instanceof AwsS3V3Adapter) {
            return $this->readS3Chunk($disk, $media, $offset, $length);
        }

        $stream = $disk->readStream($media->path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The TikTok source could not be opened.');
        }

        try {
            if ($offset > 0 && (! stream_get_meta_data($stream)['seekable'] || fseek($stream, $offset) !== 0)) {
                // Never repeatedly download/discard the beginning of a remote
                // video: that becomes quadratic in its size and can expire the
                // upload session. S3 uses an exact byte-range read above.
                throw new RuntimeException('The TikTok storage disk cannot resume a ranged read.');
            }
            $bytes = stream_get_contents($stream, $length);
        } finally {
            fclose($stream);
        }

        if (! is_string($bytes) || strlen($bytes) !== $length) {
            throw new RuntimeException('The TikTok source ended before its declared size.');
        }

        return $bytes;
    }

    private function readS3Chunk(AwsS3V3Adapter $disk, PostMedia $media, int $offset, int $length): string
    {
        $last = $offset + $length - 1;
        $result = $disk->getClient()->getObject([
            'Bucket' => $disk->getConfig()['bucket'],
            'Key' => $disk->path($media->path),
            'Range' => "bytes={$offset}-{$last}",
            '@http' => ['stream' => true, 'timeout' => 120, 'connect_timeout' => 10],
        ]);
        $body = $result['Body'];
        if (! $body instanceof StreamInterface) {
            throw new RuntimeException('The TikTok source range could not be opened.');
        }

        try {
            if ($result['ContentRange'] !== "bytes {$offset}-{$last}/{$media->size_bytes}"
                || (int) $result['ContentLength'] !== $length) {
                throw new RuntimeException('The storage server did not honor the requested video range.');
            }
            $bytes = '';
            while (strlen($bytes) < $length) {
                try {
                    $part = $body->read(min(1024 * 1024, $length - strlen($bytes)));
                } catch (RuntimeException) {
                    throw new ConnectionException('The private storage stream was interrupted.');
                }
                if ($part === '') {
                    throw new RuntimeException('The TikTok source range ended before its declared size.');
                }
                $bytes .= $part;
            }
        } finally {
            $body->close();
        }

        return $bytes;
    }

    private function uploadUrl(string $value): string
    {
        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array($parts['host'] ?? null, ['open-upload.tiktokapis.com', 'upload.us.tiktokapis.com'], true)
            || ($parts['path'] ?? null) !== '/video/' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('Invalid TikTok upload URL.');
        }
        parse_str($parts['query'] ?? '', $query);
        if (! is_string($query['upload_id'] ?? null) || $query['upload_id'] === ''
            || ! is_string($query['upload_token'] ?? null) || $query['upload_token'] === '') {
            throw new RuntimeException('Invalid TikTok upload URL credentials.');
        }

        return $value;
    }

    private function persistState(PublishContext $context, MediaUploadState $state): void
    {
        $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
    }

    private function initOutcomeUnknown(MediaUploadState $state, PostMedia $media): bool
    {
        return ($state->metadata($media->id)['init_outcome_unknown'] ?? false) === true;
    }

    private function setInitOutcomeUnknown(MediaUploadState $state, PostMedia $media, bool $unknown): void
    {
        $metadata = $state->metadata($media->id);
        if ($unknown) {
            $metadata['init_outcome_unknown'] = true;
        } else {
            unset($metadata['init_outcome_unknown']);
        }
        $state->setMetadata($media->id, $metadata);
    }

    private function initNeedsReview(): PublishResult
    {
        return PublishResult::failure(
            ErrorKind::Unknown,
            'TikTok may already have created this inbox transfer, but Shoutrrr did not receive its publish id. Check the TikTok inbox before retrying.',
        );
    }

    private function statusResponse(PublishContext $context, string $token, string $publishId): Response
    {
        $response = $this->request($token)->post(self::BASE_URL.'/status/fetch/', [
            'publish_id' => $publishId,
        ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        return $response;
    }

    private function status(PublishContext $context, string $token, string $publishId, PostMedia $media, MediaUploadState $state, ?Response $response = null): PublishResult
    {
        $response ??= $this->statusResponse($context, $token, $publishId);

        if ($failure = $this->failure($response, 'TikTok could not read the inbox transfer status.')) {
            return $failure;
        }

        $status = strtoupper((string) $response->json('data.status'));

        if (in_array($status, ['SEND_TO_USER_INBOX', 'PUBLISH_COMPLETE'], true)
            && ($state->metadata($media->id)['source'] ?? null) === 'FILE_UPLOAD') {
            $metadata = $state->metadata($media->id);
            $metadata['upload_complete'] = true;
            $metadata['outcome_unknown'] = false;
            unset($metadata['upload_url'], $metadata['pending_end']);
            $state->setMetadata($media->id, $metadata);
            $this->persistState($context, $state);
        }

        if ($status === 'PUBLISH_COMPLETE') {
            $ids = array_values(array_unique(array_filter(
                array_map(
                    static fn (mixed $id): string => trim((string) $id),
                    array_filter((array) $response->json('data.publicaly_available_post_id', []), is_scalar(...)),
                ),
                static fn (string $id): bool => $id !== '',
            )));

            if ($ids === []) {
                // A private/friends post is a valid completed inbox handoff but
                // TikTok never exposes a public post id for it. The publish_id
                // remains in media_upload_state as an operation tracker; it must
                // not be promoted to a video id or polled forever.
                return PublishResult::success([]);
            }

            return PublishResult::success($ids);
        }

        if ($status === 'FAILED') {
            $reason = (string) $response->json('data.fail_reason', 'unknown');

            if ($reason === 'auth_removed') {
                return PublishResult::failure(
                    ErrorKind::AuthExpired,
                    'The TikTok creator removed access while the inbox handoff was processing; reconnect the account.',
                );
            }

            if (in_array($reason, ['internal', 'video_pull_failed'], true)) {
                // TikTok documents these as retryable. The existing publish_id is
                // terminal once status is FAILED, so discard it before returning a
                // retryable result; the next job must initialize a fresh handoff.
                $state->forget($media->id);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();

                return PublishResult::failure(
                    ErrorKind::ServerError,
                    "TikTok could not complete the inbox handoff ({$reason}); retrying with a fresh transfer.",
                    retryAfter: 30,
                );
            }

            return PublishResult::failure(ErrorKind::Validation, "TikTok could not complete the inbox handoff ({$reason}).");
        }

        if ($status === 'SEND_TO_USER_INBOX') {
            return PublishResult::failure(
                ErrorKind::MediaProcessing,
                'Video sent to TikTok. Open TikTok to finish the native post.',
                retryAfter: 60,
            );
        }

        if (in_array($status, ['PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD'], true)) {
            return PublishResult::failure(ErrorKind::MediaProcessing, 'TikTok is processing the video transfer.', retryAfter: 15);
        }

        return PublishResult::failure(ErrorKind::ServerError, 'TikTok returned an unknown publishing status.');
    }

    private function request(string $token): PendingRequest
    {
        return $this->http
            ->timeout(15)
            ->connectTimeout(5)
            ->withToken($token)
            ->acceptJson();
    }

    private function failure(Response $response, string $fallback): ?PublishResult
    {
        $code = (string) $response->json('error.code', '');
        if ($response->successful() && ($code === '' || $code === 'ok')) {
            return null;
        }

        $kind = match (true) {
            $response->status() === 429 || $code === 'rate_limit_exceeded' => ErrorKind::RateLimited,
            $response->status() === 401 || $code === 'access_token_invalid' => ErrorKind::AuthExpired,
            $response->status() >= 500 => ErrorKind::ServerError,
            default => ErrorKind::Validation,
        };

        $message = (string) ($response->json('error.message') ?: $fallback);

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        // Content Posting API exposes no remote delete endpoint. Local deletion
        // therefore removes only the Shoutrrr record, matching Instagram's
        // existing best-effort behavior for provider-unsupported deletes.
    }
}
