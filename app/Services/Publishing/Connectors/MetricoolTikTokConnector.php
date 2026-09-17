<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Exceptions\TikTokCreatorInfoException;
use App\Models\PostTarget;
use App\Services\ConnectedAccounts\TikTok\TikTokPostOptions;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Publishing\Metricool\MetricoolClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MetricoolTikTokConnector implements PublishConnector
{
    public function __construct(private readonly MetricoolClient $client, private readonly TikTokPostOptions $options) {}

    public function publish(PublishContext $context): PublishResult
    {
        $saved = $context->target->media_upload_state['_metricool'] ?? [];
        if (! is_array($saved)) {
            return PublishResult::failure(ErrorKind::Unsupported, 'The saved Metricool operation is invalid. Review it before publishing.');
        }
        try {
            if (isset($saved['post_id']) || ($saved['create_outcome_unknown'] ?? false) === true) {
                $binding = $this->savedBinding($context, $saved);

                return isset($saved['post_id'])
                    ? $this->poll($context, $binding, $saved)
                    : $this->reconcile($context, $binding, $saved);
            }
            $binding = $this->client->binding($context->account);
            foreach (['user_id', 'blog_id', 'expected_handle'] as $field) {
                if (isset($saved[$field]) && $saved[$field] !== $binding[$field]) {
                    return PublishResult::awaitingAction([], 'The Metricool destination changed after this attempt began. Reconcile the original operation before starting another.');
                }
            }
            $media = $context->effectiveMedia();
            if (count($media) !== 1 || ! $media[0]->isVideo()) {
                return PublishResult::failure(ErrorKind::Validation, 'TikTok publishing requires exactly one video.');
            }
            $media = $media[0];
            $options = $saved['post_options'] ?? $context->target->content_override['tiktok'] ?? [];
            $options = is_array($options) ? $options : [];
            $issues = $this->options->issues($options, $binding['creator'], $media->duration_seconds);
            if ($issues !== []) {
                return PublishResult::failure(ErrorKind::Validation, $this->options->describe($issues[0]));
            }
            $caption = $saved['caption'] ?? implode("\n\n", $context->segments);
            if (! is_string($caption) || intdiv(strlen((string) mb_convert_encoding($caption, 'UTF-16LE', 'UTF-8')), 2) > 2200) {
                return PublishResult::failure(ErrorKind::Validation, 'TikTok captions must be no longer than 2,200 UTF-16 characters.');
            }
            $source = hash('sha256', json_encode([$media->id, $media->disk, $media->path, $media->mime, $media->size_bytes, $media->updated_at?->toISOString()], JSON_THROW_ON_ERROR));
            if (isset($saved['source_version']) && $saved['source_version'] !== $source) {
                return PublishResult::awaitingAction([], 'The video changed after the Metricool attempt began. Review the saved upload before replacing it.');
            }
            $saved = [
                ...$saved,
                'user_id' => $binding['user_id'], 'blog_id' => $binding['blog_id'], 'expected_handle' => $binding['expected_handle'],
                'account_id' => $context->account->id, 'workspace_id' => $context->account->workspace_id,
                'uuid' => $saved['uuid'] ?? (string) Str::uuid(), 'post_options' => $options,
                'caption' => $caption, 'media_id' => $media->id, 'source_version' => $source,
            ];
            $this->persist($context, $saved);
            if (! is_string($saved['media_url'] ?? null)) {
                $saved['media_url'] = $this->client->uploadVideo($binding, $media);
                $this->persist($context, $saved);
            }
            // Uploads can take minutes; a brand may have been reconnected meanwhile.
            $account = $context->account->fresh();
            if ($account === null || $account->id !== $saved['account_id'] || $account->workspace_id !== $saved['workspace_id']) {
                return PublishResult::failure(ErrorKind::Unsupported, 'The connected account changed during upload. Review the account before publishing.');
            }
            $binding = $this->client->binding($account);
            foreach (['user_id', 'blog_id', 'expected_handle'] as $field) {
                if ($saved[$field] !== $binding[$field]) {
                    return PublishResult::awaitingAction([], 'The Metricool destination changed during upload. Review the saved upload before publishing.');
                }
            }
            $issues = $this->options->issues($options, $binding['creator'], $media->duration_seconds);
            if ($issues !== []) {
                return PublishResult::failure(ErrorKind::Validation, $this->options->describe($issues[0]));
            }
            if (! $this->beginSubmission($context, $saved)) {
                return PublishResult::failure(ErrorKind::Unsupported, 'The post changed or was cancelled during upload. Refresh its status before publishing.');
            }
            try {
                $post = $this->client->request($binding, 'POST', '/v2/scheduler/posts', ['jobId' => $saved['uuid']], [
                    'uuid' => $saved['uuid'], 'publicationDate' => $saved['publication_date'], 'text' => $caption,
                    'providers' => [['network' => 'TIKTOK']], 'media' => [$saved['media_url']],
                    'autoPublish' => true, 'draft' => false, 'saveExternalMediaFiles' => true,
                    'videoCoverMilliseconds' => $options['video_cover_timestamp_ms'] ?? 0,
                    'tiktokData' => [
                        'privacyOption' => strtolower((string) $options['privacy_level']),
                        'disableComment' => $options['disable_comment'], 'disableDuet' => $options['disable_duet'], 'disableStitch' => $options['disable_stitch'],
                        'commercialContentOwnBrand' => $options['brand_organic_toggle'], 'commercialContentThirdParty' => $options['brand_content_toggle'],
                        'isAigc' => $options['is_aigc'], 'autoAddMusic' => false, 'title' => '', 'photoCoverIndex' => 0,
                    ],
                ]);
            } catch (TikTokCreatorInfoException $exception) {
                if (in_array($exception->httpStatus, [400, 401, 402, 403, 422, 429], true)) {
                    $saved['create_outcome_unknown'] = false;
                    unset($saved['publication_date']);
                    $this->persist($context, $saved);
                    throw $exception;
                }

                return $this->pendingReconciliation($context, $saved);
            }
            $id = $post['id'] ?? null;
            if (! is_int($id) || $id < 1) {
                return $this->pendingReconciliation($context, $saved);
            }
            $saved['post_id'] = $id;
            $saved['create_outcome_unknown'] = false;
            $this->persist($context, $saved);

            return $this->poll($context, $binding, $saved);
        } catch (TikTokCreatorInfoException $exception) {
            return PublishResult::failure($exception->errorKind, $exception->getMessage(), $exception->httpStatus, retryAfter: $exception->errorKind->isRetryable() ? 30 : null);
        }
    }

    /** @param array<string, mixed> $saved */
    private function beginSubmission(PublishContext $context, array &$saved): bool
    {
        return DB::transaction(function () use ($context, &$saved): bool {
            $target = PostTarget::query()->lockForUpdate()->find($context->target->id);
            if ($target === null || $target->connected_account_id !== $context->account->id
                || $target->post_id !== $context->target->post_id
                || in_array($target->status, [PostTargetStatus::Deleted, PostTargetStatus::Deleting, PostTargetStatus::Skipped], true)) {
                return false;
            }
            $post = $target->post;
            if ($post === null || $post->deleted_at !== null || $post->status === PostStatus::Deleted) {
                return false;
            }
            $state = $target->media_upload_state ?? [];
            $current = $state['_metricool'] ?? [];
            if (! is_array($current) || ($current['uuid'] ?? null) !== ($saved['uuid'] ?? null)
                || isset($current['post_id']) || ($current['create_outcome_unknown'] ?? false) === true) {
                return false;
            }
            $saved['publication_date'] ??= ['dateTime' => now()->utc()->addMinute()->format('Y-m-d\TH:i:s'), 'timezone' => 'UTC'];
            $saved['create_outcome_unknown'] = true;
            $state['_tiktok_provider'] = 'metricool';
            $state['_metricool'] = $saved;
            $target->forceFill(['media_upload_state' => $state])->save();
            $context->target->forceFill(['media_upload_state' => $state]);

            return true;
        });
    }

    /** @param array<string, mixed> $binding
     * @param  array<string, mixed>  $saved
     */
    private function reconcile(PublishContext $context, array $binding, array $saved): PublishResult
    {
        if (! is_string($saved['uuid'] ?? null) || ! Str::isUuid($saved['uuid'])) {
            return $this->unknown();
        }
        $posts = $this->client->request($binding, 'GET', '/v2/scheduler/posts', ['filter' => json_encode(['uuid' => $saved['uuid']], JSON_THROW_ON_ERROR)]);
        $matches = array_values(array_filter($posts, static fn (mixed $post): bool => is_array($post) && ($post['uuid'] ?? null) === $saved['uuid']));
        if (count($matches) !== 1 || ! is_int($matches[0]['id'] ?? null) || $matches[0]['id'] < 1
            || ($matches[0]['text'] ?? null) !== ($saved['caption'] ?? null)
            || ($matches[0]['media'] ?? null) !== [$saved['media_url'] ?? null]) {
            return $this->pendingReconciliation($context, $saved);
        }
        $saved['post_id'] = $matches[0]['id'];
        $saved['create_outcome_unknown'] = false;
        $this->persist($context, $saved);

        return $this->poll($context, $binding, $saved);
    }

    /** @param array<string, mixed> $binding
     * @param  array<string, mixed>  $saved
     */
    private function poll(PublishContext $context, array $binding, array $saved): PublishResult
    {
        if (! is_int($saved['post_id']) || $saved['post_id'] < 1) {
            return $this->unknown();
        }
        $post = $this->client->request($binding, 'GET', '/v2/scheduler/posts/'.$saved['post_id']);
        $providers = $post['providers'] ?? null;
        if (($post['id'] ?? null) !== $saved['post_id'] || ! is_array($providers) || count($providers) !== 1
            || ! is_array($providers[0]) || strtolower((string) ($providers[0]['network'] ?? '')) !== 'tiktok') {
            return $this->unknown();
        }
        $provider = $providers[0];
        $status = $provider['status'] ?? null;
        $saved['provider_status'] = is_string($status) ? $status : 'UNKNOWN';
        $this->persist($context, $saved);
        if (in_array($status, ['PENDING', 'PUBLISHING'], true)) {
            return PublishResult::failure(ErrorKind::MediaProcessing, 'Metricool is publishing the TikTok video. Public publication is not confirmed yet.', retryAfter: 30);
        }
        if ($status === 'ERROR') {
            $saved['terminal'] = true;
            $saved['terminal_outcome'] = 'failed';
            $this->persist($context, $saved);

            return PublishResult::failure(ErrorKind::Validation, 'Metricool could not publish this TikTok post. Review the existing Metricool job before submitting another.');
        }
        if ($status === 'PUBLISHED') {
            if (($saved['post_options']['privacy_level'] ?? null) !== 'PUBLIC_TO_EVERYONE') {
                $saved['terminal'] = true;
                $saved['terminal_outcome'] = 'restricted';
                $this->persist($context, $saved);

                return PublishResult::completed([], 'Metricool completed the TikTok submission using the selected restricted visibility. It is not a public post.');
            }
            $url = $provider['publicUrl'] ?? null;
            $parts = is_string($url) ? parse_url($url) : false;
            if (is_array($parts) && ($parts['scheme'] ?? null) === 'https'
                && in_array($parts['host'] ?? null, ['www.tiktok.com', 'tiktok.com'], true)
                && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
                && preg_match('~\A/@([^/]+)/video/([0-9]+)\z~', $parts['path'] ?? '', $matches) === 1
                && strtolower($matches[1]) === $saved['expected_handle']) {
                $saved['public_url'] = 'https://www.tiktok.com/@'.$saved['expected_handle'].'/video/'.$matches[2];
                $saved['remote_id'] = $matches[2];
                $saved['terminal'] = true;
                $saved['terminal_outcome'] = 'published';
                $this->persist($context, $saved);

                return PublishResult::success([$matches[2]]);
            }

            return PublishResult::awaitingAction([], 'Metricool reports completion but has not supplied a public TikTok URL for the expected account. Reconcile the existing post before retrying.');
        }

        return PublishResult::awaitingAction([], 'The existing Metricool TikTok post needs review or confirmation. It is not confirmed live and will not be resubmitted automatically.');
    }

    /** @param array<string, mixed> $saved
     * @return array{user_id: string, blog_id: string, expected_handle: string}
     */
    private function savedBinding(PublishContext $context, array $saved): array
    {
        foreach (['user_id', 'blog_id'] as $field) {
            if (! is_string($saved[$field] ?? null) || preg_match('/\A[1-9][0-9]*\z/', $saved[$field]) !== 1) {
                throw new TikTokCreatorInfoException('The saved Metricool destination is invalid. Reconcile the existing operation before posting.', ErrorKind::Unsupported);
            }
        }
        if (($saved['account_id'] ?? null) !== $context->account->id
            || ($saved['workspace_id'] ?? null) !== $context->account->workspace_id
            || ! is_string($saved['expected_handle'] ?? null)
            || preg_match('/\A[a-z0-9._]{1,24}\z/', $saved['expected_handle']) !== 1) {
            throw new TikTokCreatorInfoException('The saved Metricool account binding is invalid. Reconcile the existing operation before posting.', ErrorKind::Unsupported);
        }

        return ['user_id' => $saved['user_id'], 'blog_id' => $saved['blog_id'], 'expected_handle' => $saved['expected_handle']];
    }

    /** @param array<string, mixed> $saved */
    private function pendingReconciliation(PublishContext $context, array $saved): PublishResult
    {
        $saved['reconciliation_checks'] = (int) ($saved['reconciliation_checks'] ?? 0) + 1;
        $this->persist($context, $saved);
        if ($saved['reconciliation_checks'] >= 5) {
            return $this->unknown();
        }

        return PublishResult::failure(ErrorKind::MediaProcessing, 'Metricool may have accepted this TikTok post. Checking the saved operation without submitting another copy.', retryAfter: 30);
    }

    /** @param array<string, mixed> $saved */
    private function persist(PublishContext $context, array $saved): void
    {
        $state = $context->target->media_upload_state ?? [];
        $state['_tiktok_provider'] = 'metricool';
        $state['_metricool'] = $saved;
        $context->target->forceFill(['media_upload_state' => $state])->save();
    }

    private function unknown(): PublishResult
    {
        return PublishResult::awaitingAction([], 'Metricool may have accepted this TikTok post, but its result is unconfirmed. The saved operation must be reconciled; no duplicate will be submitted.');
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        throw new RuntimeException('Delete or cancel this post in Metricool or TikTok first. Shoutrrr cannot confirm remote deletion.');
    }
}
