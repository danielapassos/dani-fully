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
use App\Services\Media\PublicMediaUrl;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Transfers one video to the creator's TikTok inbox. TikTok, not this app,
 * owns the final caption/privacy/music choices and the creator must publish it
 * natively. The connector keeps polling until TikTok reports the final post.
 */
class TikTokConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    private const string BASE_URL = 'https://open.tiktokapis.com/v2/post/publish';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PublicMediaUrl $publicMediaUrl,
    ) {}

    public function publish(PublishContext $context): PublishResult
    {
        if (! config('services.tiktok.inbox_enabled')) {
            return PublishResult::failure(
                ErrorKind::Unsupported,
                'TikTok inbox publishing is disabled until the developer-app review and verified media domain are ready.',
            );
        }

        $token = (string) ($context->credentials['access_token'] ?? '');
        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'TikTok access token unavailable; reconnect the account.');
        }

        if (count($context->media) !== 1 || ! $context->media[0]->isVideo()) {
            return PublishResult::failure(ErrorKind::Validation, 'TikTok inbox publishing requires exactly one video.');
        }

        $media = $context->media[0];
        $state = new MediaUploadState($context->target->media_upload_state);
        $publishId = $state->remoteRef($media->id);
        $initializing = false;

        try {
            if ($publishId === null) {
                if ($this->initOutcomeUnknown($state, $media)) {
                    return $this->initNeedsReview();
                }

                $mediaUrl = $this->publicMediaUrl->for($media);
                if (parse_url($mediaUrl, PHP_URL_SCHEME) !== 'https') {
                    return PublishResult::failure(
                        ErrorKind::Validation,
                        'TikTok must fetch the video from a verified public HTTPS media domain.',
                    );
                }

                // An inbox-init response can be lost after TikTok creates the
                // transfer. Persist the mutation boundary first so a retry cannot
                // silently create a second inbox notification.
                $this->setInitOutcomeUnknown($state, $media, true);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
                $initializing = true;

                $response = $this->request($token)->post(self::BASE_URL.'/inbox/video/init/', [
                    'source_info' => [
                        'source' => 'PULL_FROM_URL',
                        'video_url' => $mediaUrl,
                    ],
                ]);

                $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

                if ($failure = $this->failure($response, 'TikTok could not transfer the video to the creator inbox.')) {
                    if ($response->serverError()) {
                        return $this->initNeedsReview();
                    }

                    $this->setInitOutcomeUnknown($state, $media, false);
                    $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
                    $initializing = false;

                    return $failure;
                }

                $publishId = (string) $response->json('data.publish_id');
                if ($publishId === '') {
                    return $this->initNeedsReview();
                }

                $state->markUploaded($media->id, $publishId);
                $this->setInitOutcomeUnknown($state, $media, false);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
                $initializing = false;
            }

            return $this->status($context, $token, $publishId, $media, $state);
        } catch (ConnectionException $exception) {
            if ($initializing) {
                return $this->initNeedsReview();
            }

            return PublishResult::failure(ErrorKind::Network, $exception->getMessage());
        }
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

    private function status(PublishContext $context, string $token, string $publishId, PostMedia $media, MediaUploadState $state): PublishResult
    {
        $response = $this->request($token)->post(self::BASE_URL.'/status/fetch/', [
            'publish_id' => $publishId,
        ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        if ($failure = $this->failure($response, 'TikTok could not read the inbox transfer status.')) {
            return $failure;
        }

        $status = strtoupper((string) $response->json('data.status'));

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
