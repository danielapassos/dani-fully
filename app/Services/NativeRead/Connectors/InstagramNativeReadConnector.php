<?php

declare(strict_types=1);

namespace App\Services\NativeRead\Connectors;

use App\Dto\NativeRead\NativeMedia;
use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\NativeRead\RecentPostsResult;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\Instagram\InstagramGraphApi;
use App\Services\NativeRead\Contracts\NativeReadConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

class InstagramNativeReadConnector implements NativeReadConnector
{
    public function __construct(private readonly HttpFactory $http) {}

    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult
    {
        try {
            $response = $this->http->timeout(10)->connectTimeout(5)->acceptJson()
                ->get(InstagramGraphApi::baseUrl($account).'/'.$account->remote_account_id.'/media', [
                    'fields' => 'id,caption,media_type,media_url,timestamp',
                    'since' => $cursor->watermark->timestamp,
                    'limit' => 50,
                    'access_token' => (string) ($credentials['access_token'] ?? ''),
                ]);
        } catch (ConnectionException $e) {
            return RecentPostsResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return $response->status() === 429
                ? RecentPostsResult::rateLimited($response->body())
                : RecentPostsResult::failed($response->body());
        }

        $posts = [];
        $newest = null;
        foreach ((array) $response->json('data', []) as $row) {
            $id = (string) ($row['id'] ?? '');
            $createdAt = Carbon::parse((string) ($row['timestamp'] ?? 'now'))->toImmutable();
            if ($id === '' || $createdAt < $cursor->watermark) {
                continue;
            }
            $media = [];
            $url = (string) ($row['media_url'] ?? '');
            $type = (string) ($row['media_type'] ?? 'IMAGE');
            // The parent carousel URL is only a cover, not the complete album.
            // Keep a reference for analytics; fan-out requires the original media.
            $kind = $url !== '' && in_array($type, ['IMAGE', 'VIDEO'], true)
                ? strtolower($type) : 'unsupported';
            $media[] = new NativeMedia($url, $kind);
            $newest ??= $id;
            $posts[] = new NativePost($id, (string) ($row['caption'] ?? ''), $createdAt, $media, false, false);
        }

        return RecentPostsResult::ok($posts, $newest);
    }
}
