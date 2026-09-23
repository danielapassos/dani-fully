<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Exceptions\TikTokCreatorInfoException;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\PostTarget;
use App\Services\Auth\TikTokAccountsOAuthProvider;
use App\Services\ConnectedAccounts\TikTok\TikTokPostOptions;
use App\Services\Media\PublicMediaUrl;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Publishing\SegmentMediaResolver;
use App\Services\Publishing\TargetMediaSelection;
use App\Services\Publishing\TikTokAccounts\TikTokAccountsClient;
use App\Services\Publishing\TikTokAccounts\TikTokAccountsRequestException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TikTokAccountsConnector implements PublishConnector
{
    public function __construct(private readonly TikTokAccountsClient $client, private readonly TikTokPostOptions $options, private readonly PublicMediaUrl $mediaUrls) {}

    public function publish(PublishContext $context): PublishResult
    {
        $state = $context->target->media_upload_state ?? [];
        $saved = $state['_tiktok_accounts'] ?? [];
        if (! is_array($saved) || isset($state['_metricool'])
            || (isset($state['_tiktok_provider']) && $state['_tiktok_provider'] !== 'accounts_api')) {
            return $this->unknown('The saved TikTok Accounts API route is inconsistent. Review the existing submission.');
        }
        foreach ($state as $key => $entry) {
            if (! str_starts_with((string) $key, '_') && $key !== 'publication' && is_array($entry) && $entry !== []) {
                return $this->unknown('A native TikTok submission is already recorded. Reconcile it before using the Accounts API.');
            }
        }
        $hasOperation = array_key_exists('share_id', $saved) || array_key_exists('publish_id', $saved);
        if ($saved !== []) {
            foreach (['open_id', 'client_id', 'expected_handle', 'account_id', 'workspace_id'] as $field) {
                if (! is_string($saved[$field] ?? null) || $saved[$field] === '') {
                    return $this->unknown('The saved TikTok Accounts API identity is incomplete. Reconcile the existing submission.');
                }
            }
            if ($saved['client_id'] !== config('services.tiktok_accounts.client_id')) {
                return $this->unknown('The TikTok Accounts API application changed after this attempt began. Reconcile the original submission.');
            }
        }
        if (($saved['create_outcome_unknown'] ?? false) === true && ! $hasOperation) {
            return $this->unknown();
        }
        if (! $hasOperation && ($context->target->remote_id !== null || ($context->target->remote_ids ?? []) !== [])) {
            return $this->unknown('A TikTok post is already recorded. Reconcile it before starting a new Accounts API submission.');
        }
        try {
            $binding = $this->client->binding($context->account);
            foreach (['open_id' => $binding['open_id'], 'client_id' => $binding['client_id'], 'expected_handle' => $binding['expected_handle'], 'account_id' => $context->account->id, 'workspace_id' => $context->account->workspace_id] as $field => $value) {
                if (isset($saved[$field]) && $saved[$field] !== $value) {
                    return $this->unknown('The TikTok Accounts API destination changed after this attempt began. Reconcile the original submission.');
                }
            }
            if ($hasOperation) {
                if (! is_string($saved['publish_id'] ?? null) || $saved['publish_id'] === '' || ($saved['share_id'] ?? null) !== $saved['publish_id']) {
                    return $this->unknown();
                }

                return $this->poll($context, $binding, $saved);
            }
            if ($context->account->isDisabled()) {
                return PublishResult::failure(ErrorKind::Unsupported, 'This account is disabled. Re-enable it before posting.');
            }
            $media = $context->effectiveMedia();
            if (count($media) !== 1 || ! $media[0]->isVideo()) {
                return PublishResult::failure(ErrorKind::Validation, 'TikTok Accounts API publishing requires exactly one video.');
            }
            $media = $media[0];
            if ($media->size_bytes < 1 || $media->size_bytes > 1024 * 1024 * 1024
                || ! in_array($media->mime, ['video/mp4', 'video/quicktime', 'video/webm'], true)
                || $media->duration_seconds === null || $media->duration_seconds < 3 || $media->duration_seconds > 600
                || ($media->width ?? 0) < 360 || ($media->height ?? 0) < 360) {
                return PublishResult::failure(ErrorKind::Validation, 'TikTok Accounts API requires an MP4, MOV, or WebM video up to 1 GB, 3–600 seconds long, with width and height of at least 360 pixels.');
            }
            $options = $saved['post_options'] ?? $context->target->content_override['tiktok'] ?? [];
            $options = is_array($options) ? $options : [];
            if (($options['privacy_level'] ?? null) !== 'PUBLIC_TO_EVERYONE') {
                return PublishResult::failure(ErrorKind::Validation, 'TikTok Accounts API publishing supports public posts only. Review the visibility choice before publishing.');
            }
            $issues = $this->options->issues($options, $binding['creator'], $media->duration_seconds);
            if ($issues !== []) {
                return PublishResult::failure(ErrorKind::Validation, $this->options->describe($issues[0]));
            }
            $caption = $saved['caption'] ?? implode("\n\n", $context->segments);
            if (! is_string($caption) || intdiv(strlen((string) mb_convert_encoding($caption, 'UTF-16LE', 'UTF-8')), 2) > 2200) {
                return PublishResult::failure(ErrorKind::Validation, 'TikTok captions must be no longer than 2,200 UTF-16 characters.');
            }
            $source = $this->mediaUrls->tikTokSourceVersion($media);
            if (isset($saved['source_version']) && ($saved['source_version'] !== $source || ($saved['media_id'] ?? null) !== $media->id
                || ($saved['media_updated_at'] ?? null) !== $media->getRawOriginal('updated_at'))) {
                return $this->unknown('The video changed after the TikTok Accounts API attempt began. Review the saved submission before replacing it.');
            }
            try {
                $videoUrl = $this->mediaUrls->forTikTok($media);
            } catch (RuntimeException) {
                return PublishResult::failure(ErrorKind::Unsupported, 'Configure the canonical HTTPS media URL before TikTok Accounts API publishing.');
            }
            $this->client->verifyMediaUrl($videoUrl);
            $postInfo = [
                'caption' => $caption,
                'disable_comment' => $options['disable_comment'], 'disable_duet' => $options['disable_duet'], 'disable_stitch' => $options['disable_stitch'],
                'is_brand_organic' => $options['brand_organic_toggle'], 'is_branded_content' => $options['brand_content_toggle'],
                'is_ai_generated' => $options['is_aigc'], 'thumbnail_offset' => $options['video_cover_timestamp_ms'] ?? 0,
                'upload_to_draft' => false, 'is_ads_only' => false,
            ];
            $saved = [
                ...$saved, 'open_id' => $binding['open_id'], 'client_id' => $binding['client_id'], 'expected_handle' => $binding['expected_handle'],
                'account_id' => $context->account->id, 'workspace_id' => $context->account->workspace_id,
                'media_id' => $media->id, 'source_version' => $source, 'media_updated_at' => $media->getRawOriginal('updated_at'),
                'caption' => $caption, 'post_options' => $options,
            ];
            if (! $this->beginSubmission($context, $saved, $binding)) {
                return $this->unknown('The post changed or another submission started. Refresh the saved publishing state before retrying.');
            }
            try {
                $created = $this->client->create($binding, $videoUrl, $postInfo);
            } catch (TikTokAccountsRequestException $exception) {
                if (! $exception->definiteRejection) {
                    return $this->unknown();
                }
                $saved['create_outcome_unknown'] = false;
                $saved['last_rejection'] = ['code' => $exception->providerCode, 'request_id' => $exception->requestId, 'http_status' => $exception->httpStatus];
                $this->persist($context, $saved);

                throw $exception;
            }
            $shareId = $created['share_id'] ?? null;
            if (! is_string($shareId) || trim($shareId) === '' || strlen($shareId) > 256) {
                return $this->unknown();
            }
            $saved['share_id'] = $shareId;
            $saved['publish_id'] = $shareId;
            $saved['create_outcome_unknown'] = false;
            unset($saved['last_rejection']);
            $this->persist($context, $saved);

            return $this->poll($context, $binding, $saved);
        } catch (TikTokCreatorInfoException $exception) {
            return PublishResult::failure($exception->errorKind, $exception->getMessage(), $exception->httpStatus, retryAfter: $exception->errorKind->isRetryable() ? 30 : null);
        } catch (TokenRefreshException) {
            return PublishResult::failure(ErrorKind::AuthExpired, 'Reconnect this account to the TikTok Accounts API before publishing.');
        }
    }

    /** @param array<string, mixed> $saved
     * @param  array<string, mixed>  $binding
     */
    private function beginSubmission(PublishContext $context, array &$saved, array $binding): bool
    {
        return DB::transaction(function () use ($context, &$saved, $binding): bool {
            $target = PostTarget::query()->lockForUpdate()->find($context->target->id);
            if ($target === null || $target->connected_account_id !== $context->account->id
                || $target->post_id !== $context->target->post_id
                || ! in_array($target->status, [PostTargetStatus::Pending, PostTargetStatus::Publishing], true)) {
                return false;
            }
            $post = $target->post;
            $account = ConnectedAccount::withoutGlobalScopes()->lockForUpdate()->find($context->account->id);
            if ($post === null || $post->deleted_at !== null || $post->status === PostStatus::Deleted
                || $post->workspace_id !== $context->account->workspace_id || $account === null || $account->isDisabled()
                || $account->workspace_id !== $saved['workspace_id'] || $account->remote_account_id !== $context->account->remote_account_id
                || strtolower(ltrim($account->handle, '@')) !== $saved['expected_handle']) {
                return false;
            }
            if ($target->sections !== $context->segments || implode("\n\n", $target->sections) !== $saved['caption']
                || ($target->content_override['tiktok'] ?? []) !== $saved['post_options']
                || config('services.tiktok_accounts.client_id') !== $saved['client_id']) {
                return false;
            }
            $secret = ConnectedAccountSecret::query()->lockForUpdate()->find($account->id);
            $authorization = $secret?->session['tiktok_accounts'] ?? null;
            if (! is_array($authorization)
                || ($authorization['account_id'] ?? null) !== $account->id
                || ($authorization['workspace_id'] ?? null) !== $account->workspace_id
                || ($authorization['client_id'] ?? null) !== $binding['client_id']
                || ($authorization['open_id'] ?? null) !== $binding['open_id']
                || ($authorization['handle'] ?? null) !== $binding['expected_handle']
                || ($authorization['reconnect_required'] ?? false) !== false || isset($authorization['revoked_at'])
                || ! is_int($authorization['expires_at'] ?? null) || $authorization['expires_at'] <= now()->timestamp
                || ! is_string($authorization['access_token'] ?? null)
                || ! hash_equals($authorization['access_token'], $binding['access_token'])
                || ! is_array($authorization['scopes'] ?? null)
                || array_diff(TikTokAccountsOAuthProvider::REQUIRED_SCOPES, $authorization['scopes']) !== []) {
                throw new TokenRefreshException('TikTok Accounts authorization changed before submission.');
            }
            $allMedia = array_values($post->media()->lockForUpdate()->get()->all());
            $selection = app(TargetMediaSelection::class)->resolve($target, $target->placements()->lockForUpdate()->get());
            $currentContext = new PublishContext($target, $target->sections, $allMedia, $account, [], app(SegmentMediaResolver::class)->resolve(
                $target->sections, $target->section_sources ?? [], $target->segment_breaks ?? [],
                $selection['placements'], $allMedia, $selection['explicit'],
            ));
            $media = $currentContext->effectiveMedia();
            if (count($media) !== 1 || $media[0]->id !== $saved['media_id'] || $media[0]->workspace_id !== $saved['workspace_id']
                || $this->mediaUrls->tikTokSourceVersion($media[0]) !== $saved['source_version']
                || $media[0]->getRawOriginal('updated_at') !== $saved['media_updated_at']) {
                return false;
            }
            $state = $target->media_upload_state ?? [];
            foreach ($state as $key => $entry) {
                if ($key === '_metricool' || (! str_starts_with((string) $key, '_') && $key !== 'publication' && is_array($entry) && $entry !== [])) {
                    return false;
                }
            }
            $current = $state['_tiktok_accounts'] ?? [];
            if (! is_array($current) || array_key_exists('publish_id', $current) || array_key_exists('share_id', $current) || ($current['create_outcome_unknown'] ?? false) === true
                || (isset($state['_tiktok_provider']) && $state['_tiktok_provider'] !== 'accounts_api')) {
                return false;
            }
            $saved['create_outcome_unknown'] = true;
            $saved['submitted_at'] = now()->toIso8601String();
            $state['_tiktok_provider'] = 'accounts_api';
            $state['_tiktok_accounts'] = $saved;
            $target->forceFill(['media_upload_state' => $state])->save();
            $context->target->forceFill(['media_upload_state' => $state]);

            return true;
        });
    }

    /** @param array<string, mixed> $binding
     * @param  array<string, mixed>  $saved
     */
    private function poll(PublishContext $context, array $binding, array $saved): PublishResult
    {
        $status = $this->client->status($binding, $saved['publish_id']);
        $saved['last_status'] = is_string($status['status'] ?? null) ? $status['status'] : 'UNKNOWN';
        $this->persist($context, $saved);
        if ($saved['last_status'] === 'FAILED') {
            return PublishResult::failure(ErrorKind::Validation, 'TikTok Accounts API rejected the submitted video. Review the existing publishing task before retrying.');
        }
        if ($saved['last_status'] === 'SEND_TO_USER_INBOX') {
            return PublishResult::awaitingAction([], 'TikTok returned an inbox draft instead of the requested public post. Review the existing submission in TikTok.');
        }
        if ($saved['last_status'] === 'PROCESSING_DOWNLOAD') {
            return $this->processing();
        }
        if ($saved['last_status'] !== 'PUBLISH_COMPLETE') {
            return $this->unknown('TikTok returned an unrecognized publishing status. Reconcile the saved submission before retrying.');
        }
        $ids = $status['post_ids'] ?? [];
        if ($ids === []) {
            return $this->processing();
        }
        if (! is_array($ids) || count($ids) !== 1 || ! is_string($ids[0] ?? null) || preg_match('/\A[1-9][0-9]{5,29}\z/', $ids[0]) !== 1) {
            return $this->unknown('TikTok returned unexpected public post identifiers. Reconcile the saved submission.');
        }
        $id = $ids[0];
        $saved['public_post_id'] = $id;
        $this->persist($context, $saved);
        $matches = array_values(array_filter($this->client->publicVideos($binding, $id), static fn (array $video): bool => ($video['item_id'] ?? null) === $id));
        if ($matches === []) {
            return $this->processing();
        }
        if (count($matches) !== 1 || ! is_string($matches[0]['share_url'] ?? null)) {
            return $this->unknown('TikTok could not confirm a unique public post URL for this submission.');
        }
        $url = $matches[0]['share_url'];
        $parts = parse_url($url);
        $expectedPath = '/@'.$binding['expected_handle'].'/video/'.$id;
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'www.tiktok.com'
            || ($parts['path'] ?? null) !== $expectedPath || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return $this->unknown('TikTok returned a public post URL that does not match this account and post.');
        }
        $saved['public_url'] = 'https://www.tiktok.com'.$expectedPath;
        $saved['public_verified_at'] = now()->toIso8601String();
        $this->persist($context, $saved);

        return PublishResult::success([$id]);
    }

    private function processing(): PublishResult
    {
        return PublishResult::failure(ErrorKind::MediaProcessing, 'TikTok is processing this submission or confirming its public post. The existing publishing task will be checked again.', retryAfter: 180);
    }

    private function unknown(string $message = 'TikTok may have accepted this submission, but its outcome is unconfirmed. Reconcile the existing publishing task before another attempt.'): PublishResult
    {
        return PublishResult::failure(ErrorKind::Unknown, $message);
    }

    /** @param array<string, mixed> $saved */
    private function persist(PublishContext $context, array $saved): void
    {
        $context->target->forceFill(['media_upload_state' => [...($context->target->media_upload_state ?? []), '_tiktok_provider' => 'accounts_api', '_tiktok_accounts' => $saved]])->save();
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        throw new RuntimeException('Delete or cancel the existing post in TikTok first. Shoutrrr cannot confirm Accounts API remote deletion.');
    }
}
