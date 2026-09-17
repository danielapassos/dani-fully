<?php

declare(strict_types=1);

namespace App\Services\Publishing\Metricool;

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Exceptions\TikTokCreatorInfoException;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Services\ConnectedAccounts\TikTok\TikTokPostOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

class MetricoolClient
{
    private const string BASE_URL = 'https://app.metricool.com/api';

    private const int PART_SIZE = 8 * 1024 * 1024;

    public function __construct(private readonly HttpFactory $http) {}

    /** @return array{user_id: string, blog_id: string, expected_handle: string, creator: array<string, mixed>} */
    public function binding(ConnectedAccount $account): array
    {
        $userId = (string) config('services.metricool.user_id');
        $blogId = config('services.metricool.accounts.'.$account->id);
        $handle = strtolower(ltrim($account->handle, '@'));
        if ($account->platform !== Platform::TikTok || $account->disabled_at !== null
            || $account->workspace_id !== config('services.metricool.workspace_id')
            || ! is_scalar($blogId) || preg_match('/\A[1-9][0-9]*\z/', (string) $blogId) !== 1
            || preg_match('/\A[1-9][0-9]*\z/', $userId) !== 1
            || preg_match('/\A[a-z0-9._]{1,24}\z/', $handle) !== 1
            || ! is_string(config('services.metricool.token')) || config('services.metricool.token') === '') {
            throw new TikTokCreatorInfoException('Configure this workspace and TikTok account in the Metricool publishing connection.', ErrorKind::Unsupported);
        }

        $binding = ['user_id' => $userId, 'blog_id' => (string) $blogId, 'expected_handle' => $handle];
        $profiles = $this->request($binding, 'GET', '/admin/simpleProfiles');
        $matches = array_values(array_filter($profiles, static fn (mixed $profile): bool => is_array($profile)
            && (string) ($profile['id'] ?? '') === $binding['blog_id']));
        if (count($matches) !== 1 || ! is_string($matches[0]['tiktok'] ?? null)
            || strtolower(ltrim($matches[0]['tiktok'], '@')) !== $handle) {
            throw new TikTokCreatorInfoException('The Metricool brand does not match this TikTok account. Correct the account mapping before publishing.', ErrorKind::Unsupported);
        }

        $creator = $this->normalizeCreator($this->request($binding, 'GET', '/v2/scheduler/catalogs/tiktok/creator-info'));
        if (strtolower(ltrim($creator['creator_username'], '@')) !== $handle) {
            throw new TikTokCreatorInfoException('Metricool returned a different TikTok creator. Correct the account connection before publishing.', ErrorKind::Unsupported);
        }

        return [...$binding, 'creator' => $creator];
    }

    /** @return array{creator_username: string, creator_nickname: string, creator_avatar_url: ?string, privacy_level_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_video_post_duration_sec: int} */
    public function creatorInfo(ConnectedAccount $account): array
    {
        /** @var array{creator_username: string, creator_nickname: string, creator_avatar_url: ?string, privacy_level_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_video_post_duration_sec: int} */
        return $this->binding($account)['creator'];
    }

    /** @param array<string, mixed> $data
     * @return array{creator_username: string, creator_nickname: string, creator_avatar_url: ?string, privacy_level_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_video_post_duration_sec: int}
     */
    private function normalizeCreator(array $data): array
    {
        if (! is_string($data['creatorUsername'] ?? null) || $data['creatorUsername'] === ''
            || ! is_string($data['creatorNickname'] ?? null)
            || ! is_array($data['privacyLevelOptions'] ?? null) || $data['privacyLevelOptions'] === []
            || ! is_int($data['maxVideoPostDurationSec'] ?? null) || $data['maxVideoPostDurationSec'] < 1) {
            throw new TikTokCreatorInfoException('Metricool returned incomplete TikTok creator settings. Refresh before publishing.');
        }
        foreach (['commentDisabled', 'duetDisabled', 'stitchDisabled'] as $key) {
            if (! is_bool($data[$key] ?? null)) {
                throw new TikTokCreatorInfoException('Metricool returned incomplete TikTok interaction settings. Refresh before publishing.');
            }
        }
        foreach ($data['privacyLevelOptions'] as $privacy) {
            if (! is_string($privacy) || ! in_array($privacy, TikTokPostOptions::PRIVACY_LEVELS, true)) {
                throw new TikTokCreatorInfoException('Metricool returned an unsupported TikTok privacy choice.');
            }
        }
        $avatar = $data['creatorAvatarUrl'] ?? null;

        return [
            'creator_username' => $data['creatorUsername'],
            'creator_nickname' => $data['creatorNickname'],
            'creator_avatar_url' => is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
            'privacy_level_options' => array_values(array_unique($data['privacyLevelOptions'])),
            'comment_disabled' => $data['commentDisabled'],
            'duet_disabled' => $data['duetDisabled'],
            'stitch_disabled' => $data['stitchDisabled'],
            'max_video_post_duration_sec' => $data['maxVideoPostDurationSec'],
        ];
    }

