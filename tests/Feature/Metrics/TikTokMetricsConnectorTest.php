<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\TikTokMetricsConnector;
use Illuminate\Http\Client\Request;
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

test('tiktok sums metrics across every remote video id', function () {
    Http::fake(['https://open.tiktokapis.com/v2/video/query/*' => Http::response([
        'data' => ['videos' => [
            [
                'id' => 'video-42',
                'like_count' => 80,
                'comment_count' => 7,
                'share_count' => 11,
                'view_count' => 2400,
            ],
            [
                'id' => 'video-43',
                'like_count' => 20,
                'comment_count' => 3,
                'share_count' => 4,
                'view_count' => 600,
            ],
        ]],
        'error' => ['code' => 'ok'],
    ])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok,
        'remote_id' => 'video-42',
        'remote_ids' => ['video-42', 'video-43'],
    ]);

    $result = app(TikTokMetricsConnector::class)->fetchPost($account, $target, ['access_token' => 'token']);

    expect($result->isOk())->toBeTrue()
        ->and($result->likes)->toBe(100)
        ->and($result->comments)->toBe(10)
        ->and($result->reposts)->toBe(15)
        ->and($result->impressions)->toBe(3000)
        ->and($result->raw['videos'])->toHaveCount(2);

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'filters.video_ids') === ['video-42', 'video-43']);
});

test('tiktok batches video queries at the twenty id provider limit', function () {
    Http::fake(function (Request $request) {
        $videoIds = data_get($request->data(), 'filters.video_ids', []);

        return Http::response([
            'data' => ['videos' => array_map(static fn (string $id): array => [
                'id' => $id,
                'like_count' => 1,
                'comment_count' => 1,
                'share_count' => 1,
                'view_count' => 1,
            ], $videoIds)],
            'error' => ['code' => 'ok'],
        ]);
    });

    $videoIds = array_map(static fn (int $index): string => "video-{$index}", range(1, 21));
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok,
        'remote_id' => $videoIds[0],
        'remote_ids' => $videoIds,
    ]);

    $result = app(TikTokMetricsConnector::class)->fetchPost($account, $target, ['access_token' => 'token']);

    expect($result->isOk())->toBeTrue()
        ->and($result->likes)->toBe(21)
        ->and($result->comments)->toBe(21)
        ->and($result->reposts)->toBe(21)
        ->and($result->impressions)->toBe(21);

    $requests = Http::recorded();
    expect($requests)->toHaveCount(2)
        ->and(data_get($requests[0][0]->data(), 'filters.video_ids'))->toHaveCount(20)
        ->and(data_get($requests[1][0]->data(), 'filters.video_ids'))->toHaveCount(1);
});
