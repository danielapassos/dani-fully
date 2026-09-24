<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\InstagramNativeReadConnector;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->connector = app(InstagramNativeReadConnector::class));

test('parses ig media', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['id' => 'm1', 'caption' => 'sunset', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/s.jpg', 'timestamp' => '2026-09-02T10:00:00+0000'],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'ig42']);
    $result = $this->connector->fetchRecent($account, new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null), ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts[0]->text)->toBe('sunset')
        ->and($result->posts[0]->media[0]->kind)->toBe('image');
});

test('native reads use the Instagram Login graph host for directly connected accounts', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.instagram.com/*' => Http::response(['data' => []])]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram, 'remote_account_id' => 'ig-direct',
        'capabilities' => ['instagram_login' => true],
    ]);

    $result = $this->connector->fetchRecent($account, new NativeReadCursor(now()->subDay()->toImmutable(), null), ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.instagram.com/'));
});

test('carousel covers and missing URLs stay non-importable instead of incomplete image posts', function (string $type, string $url) {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['id' => 'album', 'media_type' => $type, 'media_url' => $url, 'timestamp' => now()->toIso8601String()],
    ]])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);

    $result = $this->connector->fetchRecent($account, new NativeReadCursor(now()->subDay()->toImmutable(), null), ['access_token' => 't']);

    expect($result->posts[0]->media)->toHaveCount(1)
        ->and($result->posts[0]->media[0]->kind)->toBe('unsupported');
})->with([
    'carousel parent is only a cover' => ['CAROUSEL_ALBUM', 'https://cdn/cover.jpg'],
    'image URL unavailable' => ['IMAGE', ''],
    'video URL unavailable' => ['VIDEO', ''],
]);
