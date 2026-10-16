<?php

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Publishing\PublicationProgress;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('reports upload stages without treating initialization or transfer as public publishing', function (array $state, ?ErrorKind $error, string $phase): void {
    $target = PostTarget::factory()->create([
        'platform' => Platform::YouTube,
        'status' => PostTargetStatus::Publishing,
        'error_kind' => $error,
        'media_upload_state' => $state,
    ]);

    $view = app(PublicationProgress::class)->view($target);

    expect($view['phase'])->toBe($phase)
        ->and($view['live_verified'])->toBeFalse()
        ->and($view['receipt'])->toBeNull();
    Http::assertNothingSent();
})->with([
    'initialized upload' => [['video' => ['remote_ref' => 'encrypted-session', 'metadata' => ['uploaded_bytes' => 0, 'total_bytes' => 5000]]], null, 'uploading'],
    'partially uploaded' => [['video' => ['metadata' => ['uploaded_bytes' => 3000, 'total_bytes' => 5000]]], ErrorKind::MediaProcessing, 'uploading'],
    'upload complete' => [['video' => ['metadata' => ['upload_complete' => true]]], null, 'uploaded'],
    'processing' => [['video' => ['metadata' => ['upload_complete' => true]]], ErrorKind::MediaProcessing, 'processing'],
    'ambiguous request' => [['video' => ['metadata' => ['outcome_unknown' => true]]], ErrorKind::Network, 'uncertain'],
    'ambiguous publishing' => [['_container' => ['metadata' => ['publish_outcome_unknown' => true]]], ErrorKind::Unknown, 'uncertain'],
]);

test('a recorded published id is not a verified live receipt', function (): void {
    $target = PostTarget::factory()->create([
        'platform' => Platform::Instagram,
        'status' => PostTargetStatus::Published,
        'remote_id' => '12345',
    ]);

    expect(app(PublicationProgress::class)->view($target))
        ->toMatchArray(['phase' => 'published_unverified', 'live_verified' => false, 'receipt' => null]);
});

test('receipts bind exact destination identity and remote id before claiming live publication', function (array $overrides, bool $expected): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'expected-account']);
    $receipt = [
        'remote_id' => '12345', 'remote_account_id' => 'expected-account',
        'url' => 'https://www.instagram.com/reel/Example/', 'visibility' => 'published',
        'verified_at' => now()->toIso8601String(), 'access_token' => 'must-not-be-returned', ...$overrides,
    ];
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram,
        'status' => PostTargetStatus::Published, 'remote_id' => '12345',
        'media_upload_state' => ['publication' => ['receipt' => $receipt]],
    ]);

    $view = app(PublicationProgress::class)->view($target);
    expect($view['live_verified'])->toBe($expected)
        ->and($view['phase'])->toBe($expected ? 'live' : 'published_unverified')
        ->and(json_encode($view))->not->toContain('must-not-be-returned');
    Http::assertNothingSent();
})->with([
    'matching receipt' => [[], true],
    'story receipt' => [['url' => 'https://www.instagram.com/stories/expected-account/12345/'], true],
    'different account' => [['remote_account_id' => 'other-account'], false],
    'different post' => [['remote_id' => '67890'], false],
    'untrusted host' => [['url' => 'https://www.instagram.com.evil.test/reel/Example/'], false],
    'embedded credentials' => [['url' => 'https://secret@www.instagram.com/reel/Example/'], false],
    'missing timestamp' => [['verified_at' => null], false],
    'future timestamp' => [['verified_at' => '2999-01-01T00:00:00Z'], false],
]);

test('TikTok live evidence remains bound to the original operation and verified account', function (string $binding, string $operation, bool $expected): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok, 'remote_account_id' => 'original-account']);
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id, 'platform' => Platform::TikTok,
        'status' => PostTargetStatus::Published, 'remote_id' => '1234567890',
        'media_upload_state' => [
            'video' => ['remote_ref' => 'v_inbox_original'],
            '_tiktok_reconciliation' => [
                'connected_account_id' => $account->id, 'remote_account_id' => $binding,
                'publish_id' => $operation, 'verified_at' => now()->toIso8601String(),
                'public_posts' => [['id' => '1234567890', 'url' => 'https://www.tiktok.com/@expected/video/1234567890']],
            ],
        ],
    ]);

    expect(app(PublicationProgress::class)->view($target)['live_verified'])->toBe($expected);
})->with([
    'original operation' => ['original-account', 'v_inbox_original', true],
    'different account' => ['previous-account', 'v_inbox_original', false],
    'different operation' => ['original-account', 'v_inbox_other', false],
]);

test('completed uploads do not deny possible visibility or claim live publication without proof', function (): void {
    $target = PostTarget::factory()->create(['status' => PostTargetStatus::Completed]);

    expect(app(PublicationProgress::class)->view($target))
        ->toMatchArray(['phase' => 'completed', 'label' => 'Completed · visibility unconfirmed', 'live_verified' => false]);
});

test('a provider action request is not mislabeled as a TikTok inbox delivery', function (): void {
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok, 'status' => PostTargetStatus::AwaitingAction,
        'media_upload_state' => ['_metricool' => ['post_id' => 'existing-task']],
    ]);

    expect(app(PublicationProgress::class)->view($target)['phase'])->toBe('action_required');
});
