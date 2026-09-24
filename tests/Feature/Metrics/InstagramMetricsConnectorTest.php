<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Services\Metrics\Connectors\InstagramMetricsConnector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->connector = app(InstagramMetricsConnector::class);
});

test('fetchPost maps insights metrics to ok', function () {
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response([
            'data' => [
                ['name' => 'likes', 'period' => 'lifetime', 'values' => [['value' => 9]]],
                ['name' => 'comments', 'period' => 'lifetime', 'values' => [['value' => 3]]],
                ['name' => 'saved', 'period' => 'lifetime', 'values' => [['value' => 1]]],
                ['name' => 'shares', 'period' => 'lifetime', 'values' => [['value' => 2]]],
                ['name' => 'reach', 'period' => 'lifetime', 'values' => [['value' => 300]]],
                ['name' => 'views', 'period' => 'lifetime', 'values' => [['value' => 250]]],
            ],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => '17800000000000000']);

    $r = $this->connector->fetchPost($account, $target, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->likes)->toBe(9);
    expect($r->comments)->toBe(3);
    expect($r->reposts)->toBe(2);
    expect($r->impressions)->toBe(250);
});

test('429 on insights maps to rate limited', function () {
    Http::fake(['graph.facebook.com/*/insights*' => Http::response([], 429)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => '17800000000000000']);

    expect($this->connector->fetchPost($account, $target, ['access_token' => 't'])->status)
        ->toBe(MetricsStatus::RateLimited);
});

test('fetchAccount maps followers_count and media_count', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123', 'followers_count' => 42, 'media_count' => 7])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => '123']);

    $r = $this->connector->fetchAccount($account, ['access_token' => 't']);

    expect($r->isOk())->toBeTrue();
    expect($r->followers)->toBe(42);
    expect($r->postsCount)->toBe(7);
});

test('direct instagram login fetches account metrics from graph instagram', function () {
    Http::fake([
        'graph.instagram.com/*' => Http::response(['id' => '123', 'followers_count' => 42, 'media_count' => 7]),
    ]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram,
        'remote_account_id' => '123',
        'capabilities' => ['instagram_login' => true],
    ]);

    expect($this->connector->fetchAccount($account, ['access_token' => 't'])->isOk())->toBeTrue();
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://graph.instagram.com/'));
});

test('instagram insight errors remain failures instead of zero engagement', function (int $status): void {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*/insights*' => Http::response(['error' => ['message' => 'Insights unavailable']], $status)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => '17800000000000000']);

    $result = $this->connector->fetchPost($account, $target, ['access_token' => 't']);
    expect($result->status)->toBe(MetricsStatus::Failed)->and($result->message)->toBe('Insights unavailable');
})->with([400, 401, 403, 500]);

test('instagram missing insight fields do not create a successful zero snapshot', function (): void {
    Http::fake(['graph.facebook.com/*/insights*' => Http::response(['data' => []])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => '17800000000000000']);

    expect($this->connector->fetchPost($account, $target, ['access_token' => 't'])->status)->toBe(MetricsStatus::Failed);
});

test('instagram total value metrics retain real zero measurements', function (): void {
    Http::fake(['graph.facebook.com/*/insights*' => Http::response(['data' => [
        ['name' => 'likes', 'total_value' => ['value' => 0]],
        ['name' => 'comments', 'total_value' => ['value' => 0]],
        ['name' => 'shares', 'total_value' => ['value' => 0]],
    ]])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'remote_id' => '17800000000000000']);

    $result = $this->connector->fetchPost($account, $target, ['access_token' => 't']);
    expect($result->status)->toBe(MetricsStatus::Ok)->and($result->likes)->toBe(0)->and($result->impressions)->toBeNull();
});

test('instagram missing account counters remain unavailable', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123'])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    expect($this->connector->fetchAccount($account, ['access_token' => 't'])->status)->toBe(MetricsStatus::Failed);
});

test('an instagram 400 capture preserves the last successful measurement', function (): void {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*/insights*' => Http::response(['error' => ['message' => 'Insights unavailable']], 400)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'token_expires_at' => null]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'token']);
    $target = PostTarget::factory()->published()->create([
        'platform' => Platform::Instagram, 'connected_account_id' => $account->id,
        'remote_id' => '17800000000000000', 'likes' => 42, 'metrics_status' => MetricsStatus::Ok,
    ]);
    PostTargetMetric::factory()->create([
        'post_target_id' => $target->id, 'captured_at' => now()->subDay(), 'likes' => 42,
    ]);

    CapturePostTargetMetrics::dispatchSync($target);

    expect($target->fresh()->metrics_status)->toBe(MetricsStatus::Failed)
        ->and($target->fresh()->likes)->toBe(42)
        ->and($target->metrics()->count())->toBe(1);
});
