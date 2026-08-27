<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Publishes to a Discord channel through its webhook URL. Each non-empty segment
 * becomes its own sequential channel message (Discord channels are already a
 * linear feed, so no reply-chaining is needed). Media is uploaded as multipart
 * `files[n]` alongside a `payload_json` content field. Explicit placements keep
 * each attachment on its authored
 * section. `?wait=true` makes Discord return the created message so its id
 * can be stored for later delete/metrics.
 */
class DiscordPublishConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    public function publish(PublishContext $context): PublishResult
    {
        $webhookUrl = (string) ($context->credentials['webhook_url'] ?? '');

        if ($webhookUrl === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'Discord webhook unavailable; reconnect the account.');
        }

        $messages = [];
        foreach ($context->segments as $sectionIndex => $segment) {
            $text = trim($segment);
            $sectionMedia = array_slice(
                $context->mediaForSection($sectionIndex),
                0,
                Platform::Discord->maxMedia(),
            );

            if ($text !== '' || $sectionMedia !== []) {
                $messages[] = ['text' => $text, 'media' => $sectionMedia];
            }
        }

        if ($messages === [] && $context->effectiveMedia() !== []) {
            $messages[] = [
                'text' => '',
                'media' => array_slice($context->effectiveMedia(), 0, Platform::Discord->maxMedia()),
            ];
        }

        if ($messages === []) {
            return PublishResult::failure(ErrorKind::Validation, 'Discord requires text or media.');
        }

        $remoteIds = $context->target->remote_ids ?? [];

        try {
            foreach ($messages as $index => $message) {
                // Resume: skip segments already posted on a prior attempt.
                if (isset($remoteIds[$index])) {
                    continue;
                }

                $response = $this->send($webhookUrl, $message['text'], $message['media']);

                $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $response);

                if ($response->failed()) {
                    return $this->mapFailure($response);
                }

                $messageId = (string) $response->json('id');

                if ($messageId === '') {
                    return PublishResult::failure(
                        ErrorKind::Unknown,
                        'Discord accepted the publish request but returned no message id. The message may already be live; check Discord before taking further action.',
                    );
                }

                $remoteIds[$index] = $messageId;

                // Persist this segment's id BEFORE sending the next one so a mid-thread
                // death resumes (rather than re-posts) the already-published segments.
                $context->target->forceFill([
                    'remote_id' => $remoteIds[0],
                    'remote_ids' => array_values($remoteIds),
                ])->save();
            }
        } catch (ConnectionException $e) {
            return PublishResult::failure(
                ErrorKind::Unknown,
                'The connection closed after Discord received the publish request. The message may already be live; check Discord before taking further action.',
            );
        }

        return PublishResult::success(array_values($remoteIds));
    }

    /**
     * @param  list<PostMedia>  $media
     */
    private function send(string $webhookUrl, string $text, array $media): Response
    {
        $endpoint = $webhookUrl.'?wait=true';

        if ($media === []) {
            return $this->http()->post($endpoint, ['content' => $text]);
        }

        $request = $this->http();

        foreach ($media as $i => $item) {
            $bytes = (string) Storage::disk($item->disk)->get($item->path);
            $request = $request->attach("files[{$i}]", $bytes, $this->filename($item, $i));
        }

        return $request->post($endpoint, [
            'payload_json' => json_encode(['content' => $text], JSON_THROW_ON_ERROR),
        ]);
    }

    private function filename(PostMedia $media, int $index): string
    {
        $extension = match ($media->mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            default => 'jpg',
        };

        return "media-{$index}.{$extension}";
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $webhookUrl = (string) ($credentials['webhook_url'] ?? '');
        $ids = $target->remote_ids ?? array_filter([$target->remote_id]);

        if ($ids === []) {
            return;
        }

        if ($webhookUrl === '') {
            throw new RuntimeException('Discord webhook unavailable; reconnect the account.');
        }

        foreach ($ids as $id) {
            $response = $this->http()->delete($webhookUrl.'/messages/'.$id);

            // 404 = already gone; any 2xx (Discord returns 204) = deleted.
            $succeeded = $response->successful() || $response->status() === 404;

            $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $target->account, $response, succeeded: $succeeded);
        }
    }

    private function http(): PendingRequest
    {
        return app(HttpFactory::class)->timeout(15)->connectTimeout(5)->acceptJson();
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->classifyStatus($response->status());
        $message = (string) ($response->json('message') ?? 'Discord request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }
}
