<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\YouTubeMetricsConnector;
use Illuminate\Support\Facades\Http;

test('youtube maps video and channel statistics', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
            'items' => [['statistics' => [
                'viewCount' => '2000',
                'likeCount' => '72',
                'commentCount' => '13',
            ]]],
        ]),
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [['statistics' => [
                'subscriberCount' => '13000',
                'videoCount' => '112',
            ]]],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::YouTube,
        'remote_account_id' => 'UC-dani',
    ]);
    $target = PostTarget::factory()->create(['platform' => Platform::YouTube, 'remote_id' => 'video_42']);
    $connector = app(YouTubeMetricsConnector::class);

    $video = $connector->fetchPost($account, $target, ['access_token' => 'token']);
    $channel = $connector->fetchAccount($account, ['access_token' => 'token']);

    expect($video->isOk())->toBeTrue()
        ->and($video->likes)->toBe(72)
        ->and($video->comments)->toBe(13)
        ->and($video->reposts)->toBe(0)
        ->and($video->impressions)->toBe(2000)
        ->and($channel->isOk())->toBeTrue()
        ->and($channel->followers)->toBe(13000)
        ->and($channel->postsCount)->toBe(112);
});

test('youtube rate limits stay visible', function () {
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response([
        'error' => ['message' => 'quota'],
    ], 429)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::YouTube]);
    $target = PostTarget::factory()->create(['platform' => Platform::YouTube, 'remote_id' => 'video_42']);

    expect(app(YouTubeMetricsConnector::class)->fetchPost($account, $target, ['access_token' => 'token'])->status)
        ->toBe(MetricsStatus::RateLimited);
});
