<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\UsageCategory;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\ConnectedAccounts\Instagram\InstagramGraphApi;
use App\Services\Media\ImageConversionFailed;
use App\Services\Media\PublicMediaUrl;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Publishing\InstagramReelCover;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Publishes to Instagram via the async two-step container flow: create a media
 * container (image / carousel-of-children / Reels video), poll it until Meta
 * finishes fetching + processing the referenced URL, then publish the container.
 *
 * Instagram has no direct byte-upload API — every container references a public
 * HTTPS URL that Meta fetches server-side (see PublicMediaUrl). Media is required;
 * there is no text-only post type.
 */
class InstagramConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    /** The pseudo "media id" used to key the top-level (single or carousel-parent) container in MediaUploadState. */
    private const string CONTAINER_KEY = 'container';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PublicMediaUrl $publicMediaUrl,
    ) {}

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');

        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'Instagram access token unavailable; reconnect the account.');
        }

        if (($context->target->remote_id ?? null) !== null) {
            return PublishResult::success($context->target->remote_ids ?? [$context->target->remote_id]);
        }

        if ($context->effectiveMedia() === []) {
            return PublishResult::failure(ErrorKind::Validation, 'Instagram requires at least one image or video');
        }

        $igUserId = (string) $context->account->remote_account_id;
        $caption = implode("\n\n", array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), $context->segments),
            static fn (string $segment): bool => $segment !== '',
        )));

        $format = $context->target->format;
        if (! $format->allowsCaption()) {
            $caption = '';
        }

        $state = new MediaUploadState($context->target->media_upload_state);
        if ($this->publishOutcomeUnknown($state)) {
            return $this->publishNeedsReview();
        }

        $finalPublishPending = false;

        try {
            $containerId = $this->resolveContainerId($context, $state, $igUserId, $caption, $token, $format);

            $notReady = $this->pollContainer($context, $containerId, $token);
            if ($notReady !== null) {
                return $notReady;
            }

            // Persist the mutation boundary before calling media_publish. If the
            // process or connection dies after Meta accepts the request, a retry
            // must stop for manual reconciliation instead of publishing twice.
            $this->setPublishOutcomeUnknown($state, true);
            $this->persistState($context, $state);
            $finalPublishPending = true;

            $publish = $this->http->asForm()->post(InstagramGraphApi::baseUrl($context->account).'/'.$igUserId.'/media_publish', [
                'creation_id' => $containerId,
                'access_token' => $token,
            ]);

            $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $publish);

            if ($publish->failed()) {
                if ($publish->serverError()) {
                    return $this->publishNeedsReview();
                }

                $this->setPublishOutcomeUnknown($state, false);
                $this->persistState($context, $state);
                $finalPublishPending = false;

                return $this->mapFailure($publish);
            }

            $mediaId = (string) $publish->json('id');
        } catch (InstagramRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (InstagramReelNeedsVideo) {
            return PublishResult::failure(ErrorKind::Validation, 'Instagram Reels require a video.');
        } catch (InstagramCoverUnavailable) {
            return PublishResult::failure(ErrorKind::Validation, 'The Instagram cover must be an available workspace image for a single Reel video. Choose a new cover or remove the selection.');
        } catch (ImageConversionFailed $e) {
            // The image can't be re-encoded to the JPEG Instagram requires; retrying
            // won't change that, so fail with the reason rather than looping.
            return PublishResult::failure(ErrorKind::Unsupported, $e->getMessage());
        } catch (ConnectionException $e) {
            if ($finalPublishPending) {
                return $this->publishNeedsReview();
            }

            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        if ($mediaId === '') {
            return $this->publishNeedsReview();
        }

        // Clear the ambiguity marker and persist the returned media id in the same
        // row update. A worker death after this point can reconcile from remote_ids.
        $this->setPublishOutcomeUnknown($state, false);
        $context->target->forceFill([
            'remote_id' => $mediaId,
            'remote_ids' => [$mediaId],
            'media_upload_state' => $state->toArray(),
        ])->save();

        return PublishResult::success([$mediaId]);
    }

    /**
     * Create (or resume) the container that will be handed to media_publish: a single
     * image/Reels container, or a CAROUSEL parent referencing per-item child containers.
     */
    private function resolveContainerId(PublishContext $context, MediaUploadState $state, string $igUserId, string $caption, string $token, PostFormat $format): string
    {
        $existing = $state->remoteRef(self::CONTAINER_KEY);

        if ($existing !== null) {
            return $existing;
        }

        $media = array_slice($context->effectiveMedia(), 0, Platform::Instagram->maxMedia());
        $coverIssues = app(InstagramReelCover::class)->issues($context->target, $context->effectiveMedia());
        if ($coverIssues !== []) {
            throw new InstagramCoverUnavailable;
        }

        $containerId = match (true) {
            $format === PostFormat::Story => $this->createStoryContainer($context, $media[0], $igUserId, $token),
            $format === PostFormat::Reels => $this->createReelContainer($context, $this->firstVideo($media), $igUserId, $caption, $token),
            count($media) === 1 => $this->createSingleContainer($context, $media[0], $igUserId, $caption, $token),
            default => $this->createCarouselContainer($context, $state, $media, $igUserId, $caption, $token),
        };

        $state->markUploaded(self::CONTAINER_KEY, $containerId);
        $this->persistState($context, $state);

        return $containerId;
    }

    private function createSingleContainer(PublishContext $context, PostMedia $media, string $igUserId, string $caption, string $token): string
    {
        $body = [
            'caption' => $caption,
            'access_token' => $token,
        ];

        if ($media->isVideo()) {
            $body['media_type'] = 'REELS';
            $body['video_url'] = $this->publicMediaUrl->for($media, Platform::Instagram);
            $body = [...$body, ...$this->coverBody($context)];
        } else {
            $body['image_url'] = $this->publicMediaUrl->for($media, Platform::Instagram);
        }

        $response = $this->http->asForm()->post(InstagramGraphApi::baseUrl($context->account).'/'.$igUserId.'/media', $body);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new InstagramRequestFailed($response);
        }

        return (string) $response->json('id');
    }

    private function createStoryContainer(PublishContext $context, PostMedia $media, string $igUserId, string $token): string
    {
        $body = [
            'media_type' => 'STORIES',
            'access_token' => $token,
        ];
        $body[$media->isVideo() ? 'video_url' : 'image_url'] = $this->publicMediaUrl->for($media, Platform::Instagram);

        $response = $this->http->asForm()->post(InstagramGraphApi::baseUrl($context->account).'/'.$igUserId.'/media', $body);
        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new InstagramRequestFailed($response);
        }

        return (string) $response->json('id');
    }

    private function createReelContainer(PublishContext $context, ?PostMedia $video, string $igUserId, string $caption, string $token): string
    {
        if ($video === null) {
            throw new InstagramReelNeedsVideo;
        }

        $response = $this->http->asForm()->post(InstagramGraphApi::baseUrl($context->account).'/'.$igUserId.'/media', [
            'media_type' => 'REELS',
            'video_url' => $this->publicMediaUrl->for($video, Platform::Instagram),
            'caption' => $caption,
            'access_token' => $token,
            ...$this->coverBody($context),
        ]);
        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new InstagramRequestFailed($response);
        }

        return (string) $response->json('id');
    }

    /** @return array{cover_url?: string} */
    private function coverBody(PublishContext $context): array
    {
        $cover = app(InstagramReelCover::class)->resolve($context->target);
        if ($cover === null && ($context->target->content_override['instagram']['cover_media_id'] ?? null) !== null) {
            throw new InstagramCoverUnavailable;
        }

        return $cover === null ? [] : ['cover_url' => $this->publicMediaUrl->for($cover, Platform::Instagram)];
    }

    /** @param  list<PostMedia>  $media */
    private function firstVideo(array $media): ?PostMedia
    {
        foreach ($media as $item) {
            if ($item->isVideo()) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Create each unpublished carousel-item child container (resuming any already
     * persisted from a prior attempt), then the CAROUSEL parent referencing them.
     *
     * @param  list<PostMedia>  $media
     */
    private function createCarouselContainer(PublishContext $context, MediaUploadState $state, array $media, string $igUserId, string $caption, string $token): string
    {
        $childIds = [];

        foreach ($media as $item) {
            $childId = $state->remoteRef($item->id);

            if ($childId === null) {
                $childId = $this->createChildContainer($context, $item, $igUserId, $token);
                $state->markUploaded($item->id, $childId);
                $this->persistState($context, $state);
            }

            $childIds[] = $childId;
        }

        $response = $this->http->asForm()->post(InstagramGraphApi::baseUrl($context->account).'/'.$igUserId.'/media', [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $caption,
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new InstagramRequestFailed($response);
        }

        return (string) $response->json('id');
    }

    private function createChildContainer(PublishContext $context, PostMedia $media, string $igUserId, string $token): string
    {
        $body = [
            'is_carousel_item' => 'true',
            'media_type' => $media->isVideo() ? 'VIDEO' : 'IMAGE',
            'access_token' => $token,
        ];
        $body[$media->isVideo() ? 'video_url' : 'image_url'] = $this->publicMediaUrl->for($media, Platform::Instagram);

        $response = $this->http->asForm()->post(InstagramGraphApi::baseUrl($context->account).'/'.$igUserId.'/media', $body);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new InstagramRequestFailed($response);
        }

        return (string) $response->json('id');
    }

    private function persistState(PublishContext $context, MediaUploadState $state): void
    {
        $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
    }

    /**
     * Poll the container's processing status. Returns null when it is FINISHED (ready
     * to publish), or a PublishResult to return immediately (MediaProcessing to retry,
     * or a terminal failure) otherwise.
     */
    private function pollContainer(PublishContext $context, string $containerId, string $token): ?PublishResult
    {
        $response = $this->http->get(InstagramGraphApi::baseUrl($context->account).'/'.$containerId, [
            'fields' => 'status_code',
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        if ($response->failed()) {
            return $this->mapFailure($response);
        }

        $status = (string) $response->json('status_code');

        return match ($status) {
            'FINISHED' => null,
            'PUBLISHED' => $this->publishNeedsReview(),
            'IN_PROGRESS' => PublishResult::failure(ErrorKind::MediaProcessing, 'Instagram is processing the media.', retryAfter: 6),
            default => PublishResult::failure(
                ErrorKind::ServerError,
                "Instagram container processing failed ({$status}).",
                $response->status(),
                $this->excerpt($response),
            ),
        };
    }

    private function publishOutcomeUnknown(MediaUploadState $state): bool
    {
        return ($state->metadata(self::CONTAINER_KEY)['publish_outcome_unknown'] ?? false) === true;
    }

    private function setPublishOutcomeUnknown(MediaUploadState $state, bool $unknown): void
    {
        $metadata = $state->metadata(self::CONTAINER_KEY);
        if ($unknown) {
            $metadata['publish_outcome_unknown'] = true;
        } else {
            unset($metadata['publish_outcome_unknown']);
        }
        $state->setMetadata(self::CONTAINER_KEY, $metadata);
    }

    private function publishNeedsReview(): PublishResult
    {
        return PublishResult::failure(
            ErrorKind::Unknown,
            'Instagram may already have published this post, but Shoutrrr could not confirm its media id. Check Instagram before retrying.',
        );
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $id = $target->remote_id;

        if ($id === null) {
            return;
        }

        if ($token === '') {
            throw new RuntimeException('Instagram access token unavailable; reconnect the account.');
        }

        // IG media deletion is generally unsupported via the Graph API; best-effort the
        // call and swallow a 4xx (matches how the API responds to unsupported deletes)
        // rather than failing the whole delete flow over it.
        $response = $this->http->delete(InstagramGraphApi::baseUrl($target->account).'/'.$id, ['access_token' => $token]);

        $succeeded = $response->successful() || $response->status() === 404 || $response->clientError();

        $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $target->account, $response, succeeded: $succeeded);
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->classifyStatus($response->status());
        $message = (string) ($response->json('error.message') ?? 'Instagram request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }
}

/**
 * Internal signal so a failed container-create call short-circuits to the shared
 * HTTP-error mapping. Not part of the public connector surface.
 *
 * @internal
 */
final class InstagramRequestFailed extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Instagram request failed.');
    }
}

/**
 * Internal signal so a Reels container request without a video short-circuits to a
 * Validation failure. Not part of the public connector surface.
 *
 * @internal
 */
final class InstagramReelNeedsVideo extends RuntimeException {}

final class InstagramCoverUnavailable extends RuntimeException {}
