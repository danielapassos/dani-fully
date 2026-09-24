<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Events\PostTargetPublished;
use App\Jobs\ReconcileTikTokInbox;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Services\Publishing\TikTokInboxReconciliation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::flush();
});

function inboxTrackingTarget(?Workspace $workspace = null, array $attributes = []): PostTarget
{
    $workspace ??= Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok, 'token_expires_at' => now()->addHour()]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'saved-inbox-token']);
    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::AwaitingAction]);

    return PostTarget::factory()->for($post)->create([
        'platform' => Platform::TikTok,
        'connected_account_id' => $account->id,
        'status' => PostTargetStatus::AwaitingAction,
        'sections' => ['Caption can be changed in TikTok'],
        'attempts' => 1,
        'media_upload_state' => ['_tiktok_provider' => 'native', 'media-id' => ['remote_ref' => 'v_inbox_file~v2.123', 'metadata' => ['publish_mode' => 'inbox', 'provider_status' => 'SEND_TO_USER_INBOX', 'upload_complete' => true]], 'publication' => ['status' => 'awaiting_action', 'message' => 'Finish in TikTok']],
        ...$attributes,
    ]);
}

function fakeInboxPublicPosts(array $ids = ['7688668628333643789']): void
{
    Http::fake([
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => $ids]]),
        'open.tiktokapis.com/v2/video/query/*' => Http::response(['error' => ['code' => 'ok'], 'data' => ['videos' => array_map(fn (string $id): array => ['id' => $id, 'share_url' => "https://www.tiktok.com/@mommygorl/video/{$id}", 'video_description' => 'Changed caption', 'create_time' => now()->subHour()->timestamp], $ids)]]),
    ]);
}

test('reconciles exact inbox operation to every owned public post without uploading or triggering cross posting', function (): void {
    Event::fake([PostTargetPublished::class]);
    $target = inboxTrackingTarget();
    fakeInboxPublicPosts(['7688668628333643790', '7688668628333643789']);
    config()->set('services.tiktok.publishing_provider', 'accounts_api');
    config()->set('services.tiktok.inbox_enabled', false);
    config()->set('services.tiktok.direct_post_enabled', true);

    app(TikTokInboxReconciliation::class)->reconcile($target);
    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->publicationStatus())->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('7688668628333643789')
        ->and($target->remote_ids)->toBe(['7688668628333643789', '7688668628333643790'])
        ->and($target->media_upload_state['media-id']['remote_ref'])->toBe('v_inbox_file~v2.123')
        ->and($target->media_upload_state['_tiktok_provider'])->toBe('native')
        ->and($target->media_upload_state['_tiktok_reconciliation']['public_posts'])->toHaveCount(2)
        ->and($target->sections)->toBe(['Caption can be changed in TikTok'])
        ->and($target->post->status)->toBe(PostStatus::Published)
        ->and($target->attempts)->toBe(1);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/status/fetch/') && $request['publish_id'] === 'v_inbox_file~v2.123' && $request->hasHeader('Authorization', 'Bearer saved-inbox-token'));
    Event::assertNotDispatched(PostTargetPublished::class);
    app(TikTokInboxReconciliation::class)->reconcile($target, manual: true);
    Http::assertSentCount(2);
});

test('private or pending moderation completion remains nonpublic and can later reconcile', function (): void {
    $target = inboxTrackingTarget();
    Http::fake(['open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []]])]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->publicationStatus())->toBe(PostTargetStatus::Completed)
        ->and($target->fresh()->remote_id)->toBeNull()
        ->and($target->fresh()->posted_at)->toBeNull();
    Http::assertSentCount(1);
    $this->travel(16)->minutes();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    fakeInboxPublicPosts();
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->publicationStatus())->toBe(PostTargetStatus::Published);
});

test('inbox delivery never claims public publication', function (): void {
    $target = inboxTrackingTarget();
    Http::fake(['*' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'SEND_TO_USER_INBOX']])]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->status)->toBe(PostTargetStatus::AwaitingAction)
        ->and($target->fresh()->remote_id)->toBeNull();
    Http::assertSentCount(1);
});

