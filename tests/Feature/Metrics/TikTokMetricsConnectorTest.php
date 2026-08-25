<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\TikTokMetricsConnector;
use Illuminate\Support\Facades\Http;

test('tiktok maps video and creator metrics', function () {
    Http::fake([
        'https://open.tiktokapis.com/v2/video/query/*' => Http::response([
            'data' => ['videos' => [[
                'id' => 'video-42',
                'like_count' => 80,
                'comment_count' => 7,
                'share_count' => 11,
                'view_count' => 2400,
            ]]],
            'error' => ['code' => 'ok'],
        ]),
        'https://open.tiktokapis.com/v2/user/info/*' => Http::response([
            'data' => ['user' => [
                'follower_count' => 9100,
                'following_count' => 420,
                'video_count' => 86,
            ]],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok]);
    $target = PostTarget::factory()->create(['platform' => Platform::TikTok, 'remote_id' => 'video-42']);
    $connector = app(TikTokMetricsConnector::class);

    $post = $connector->fetchPost($account, $target, ['access_token' => 'token']);
    $creator = $connector->fetchAccount($account, ['access_token' => 'token']);

    expect($post->isOk())->toBeTrue()
        ->and($post->likes)->toBe(80)
        ->and($post->comments)->toBe(7)
        ->and($post->reposts)->toBe(11)
        ->and($post->impressions)->toBe(2400)
        ->and($creator->isOk())->toBeTrue()
        ->and($creator->followers)->toBe(9100)
        ->and($creator->following)->toBe(420)
        ->and($creator->postsCount)->toBe(86);
});

test('tiktok rate limits stay visible', function () {
    Http::fake(['https://open.tiktokapis.com/v2/video/query/*' => Http::response([
        'error' => ['code' => 'rate_limit_exceeded', 'message' => 'slow down'],
    ], 429)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok]);
    $target = PostTarget::factory()->create(['platform' => Platform::TikTok, 'remote_id' => 'video-42']);

    expect(app(TikTokMetricsConnector::class)->fetchPost($account, $target, ['access_token' => 'token'])->status)
        ->toBe(MetricsStatus::RateLimited);
});
