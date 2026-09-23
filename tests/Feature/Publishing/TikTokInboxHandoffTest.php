<?php

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\TikTokInboxHandoff;
use App\Support\PostView;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.tiktok.publishing_provider', 'native');
    config()->set('services.tiktok.inbox_enabled', true);
    config()->set('services.tiktok.direct_post_enabled', false);
    Http::preventStrayRequests();
});

test('inbox capability requires the explicit native inbox route without direct post', function (string $provider, bool $inbox, bool $direct, Platform $platform, bool $expected): void {
    config()->set('services.tiktok.publishing_provider', $provider);
    config()->set('services.tiktok.inbox_enabled', $inbox);
    config()->set('services.tiktok.direct_post_enabled', $direct);
    $account = ConnectedAccount::factory()->make(['platform' => $platform]);

    expect(app(TikTokInboxHandoff::class)->enabledFor($account))->toBe($expected);
})->with([
    'native inbox' => ['native', true, false, Platform::TikTok, true],
    'disabled inbox' => ['native', false, false, Platform::TikTok, false],
    'direct wins' => ['native', true, true, Platform::TikTok, false],
    'accounts api' => ['accounts_api', true, false, Platform::TikTok, false],
    'metricool' => ['metricool', true, false, Platform::TikTok, false],
    'other platform' => ['native', true, false, Platform::Instagram, false],
]);

test('handoff preserves the exact effective target caption before upload without claiming delivery', function (): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'base_text' => 'Base caption is not the account override']);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'sections' => ["ASMR tabi shoes unboxing\n@woodchucksato", "#tabi\n#unboxing"],
        'content_override' => ['text' => 'Unresolved editor copy'],
    ]);
    $before = $target->refresh()->getRawOriginal();

    $view = PostView::make($post)['targets'][0]['manual_completion'];

    expect($view['kind'])->toBe('tiktok_inbox')
        ->and($view['caption'])->toBe("ASMR tabi shoes unboxing\n@woodchucksato\n\n#tabi\n#unboxing")
        ->and($view['instructions'])->toStartWith('After TikTok confirms delivery,')
        ->toContain('Replace any prefilled caption', 'review mentions, cover, and privacy')
        ->and($target->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

test('saved native inbox mode remains an inbox handoff after provider configuration changes', function (): void {
    config()->set('services.tiktok.publishing_provider', 'accounts_api');
    config()->set('services.tiktok.inbox_enabled', false);
    config()->set('services.tiktok.direct_post_enabled', true);
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok,
        'status' => PostTargetStatus::AwaitingAction,
        'sections' => ['Saved caption'],
        'media_upload_state' => ['_tiktok_provider' => 'native', 'video-id' => ['remote_ref' => 'inbox-upload', 'metadata' => ['publish_mode' => 'inbox']]],
    ]);

    $handoff = app(TikTokInboxHandoff::class)->forTarget($target);

    expect($handoff['caption'])->toBe('Saved caption')
        ->and($handoff['instructions'])->toStartWith('Open the upload notification in your TikTok inbox')
        ->toContain('not an item in TikTok Drafts');
});

test('legacy delivered inbox evidence gets a handoff without rewriting saved state', function (array $attributes): void {
    config()->set('services.tiktok.publishing_provider', 'accounts_api');
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok,
        'status' => PostTargetStatus::Publishing,
        'sections' => ['Legacy caption'],
        ...$attributes,
    ]);
    $before = $target->refresh()->getRawOriginal();

    expect(app(TikTokInboxHandoff::class)->forTarget($target)['caption'])->toBe('Legacy caption')
        ->and($target->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with([
    'saved terminal status' => [['status' => PostTargetStatus::AwaitingAction]],
    'provider receipt' => [['media_upload_state' => ['video-id' => ['remote_ref' => 'old-upload', 'metadata' => ['provider_status' => 'SEND_TO_USER_INBOX']]]]],
    'historical processing response' => [[
        'media_upload_state' => ['video-id' => ['remote_ref' => 'old-upload']],
        'error_kind' => ErrorKind::MediaProcessing,
        'error_message' => 'Video sent to TikTok. Open TikTok to finish the native post.',
    ]],
]);

test('saved direct or separate provider evidence never becomes inbox instructions', function (array $state): void {
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok,
        'status' => PostTargetStatus::AwaitingAction,
        'media_upload_state' => $state,
    ]);

    expect(app(TikTokInboxHandoff::class)->forTarget($target))->toBeNull();
})->with([
    'accounts pin' => [['_tiktok_provider' => 'accounts_api']],
    'metricool pin' => [['_tiktok_provider' => 'metricool']],
    'unknown pin' => [['_tiktok_provider' => 'unknown']],
    'null pin' => [['_tiktok_provider' => null]],
    'empty pin' => [['_tiktok_provider' => '']],
    'accounts state' => [['_tiktok_accounts' => []]],
    'metricool state' => [['_metricool' => []]],
    'native direct' => [['video-id' => ['metadata' => ['publish_mode' => 'direct']]]],
    'conflicting modes' => [['video-a' => ['metadata' => ['publish_mode' => 'inbox']], 'video-b' => ['metadata' => ['publish_mode' => 'direct']]]],
]);

test('ambiguous attempted targets do not inherit the current inbox mode', function (array $attributes): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok]);
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        ...$attributes,
    ]);

    expect(app(TikTokInboxHandoff::class)->forTarget($target))->toBeNull();
})->with([
    'attempted' => [['attempts' => 1]],
    'reference' => [['remote_id' => 'remote-video']],
    'references' => [['remote_ids' => ['remote-video']]],
    'upload started' => [['media_upload_state' => ['video-id' => ['remote_ref' => 'unknown-upload']]]],
    'failed' => [['status' => PostTargetStatus::Failed]],
    'completed' => [['status' => PostTargetStatus::Completed]],
    'published' => [['status' => PostTargetStatus::Published]],
    'deleted' => [['status' => PostTargetStatus::Deleted]],
]);

test('non TikTok awaiting action never produces a TikTok handoff', function (): void {
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'status' => PostTargetStatus::AwaitingAction]);

    expect(app(TikTokInboxHandoff::class)->forTarget($target))->toBeNull();
});

test('submission wording distinguishes inbox only mixed and other selected destinations', function (bool $inbox, bool $other, string $fragment): void {
    $post = Post::factory()->create();
    if ($inbox) {
        $account = ConnectedAccount::factory()->create(['workspace_id' => $post->workspace_id, 'platform' => Platform::TikTok]);
        PostTarget::factory()->for($post)->create(['connected_account_id' => $account->id, 'platform' => Platform::TikTok]);
    }
    if ($other) {
        PostTarget::factory()->for($post)->create(['platform' => Platform::X]);
    }

    expect(app(TikTokInboxHandoff::class)->submissionMessage($post))->toContain($fragment)
        ->and(app(TikTokInboxHandoff::class)->confirmation($post))->not->toContain('This will publicly publish');
})->with([
    'inbox' => [true, false, 'TikTok inbox upload queued. Delivery is not yet confirmed'],
    'mixed' => [true, true, 'TikTok inbox targets require manual completion'],
    'other' => [false, true, 'Publishing started.'],
]);