test('does not infer ownership from captions or partial query results', function (array $videos): void {
    $target = inboxTrackingTarget();
    Http::fake([
        '*/status/fetch/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7688668628333643789']]]),
        '*/video/query/*' => Http::response(['error' => ['code' => 'ok'], 'data' => ['videos' => $videos]]),
    ]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->publicationStatus())->toBe(PostTargetStatus::AwaitingAction)
        ->and($target->fresh()->remote_id)->toBeNull()
        ->and(app(TikTokInboxReconciliation::class)->view($target->fresh())['error'])->not->toBeNull();
})->with([
    'no owned videos' => [[]],
    'matching caption but wrong id' => [[['id' => '999', 'share_url' => 'https://www.tiktok.com/@mommygorl/video/999', 'video_description' => 'Caption can be changed in TikTok']]],
    'unsafe link' => [[['id' => '7688668628333643789', 'share_url' => 'https://evil.test/video/7688668628333643789']]],
]);

test('rejects nonnative or ambiguous saved transfer modes without network requests', function (array $state): void {
    $target = inboxTrackingTarget(attributes: ['media_upload_state' => $state]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    Http::assertNothingSent();
})->with([
    'direct' => [['media-id' => ['remote_ref' => 'v_pub_file~v2.123', 'metadata' => ['publish_mode' => 'direct']]]],
    'other provider' => [['_tiktok_provider' => 'metricool', 'media-id' => ['remote_ref' => 'v_inbox_file~v2.123', 'metadata' => ['publish_mode' => 'inbox']]]],
    'ambiguous legacy' => [['media-id' => ['remote_ref' => 'unknown']]],
    'two references' => [['a' => ['remote_ref' => 'v_inbox_file~v2.123'], 'b' => ['remote_ref' => 'v_inbox_file~v2.124']]],
]);

test('preserves saved upload on token query failures and does not include provider prose', function (): void {
    $target = inboxTrackingTarget();
    Http::fake(['*' => Http::response(['error' => ['code' => 'access_token_invalid', 'message' => 'sensitive echoed token']], 401)]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->media_upload_state['media-id']['remote_ref'])->toBe('v_inbox_file~v2.123')
        ->and(json_encode($target->fresh()->media_upload_state))->not->toContain('sensitive echoed token')
        ->and($target->fresh()->status)->toBe(PostTargetStatus::AwaitingAction);
});

test('does not read disabled accounts or cross workspace corrupt targets', function (bool $disabled): void {
    $target = inboxTrackingTarget();
    if ($disabled) {
        $target->account->update(['disabled_at' => now()]);
    } else {
        $target->post->update(['workspace_id' => Workspace::factory()->create()->id]);
    }
    app(TikTokInboxReconciliation::class)->reconcile($target);
    Http::assertNothingSent();
})->with([true, false]);

test('failed inbox operation remains pinned and cannot be manually republished', function (): void {
    $target = inboxTrackingTarget();
    Http::fake(['*' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'FAILED', 'fail_reason' => 'internal']])]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->fresh()->canRetryManually())->toBeFalse()
        ->and($target->fresh()->media_upload_state['media-id']['remote_ref'])->toBe('v_inbox_file~v2.123');
});

test('command dispatches a bounded set and skips noninbox targets', function (): void {
    Queue::fake();
    inboxTrackingTarget();
    inboxTrackingTarget();
    inboxTrackingTarget(attributes: ['status' => PostTargetStatus::Pending]);
    $this->artisan('tiktok:reconcile-inbox', ['--limit' => 1])->assertSuccessful();
    Queue::assertPushed(ReconcileTikTokInbox::class, 1);
    Http::assertNothingSent();
});