    /** @param array<string, mixed> $binding
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $payload
     * @return array<mixed>
     */
    public function request(array $binding, string $method, string $path, array $query = [], ?array $payload = null): array
    {
        $token = config('services.metricool.token');
        if (! is_string($token) || $token === '') {
            throw new TikTokCreatorInfoException('The Metricool publishing credential is unavailable. Check the workspace publishing connection.', ErrorKind::Unsupported);
        }
        $url = self::BASE_URL.$path.'?'.http_build_query([
            'userId' => $binding['user_id'], 'blogId' => $binding['blog_id'], ...$query,
        ]);
        try {
            $response = $this->http->timeout(45)->connectTimeout(10)->withoutRedirecting()
                ->withHeaders(['X-Mc-Auth' => $token])->acceptJson()
                ->send($method, $url, $payload === null ? [] : ['json' => $payload]);
        } catch (ConnectionException) {
            throw new TikTokCreatorInfoException('The Metricool connection was interrupted. The saved publishing operation will be checked before another submission.', ErrorKind::Network);
        }
        if (! $response->successful()) {
            $kind = match (true) {
                in_array($response->status(), [401, 403], true) => ErrorKind::Unsupported,
                $response->status() === 402 => ErrorKind::BillingRequired,
                $response->status() === 429 => ErrorKind::RateLimited,
                $response->serverError() => ErrorKind::ServerError,
                default => ErrorKind::Validation,
            };
            throw new TikTokCreatorInfoException(match ($kind) {
                ErrorKind::Unsupported => 'Metricool denied this connection. Check its API access and brand permissions; reconnecting Shoutrrr’s native TikTok token will not fix it.',
                ErrorKind::BillingRequired => 'Metricool API publishing requires an eligible subscription.',
                ErrorKind::RateLimited => 'Metricool is limiting requests. Wait before retrying.',
                default => 'Metricool could not confirm the publishing operation. Check its saved status before retrying.',
            }, $kind, $response->status());
        }
        $data = $response->json();
        $data = is_array($data) && array_key_exists('data', $data) ? $data['data'] : $data;
        if (! is_array($data)) {
            throw new TikTokCreatorInfoException('Metricool returned an incomplete response. Check the saved operation before retrying.', ErrorKind::Unknown);
        }

        return $data;
    }

