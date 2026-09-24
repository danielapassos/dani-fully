<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\BlueskyNativeReadConnector;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->connector = app(BlueskyNativeReadConnector::class));

test('parses author feed, dropping replies and reposts', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => [
        ['post' => ['uri' => 'at://did/app.bsky.feed.post/1', 'record' => ['text' => 'original', 'createdAt' => '2026-09-02T10:00:00Z'], 'embed' => null]],
        ['post' => ['uri' => 'at://did/app.bsky.feed.post/2', 'record' => ['text' => 'a reply', 'createdAt' => '2026-09-02T10:05:00Z', 'reply' => ['parent' => []]]]],
        ['post' => ['uri' => 'at://did/app.bsky.feed.post/3', 'record' => ['text' => 'x', 'createdAt' => '2026-09-02T10:06:00Z']], 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost']],
    ]])]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:abc']);
    $cursor = new NativeReadCursor(Date::parse('2026-09-01')->toImmutable(), null);

    $result = $this->connector->fetchRecent($account, $cursor, []);

    expect($result->isOk())->toBeTrue()
        ->and($result->posts)->toHaveCount(1)
        ->and($result->posts[0]->remoteId)->toBe('at://did/app.bsky.feed.post/1')
        ->and($result->posts[0]->isReply)->toBeFalse();
});

test('unsupported or incomplete embeds cannot silently become text-only imports', function (array $embed) {
    Http::fake(['public.api.bsky.app/*' => Http::response(['feed' => [['post' => [
        'uri' => 'at://did/app.bsky.feed.post/4', 'record' => ['text' => 'Watch', 'createdAt' => now()->toIso8601String()], 'embed' => $embed,
    ]]]])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);

    $result = $this->connector->fetchRecent($account, new NativeReadCursor(now()->subDay()->toImmutable(), null), []);

    expect($result->posts[0]->media)->toHaveCount(1)
        ->and($result->posts[0]->media[0]->kind)->toBe('unsupported');
})->with([
    'video' => [['$type' => 'app.bsky.embed.video#view', 'playlist' => 'https://cdn/video.m3u8']],
    'thumbnail only' => [['images' => [['thumb' => 'https://cdn/thumb.jpg']]]],
]);