test('browser refresh is workspace scoped and returns current evidence', function (): void {
    [, $workspace] = ownerActingIn();
    $target = inboxTrackingTarget($workspace);
    fakeInboxPublicPosts();
    $this->postJson("/posts/{$target->post_id}/targets/{$target->id}/tiktok-inbox/refresh")
        ->assertOk()->assertJsonPath('tracking.status', 'published')->assertJsonCount(1, 'tracking.public_posts');
    $foreign = inboxTrackingTarget();
    $this->postJson("/posts/{$foreign->post_id}/targets/{$foreign->id}/tiktok-inbox/refresh")->assertNotFound();
    Http::assertSentCount(2);
});

test('API refresh requires write scope and bound workspace', function (): void {
    [, $workspace, $token] = issuedKey('read');
    $target = inboxTrackingTarget($workspace);
    $this->withToken($token)->postJson("/api/v1/posts/{$target->post_id}/targets/{$target->id}/tiktok-inbox/refresh")->assertForbidden();
    [, $otherWorkspace, $writeToken] = issuedKey();
    app('auth')->forgetGuards();
    $this->withToken($writeToken)->postJson("/api/v1/posts/{$target->post_id}/targets/{$target->id}/tiktok-inbox/refresh")->assertNotFound();
    $own = inboxTrackingTarget($otherWorkspace);
    fakeInboxPublicPosts();
    $this->withToken($writeToken)->postJson("/api/v1/posts/{$own->post_id}/targets/{$own->id}/tiktok-inbox/refresh")
        ->assertOk()->assertJsonPath('tracking.status', 'published');
});

test('a later inconclusive check preserves historic public proof without claiming it was verified again', function (): void {
    $target = inboxTrackingTarget();
    fakeInboxPublicPosts();
    $service = app(TikTokInboxReconciliation::class);
    $service->reconcile($target);
    $verifiedAt = $service->view($target->fresh())['verified_at'];
    $this->travel(16)->minutes();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []]])]);
    $service = app(TikTokInboxReconciliation::class);
    $service->reconcile($target);
    $tracking = $service->view($target->fresh());
    expect($target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($tracking['public_posts'])->toHaveCount(1)
        ->and($tracking['verified_at'])->toBe($verifiedAt)
        ->and($tracking['checked_at'])->not->toBe($verifiedAt)
        ->and($tracking['message'])->toContain('current public visibility has not been verified');
});

test('reconciliation cannot overwrite a concurrently deleted target', function (): void {
    $target = inboxTrackingTarget();
    Http::fake(['*' => function () use ($target) {
        $target->update(['status' => PostTargetStatus::Deleted]);

        return Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'SEND_TO_USER_INBOX']]);
    }]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->status)->toBe(PostTargetStatus::Deleted)
        ->and($target->fresh()->media_upload_state)->not->toHaveKey('_tiktok_reconciliation');
});

test('rejects malformed public identifiers before calling video query', function (array $ids): void {
    $target = inboxTrackingTarget();
    Http::fake(['*' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => $ids]])]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->status)->toBe(PostTargetStatus::AwaitingAction)
        ->and($target->fresh()->remote_id)->toBeNull();
    Http::assertSentCount(1);
})->with([[['not-an-id']], [[true]], [[['id' => '123']]]]);

test('reconciliation does not resurrect a parent deleted or disablement changed during the read', function (bool $deletePost): void {
    $target = inboxTrackingTarget();
    Http::fake(['*' => function () use ($target, $deletePost) {
        if ($deletePost) {
            $target->post->update(['status' => PostStatus::Deleted]);
        } else {
            $target->account->update(['disabled_at' => now()]);
        }

        return Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []]]);
    }]);
    app(TikTokInboxReconciliation::class)->reconcile($target);
    expect($target->fresh()->status)->toBe(PostTargetStatus::AwaitingAction)
        ->and($target->fresh()->media_upload_state)->not->toHaveKey('_tiktok_reconciliation');
    if ($deletePost) {
        expect($target->post()->withoutGlobalScopes()->first()->status)->toBe(PostStatus::Deleted);
    }
})->with([true, false]);