    /** @param array<string, mixed> $binding */
    public function uploadVideo(array $binding, PostMedia $media): string
    {
        if ($media->size_bytes < 1 || $media->size_bytes > 500 * 1024 * 1024 || $media->mime !== 'video/mp4') {
            throw new TikTokCreatorInfoException('Metricool TikTok publishing requires an MP4 video no larger than 500 MB.');
        }
        $parts = [];
        $offset = 0;
        $stream = $this->readStream($media);
        try {
            while (! feof($stream)) {
                $bytes = stream_get_contents($stream, self::PART_SIZE);
                if ($bytes === false) {
                    throw new TikTokCreatorInfoException('The video could not be read from private storage.');
                }
                if ($bytes === '') {
                    break;
                }
                $size = strlen($bytes);
                $parts[] = ['size' => $size, 'startByte' => $offset, 'endByte' => $offset + $size, 'hash' => base64_encode(hash('sha256', $bytes, true))];
                $offset += $size;
                if ($offset > $media->size_bytes) {
                    throw new TikTokCreatorInfoException('The stored video size changed. Review the attachment before publishing.');
                }
            }
        } finally {
            fclose($stream);
        }
        if ($offset !== $media->size_bytes || $parts === []) {
            throw new TikTokCreatorInfoException('The stored video size does not match the approved attachment.');
        }

        $transaction = $this->request($binding, 'PUT', '/v2/media/s3/upload-transactions', payload: [
            'resourceType' => 'planner', 'contentType' => $media->mime, 'fileExtension' => 'mp4', 'parts' => $parts,
        ]);
        $uploadType = $transaction['uploadType'] ?? null;
        $remoteParts = $transaction['parts'] ?? null;
        if ($uploadType === 'SIMPLE' && count($parts) === 1) {
            $remoteParts = [['partNumber' => 1, 'presignedUrl' => $transaction['presignedUrl'] ?? null]];
        } elseif ($uploadType !== 'MULTIPART' || ! is_string($transaction['uploadId'] ?? null)
            || ! is_string($transaction['key'] ?? null)) {
            throw new TikTokCreatorInfoException('Metricool returned an invalid media upload session.');
        }
        if (! is_array($remoteParts) || count($remoteParts) !== count($parts)) {
            throw new TikTokCreatorInfoException('Metricool returned an incomplete media upload session.');
        }

        $stream = $this->readStream($media);
        $completedParts = [];
        try {
            foreach (array_values($remoteParts) as $index => $remote) {
                if (! is_array($remote) || ($remote['partNumber'] ?? null) !== $index + 1) {
                    throw new TikTokCreatorInfoException('Metricool returned unexpected video part ordering.');
                }
                $url = $this->s3Url($remote['presignedUrl'] ?? null);
                $bytes = stream_get_contents($stream, $parts[$index]['size']);
                if ($bytes === false || strlen($bytes) !== $parts[$index]['size']
                    || base64_encode(hash('sha256', $bytes, true)) !== $parts[$index]['hash']) {
                    throw new TikTokCreatorInfoException('The video changed during upload. Review the attachment before publishing.');
                }
                try {
                    $response = $this->http->timeout(120)->connectTimeout(10)->withoutRedirecting()
                        ->withHeaders(['x-amz-checksum-sha256' => $parts[$index]['hash']])
                        ->withBody($bytes, $media->mime)->put($url);
                } catch (ConnectionException) {
                    throw new TikTokCreatorInfoException('The video transfer to Metricool was interrupted. No TikTok post has been submitted.', ErrorKind::Network);
                }
                $etag = $response->header('ETag');
                if (! $response->successful() || $etag === '') {
                    throw new TikTokCreatorInfoException('Metricool could not confirm the uploaded video part. No TikTok post has been submitted.', ErrorKind::Network);
                }
                $completedParts[] = ['partNumber' => $index + 1, 'etag' => $etag];
            }
        } finally {
            fclose($stream);
        }

        $payload = $uploadType === 'SIMPLE'
            ? ['simple' => ['fileUrl' => $this->s3Url($transaction['fileUrl'] ?? null)]]
            : ['multipart' => ['uploadId' => $transaction['uploadId'], 'key' => $transaction['key'], 'parts' => $completedParts]];
        $complete = $this->request($binding, 'PATCH', '/v2/media/s3/upload-transactions', payload: $payload);

        return $this->s3Url($complete['convertedFileUrl'] ?? $complete['fileUrl'] ?? null);
    }

    /** @return resource */
    private function readStream(PostMedia $media)
    {
        try {
            $stream = Storage::disk($media->disk)->readStream($media->path);
        } catch (FilesystemException) {
            throw new TikTokCreatorInfoException('Private video storage is unavailable. No new post was submitted.', ErrorKind::Network);
        }
        if (! is_resource($stream)) {
            throw new TikTokCreatorInfoException('The video could not be opened from private storage.');
        }

        return $stream;
    }

    private function s3Url(mixed $url): string
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || preg_match('/\Ametricool-[a-z0-9-]+\.s3(?:\.[a-z0-9-]+)?\.amazonaws\.com\z/', $parts['host'] ?? '') !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new TikTokCreatorInfoException('Metricool returned an untrusted media upload destination.');
        }

        return $url;
    }
}
