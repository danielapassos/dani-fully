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
use App\Services\Publishing\YouTubePostOptions;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Resumable, one-chunk-per-job YouTube video uploader. */
class YouTubeConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    private const string API_URL = 'https://www.googleapis.com/youtube/v3';

    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    private const int CHUNK_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly HttpFactory $http) {}

    public function publish(PublishContext $context): PublishResult
    {
        if (! config('services.youtube.publishing_enabled')) {
            return PublishResult::failure(
                ErrorKind::Unsupported,
                'YouTube publishing is disabled until OAuth review and the publishing declarations are ready.',
            );
        }

        $options = $this->options($context);
        if ($options === null) {
            return PublishResult::failure(
                ErrorKind::Validation,
                'YouTube publishing needs explicit privacy, audience, synthetic-media, paid-placement, format, and notification declarations.',
            );
        }

        $token = (string) ($context->credentials['access_token'] ?? '');
        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'YouTube access token unavailable; reconnect the channel.');
        }

        $media = $context->effectiveMedia();

        if (count($media) !== 1 || ! $media[0]->isVideo()) {
            return PublishResult::failure(ErrorKind::Validation, 'YouTube publishing requires exactly one video.');
        }

        $media = $media[0];
        $state = new MediaUploadState($context->target->media_upload_state);
        $storedPrivacy = (string) ($state->metadata($media->id)['privacy_status'] ?? '');
        $expectedPrivacy = in_array($storedPrivacy, ['private', 'unlisted', 'public'], true)
            ? $storedPrivacy
            : $options['privacyStatus'];

        try {
            if ($context->target->remote_id !== null) {
                return $this->processingStatus($context, $token, $context->target->remote_id, $expectedPrivacy);
            }

            $sealedSession = $state->remoteRef($media->id);
            if ($sealedSession === null) {
                $sealedSession = $this->startSession($context, $media, $token, $options, $state);
            }

            $sessionUrl = $this->sessionUrl(Crypt::decryptString($sealedSession));
            $metadata = $state->metadata($media->id);

            if (($metadata['outcome_unknown'] ?? false) === true) {
                $probe = $this->probeSession($context, $token, $sessionUrl, $media, $state, $expectedPrivacy);
                if ($probe !== null) {
                    return $probe;
                }
                $metadata = $state->metadata($media->id);
            }

            return $this->uploadNextChunk($context, $token, $sessionUrl, $media, $state, $metadata, $expectedPrivacy);
        } catch (ConnectionException $exception) {
            // A lost chunk response is ambiguous and must be probed before more
            // bytes are sent. Once a video id is stored, however, the upload is
            // already reconciled and only the processing-status read needs retrying.
            if ($context->target->remote_id === null) {
                $metadata = $state->metadata($media->id);
                $metadata['outcome_unknown'] = true;
                $state->setMetadata($media->id, $metadata);
                $this->persistState($context, $state);
            }

            return PublishResult::failure(ErrorKind::Network, $exception->getMessage(), retryAfter: 10);
        } catch (YouTubeRequestFailed $exception) {
            return $this->httpFailure($exception->response, 'YouTube could not start the resumable upload.');
        } catch (RuntimeException $exception) {
            return PublishResult::failure(ErrorKind::Validation, $exception->getMessage());
        }
    }

    /**
     * @param  array{privacyStatus: string, categoryId: string, formatIntent: string, madeForKids: bool, containsSyntheticMedia: bool, hasPaidProductPlacement: bool, notifySubscribers: bool}  $options
     */
    private function startSession(PublishContext $context, PostMedia $media, string $token, array $options, MediaUploadState $state): string
    {
        [$title, $description] = $this->copy($context);

        $response = $this->http
            ->timeout(15)
            ->connectTimeout(5)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $media->size_bytes,
                'X-Upload-Content-Type' => $media->mime,
            ])
            ->post(self::UPLOAD_URL.'?'.http_build_query([
                'uploadType' => 'resumable',
                'part' => 'snippet,status,paidProductPlacementDetails',
                'notifySubscribers' => $options['notifySubscribers'] ? 'true' : 'false',
            ]), [
                'snippet' => [
                    'title' => $title,
                    'description' => $description,
                    'categoryId' => $options['categoryId'],
                ],
                'status' => [
                    'privacyStatus' => $options['privacyStatus'],
                    'selfDeclaredMadeForKids' => $options['madeForKids'],
                    'containsSyntheticMedia' => $options['containsSyntheticMedia'],
                ],
                'paidProductPlacementDetails' => [
                    'hasPaidProductPlacement' => $options['hasPaidProductPlacement'],
                ],
            ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new YouTubeRequestFailed($response);
        }

        $sessionUrl = $this->sessionUrl($response->header('Location'));
        $sealed = Crypt::encryptString($sessionUrl);

        $state->markUploaded($media->id, $sealed);
        $state->setMetadata($media->id, [
            'uploaded_bytes' => 0,
            'total_bytes' => $media->size_bytes,
            'content_type' => $media->mime,
            'format_intent' => $options['formatIntent'],
            'privacy_status' => $options['privacyStatus'],
            'snippet' => ['title' => $title, 'description' => $description],
            'outcome_unknown' => false,
        ]);
        $this->persistState($context, $state);

        return $sealed;
    }

    /**
     * Probe an ambiguous upload before sending another byte. A 308 gives the
     * authoritative offset; a completed response gives the video id.
     */
    private function probeSession(PublishContext $context, string $token, string $sessionUrl, PostMedia $media, MediaUploadState $state, string $expectedPrivacy): ?PublishResult
    {
        $response = $this->http
            ->timeout(15)
            ->connectTimeout(5)
            ->withToken($token)
            ->withHeaders([
                'Content-Length' => '0',
                'Content-Range' => "bytes */{$media->size_bytes}",
            ])
            ->withBody('', $media->mime)
            ->put($sessionUrl);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        if (in_array($response->status(), [200, 201], true)) {
            return $this->recordVideoAndPoll($context, $token, $response, $expectedPrivacy);
        }

        if ($response->status() === 308) {
            $metadata = $state->metadata($media->id);
            $acknowledgedBytes = $this->acknowledgedBytes($response);
            if ($acknowledgedBytes > $media->size_bytes) {
                $metadata['outcome_unknown'] = true;
                $state->setMetadata($media->id, $metadata);
                $this->persistState($context, $state);

                return PublishResult::failure(
                    ErrorKind::ServerError,
                    'YouTube returned an invalid resumable-upload byte range; reconciling the session before retrying.',
                    retryAfter: 2,
                );
            }

            $metadata['uploaded_bytes'] = $acknowledgedBytes;
            $metadata['outcome_unknown'] = false;
            $state->setMetadata($media->id, $metadata);
            $this->persistState($context, $state);

            return null;
        }

        if (in_array($response->status(), [404, 410], true)) {
            $state->forget($media->id);
            $this->persistState($context, $state);

            return PublishResult::failure(ErrorKind::MediaProcessing, 'YouTube upload session expired; starting a fresh session.', retryAfter: 1);
        }

        return $this->httpFailure($response, 'YouTube could not reconcile the resumable upload.');
    }

    /** @param array<string, mixed> $metadata */
    private function uploadNextChunk(PublishContext $context, string $token, string $sessionUrl, PostMedia $media, MediaUploadState $state, array $metadata, string $expectedPrivacy): PublishResult
    {
        $offset = max(0, (int) ($metadata['uploaded_bytes'] ?? 0));
        $remaining = $media->size_bytes - $offset;
        if ($remaining <= 0) {
            $metadata['outcome_unknown'] = true;
            $state->setMetadata($media->id, $metadata);
            $this->persistState($context, $state);

            return PublishResult::failure(ErrorKind::MediaProcessing, 'YouTube is finalizing the upload.', retryAfter: 2);
        }

        $length = min(self::CHUNK_BYTES, $remaining);
        $last = $offset + $length - 1;
        $stream = Storage::disk($media->disk)->readStream($media->path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The YouTube video could not be opened from storage.');
        }

        try {
            $this->seek($stream, $offset);
            $bytes = stream_get_contents($stream, $length);
        } finally {
            fclose($stream);
        }

        if (! is_string($bytes) || strlen($bytes) !== $length) {
            throw new RuntimeException('The next YouTube video chunk could not be read from storage.');
        }

        $response = $this->http
            ->timeout(120)
            ->connectTimeout(10)
            ->withToken($token)
            ->withHeaders([
                'Content-Length' => (string) $length,
                'Content-Range' => "bytes {$offset}-{$last}/{$media->size_bytes}",
            ])
            ->withBody($bytes, $media->mime)
            ->put($sessionUrl);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if (in_array($response->status(), [200, 201], true)) {
            return $this->recordVideoAndPoll($context, $token, $response, $expectedPrivacy);
        }

        // A 5xx can arrive after YouTube accepted some or all of this chunk.
        // Persist the ambiguity so the next job probes the session instead of
        // blindly retransmitting bytes that may already be committed.
        if ($response->serverError()) {
            $metadata['outcome_unknown'] = true;
            $state->setMetadata($media->id, $metadata);
            $this->persistState($context, $state);
        }

        if ($response->status() !== 308) {
            return $this->httpFailure($response, 'YouTube rejected the video chunk.');
        }

        // The Range header is authoritative. YouTube may accept only part of a
        // submitted chunk, and a missing Range means it accepted zero bytes.
        // Advancing to the end of the submitted chunk would silently skip data.
        $acknowledgedBytes = $this->acknowledgedBytes($response);
        if ($acknowledgedBytes > $last + 1 || $acknowledgedBytes > $media->size_bytes) {
            $metadata['outcome_unknown'] = true;
            $state->setMetadata($media->id, $metadata);
            $this->persistState($context, $state);

            return PublishResult::failure(
                ErrorKind::ServerError,
                'YouTube returned an invalid resumable-upload byte range; reconciling the session before retrying.',
                retryAfter: 2,
            );
        }

        $metadata['uploaded_bytes'] = $acknowledgedBytes;
        $metadata['outcome_unknown'] = false;
        $state->setMetadata($media->id, $metadata);
        $this->persistState($context, $state);

        return PublishResult::failure(ErrorKind::MediaProcessing, 'Uploading the next YouTube video chunk.', retryAfter: 1);
    }

    private function recordVideoAndPoll(PublishContext $context, string $token, Response $response, string $expectedPrivacy): PublishResult
    {
        $videoId = (string) $response->json('id');
        if (! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $videoId)) {
            return PublishResult::failure(ErrorKind::ServerError, 'YouTube completed the upload without a valid video id.');
        }

        $context->target->forceFill([
            'remote_id' => $videoId,
            'remote_ids' => [$videoId],
        ])->save();

        return $this->processingStatus($context, $token, $videoId, $expectedPrivacy);
    }

    private function processingStatus(PublishContext $context, string $token, string $videoId, string $expectedPrivacy): PublishResult
    {
        $response = $this->http
            ->timeout(10)
            ->connectTimeout(5)
            ->withToken($token)
            ->acceptJson()
            ->get(self::API_URL.'/videos', [
                'part' => 'snippet,status,processingDetails',
                'id' => $videoId,
                'maxResults' => 1,
            ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        if ($response->failed()) {
            return $this->httpFailure($response, 'YouTube could not read video processing status.');
        }

        $item = $response->json('items.0');
        if (! is_array($item)) {
            return PublishResult::failure(ErrorKind::ServerError, 'YouTube did not return the uploaded video.');
        }

        $uploadStatus = strtolower((string) ($item['status']['uploadStatus'] ?? ''));
        $processingStatus = strtolower((string) ($item['processingDetails']['processingStatus'] ?? ''));
        $privacyStatus = strtolower((string) ($item['status']['privacyStatus'] ?? ''));

        if (in_array($uploadStatus, ['failed', 'rejected'], true) || $processingStatus === 'failed') {
            $reason = (string) ($item['status']['rejectionReason']
                ?? $item['status']['failureReason']
                ?? $item['processingDetails']['processingFailureReason']
                ?? 'unknown');

            return PublishResult::failure(ErrorKind::Validation, "YouTube could not process the video ({$reason}).");
        }

        if (in_array($processingStatus, ['processing', 'pending'], true) || in_array($uploadStatus, ['uploaded', 'processing'], true)) {
            return PublishResult::failure(ErrorKind::MediaProcessing, 'YouTube is processing the uploaded video.', retryAfter: 15);
        }

        if (
            ! in_array($uploadStatus, ['', 'processed'], true)
            || ! in_array($processingStatus, ['', 'succeeded'], true)
            || ($uploadStatus !== 'processed' && $processingStatus !== 'succeeded')
        ) {
            return PublishResult::failure(ErrorKind::ServerError, 'YouTube has not confirmed that video processing completed.');
        }

        if ($privacyStatus === '') {
            return PublishResult::failure(
                ErrorKind::ServerError,
                'YouTube processed the video without returning its privacy status.',
            );
        }

        if ($privacyStatus !== $expectedPrivacy) {
            return PublishResult::failure(
                ErrorKind::Unsupported,
                "YouTube processed the video as {$privacyStatus}, but this upload requested {$expectedPrivacy}.",
            );
        }

        return $privacyStatus === 'public'
            ? PublishResult::success([$videoId])
            : PublishResult::completed([$videoId], "Uploaded to YouTube as {$privacyStatus}. This video is not publicly listed.");
    }

    /** @return array{0: string, 1: string} */
    private function copy(PublishContext $context): array
    {
        $options = app(YouTubePostOptions::class)->resolve($context->target) ?? [];
        $description = trim(implode("\n\n", array_filter(array_map(trim(...), $context->segments))));
        $firstLine = trim((string) strtok($description, "\n"));
        $title = $options['title'] ?? $firstLine;
        $description = $options['description'] ?? $description;

        if ($title === '') {
            throw new RuntimeException('YouTube requires a video title.');
        }
        if (str_contains($title, '<') || str_contains($title, '>') || str_contains($description, '<') || str_contains($description, '>')) {
            throw new RuntimeException('YouTube titles and descriptions cannot contain angle brackets.');
        }

        $title = array_key_exists('title', $options) ? $title : mb_substr($title, 0, 100);
        $description = array_key_exists('description', $options) ? $description : mb_strcut($description, 0, 5_000, 'UTF-8');

        return [$title, $description];
    }

    /**
     * @return array{privacyStatus: string, categoryId: string, formatIntent: string, madeForKids: bool, containsSyntheticMedia: bool, hasPaidProductPlacement: bool, notifySubscribers: bool}|null
     */
    private function options(PublishContext $context): ?array
    {
        $options = app(YouTubePostOptions::class)->resolve($context->target);
        if ($options === null) {
            return null;
        }

        return [
            'privacyStatus' => $options['privacy_status'],
            'categoryId' => $options['category_id'],
            'formatIntent' => $options['format_intent'],
            'madeForKids' => $options['made_for_kids'],
            'containsSyntheticMedia' => $options['contains_synthetic_media'],
            'hasPaidProductPlacement' => $options['has_paid_product_placement'],
            'notifySubscribers' => $options['notify_subscribers'],
        ];
    }

    /** @param resource $stream */
    private function seek($stream, int $offset): void
    {
        if ($offset === 0) {
            return;
        }

        if (fseek($stream, $offset) === 0) {
            return;
        }

        $remaining = $offset;
        while ($remaining > 0 && ! feof($stream)) {
            $discarded = fread($stream, min(1024 * 1024, $remaining));
            if ($discarded === false || $discarded === '') {
                break;
            }
            $remaining -= strlen($discarded);
        }

        if ($remaining !== 0) {
            throw new RuntimeException('The YouTube upload could not resume from the stored offset.');
        }
    }

    private function acknowledgedBytes(Response $response): int
    {
        $range = $response->header('Range');

        return preg_match('/bytes=0-(\d+)/', $range, $matches) ? ((int) $matches[1] + 1) : 0;
    }

    private function sessionUrl(string $value): string
    {
        $parts = parse_url($value);
        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'www.googleapis.com'
            || ($parts['path'] ?? null) !== '/upload/youtube/v3/videos'
            || ! str_contains((string) ($parts['query'] ?? ''), 'upload_id=')
        ) {
            throw new RuntimeException('YouTube returned an invalid resumable upload session.');
        }

        return $value;
    }

    private function persistState(PublishContext $context, MediaUploadState $state): void
    {
        $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
    }

    private function httpFailure(Response $response, string $fallback): PublishResult
    {
        return PublishResult::failure(
            $this->youtubeFailureKind($response),
            $this->providerMessage($response, $fallback),
            $response->status(),
            $this->excerpt($response),
            $this->retryAfter($response),
        );
    }

    private function youtubeFailureKind(Response $response): ErrorKind
    {
        if ($response->status() !== 403) {
            return $this->classifyStatus($response->status());
        }

        $reasons = array_values(array_filter(array_map(
            static fn (mixed $error): string => is_array($error)
                ? strtolower((string) ($error['reason'] ?? ''))
                : '',
            (array) $response->json('error.errors', []),
        )));

        if (array_intersect($reasons, [
            'quotaexceeded',
            'dailylimitexceeded',
            'dailylimitexceededunreg',
            'userratelimitexceeded',
            'ratelimitexceeded',
            'uploadlimitexceeded',
        ]) !== []) {
            return ErrorKind::RateLimited;
        }

        if (in_array('insufficientpermissions', $reasons, true)) {
            return ErrorKind::AuthExpired;
        }

        return ErrorKind::Validation;
    }

    private function providerMessage(Response $response, string $fallback): string
    {
        return (string) ($response->json('error.message') ?? $fallback);
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        if ($target->remote_id === null) {
            return;
        }

        $response = $this->http
            ->timeout(10)
            ->connectTimeout(5)
            ->withToken((string) ($credentials['access_token'] ?? ''))
            ->delete(self::API_URL.'/videos', ['id' => $target->remote_id]);

        $this->meter(
            UsageCategory::Publish,
            UsageOperation::DELETE,
            $target->account,
            $response,
            succeeded: $response->successful() || $response->status() === 404,
        );

        $this->throwUnlessDeleteAccepted($response);
    }
}

/**
 * Internal signal that preserves an HTTP response from resumable-session
 * creation so publish() can classify authentication, throttling, and server
 * failures accurately instead of flattening them into Validation.
 *
 * @internal
 */
final class YouTubeRequestFailed extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('YouTube request failed.');
    }
}
