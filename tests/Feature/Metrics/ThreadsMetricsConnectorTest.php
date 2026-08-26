<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\ThreadsMetricsConnector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->connector = app(ThreadsMetricsConnector::class);
});

test('fetchPost maps insights metrics to ok', function () {
    Http::fake([
        'graph.threads.net/v1.0/*/insights*' => Http::response([
            'data' => [
                ['name' => 'views', 'period' => 'lifetime', 'values' => [['value' => 250]]],
                ['name' => 'likes', 'period' => 'lifetime', 'values' => [['value' => 9]]],
                ['name' => 'replies', 'period' => 'lifetime', 'values' => [['value' => 3]]],
                ['name' => 'reposts', 'period' => 'lifetime', 'values' => [['value' => 2]]],
                ['name' => 'quotes', 'period' => 'lifetime', 'values' => [['value' => 1]]],
                ['name' => 'shares', 'period' => 'lifetime', 'values' => [['value' => 5]]],
            ],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads]);
    $target = PostTarget::factory()->create(['platform' => Platform::Threads, 'remote_id' => '17800000000000001']);

    $r = $this->connector->fetchPost($account, $target, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->likes)->toBe(9);
    expect($r->comments)->toBe(3);
    expect($r->reposts)->toBe(2);
    expect($r->impressions)->toBe(250);
});

test('fetchPost sums every thread segment and discounts authored continuations', function () {
    Http::fake([
        'graph.threads.net/v1.0/thread-root/insights*' => Http::response([
            'data' => [
                ['name' => 'views', 'values' => [['value' => 200]]],
                ['name' => 'likes', 'values' => [['value' => 8]]],
                ['name' => 'replies', 'values' => [['value' => 4]]],
                ['name' => 'reposts', 'values' => [['value' => 2]]],
            ],
        ]),
        'graph.threads.net/v1.0/thread-reply/insights*' => Http::response([
            'data' => [
                ['name' => 'views', 'values' => [['value' => 75]]],
                ['name' => 'likes', 'values' => [['value' => 3]]],
                ['name' => 'replies', 'values' => [['value' => 2]]],
                ['name' => 'reposts', 'values' => [['value' => 1]]],
            ],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::Threads,
        'remote_id' => 'thread-root',
        'remote_ids' => ['thread-root', 'thread-reply'],
    ]);

    $result = $this->connector->fetchPost($account, $target, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->likes)->toBe(11)
        ->and($result->comments)->toBe(5)
        ->and($result->reposts)->toBe(3)
        ->and($result->impressions)->toBe(275)
        ->and($result->raw['posts'])->toHaveCount(2);
    Http::assertSentCount(2);
});

test('429 on insights maps to rate limited', function () {
    Http::fake(['graph.threads.net/v1.0/*/insights*' => Http::response([], 429)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads]);
    $target = PostTarget::factory()->create(['platform' => Platform::Threads, 'remote_id' => '17800000000000001']);

    expect($this->connector->fetchPost($account, $target, ['access_token' => 't'])->status)
        ->toBe(MetricsStatus::RateLimited);
});

test('fetchAccount maps followers_count from threads_insights total_value shape', function () {
    Http::fake([
        'graph.threads.net/v1.0/*/threads_insights*' => Http::response([
            'data' => [
                ['name' => 'followers_count', 'period' => 'lifetime', 'total_value' => ['value' => 42]],
            ],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads, 'remote_account_id' => '999']);

    $r = $this->connector->fetchAccount($account, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->followers)->toBe(42);
});

test('fetchAccount maps followers_count from threads_insights values shape', function () {
    Http::fake([
        'graph.threads.net/v1.0/*/threads_insights*' => Http::response([
            'data' => [
                ['name' => 'followers_count', 'period' => 'lifetime', 'values' => [['value' => 17]]],
            ],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads, 'remote_account_id' => '999']);

    $r = $this->connector->fetchAccount($account, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->followers)->toBe(17);
});
