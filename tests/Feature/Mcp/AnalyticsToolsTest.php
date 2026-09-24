<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostOrigin;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\ListAccountAnalyticsTool;
use App\Mcp\Tools\ListPostAnalyticsTool;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Metrics\StoredAnalytics;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['metrics.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    bindTokenToWorkspace($this->user, $this->workspace, ['read', 'mcp:use']);
});

test('account analytics reads only stored workspace measurements and marks missing or stale data', function (): void {
    $account = ConnectedAccount::factory()->for($this->workspace)->create([
        'metrics_status' => MetricsStatus::Failed, 'metrics_captured_at' => now(),
    ]);
    AccountMetric::factory()->create([
        'connected_account_id' => $account->id, 'captured_at' => now()->subDays(3),
        'followers' => 17, 'raw' => ['private_provider_payload' => 'must not leave storage'],
    ]);
    $missing = ConnectedAccount::factory()->for($this->workspace)->create();
    $foreign = ConnectedAccount::factory()->create(['handle' => 'foreign-account']);
    AccountMetric::factory()->create(['connected_account_id' => $foreign->id]);

    ShoutrrrServer::actingAs($this->user)->tool(ListAccountAnalyticsTool::class)
        ->assertOk()->assertSee($account->id)->assertSee($missing->id)
        ->assertSee('"followers": 17')->assertSee('"followers": null')->assertSee('"freshness": "stale"')
        ->assertDontSee($foreign->id)->assertDontSee('private_provider_payload')->assertDontSee('access_token');

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('post analytics includes synced published captions and exact identities but excludes awaiting TikTok deliveries', function (): void {
    $account = ConnectedAccount::factory()->for($this->workspace)->create(['platform' => Platform::YouTube]);
    $post = Post::factory()->for($this->workspace)->create(['origin' => PostOrigin::Sync]);
    $target = PostTarget::factory()->published()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::YouTube,
        'remote_id' => 'public-video-id', 'sections' => ['Exact caption @creator'],
        'metrics_status' => MetricsStatus::Ok, 'metrics_captured_at' => now(),
    ]);
    PostTargetMetric::factory()->create([
        'post_target_id' => $target->id, 'captured_at' => now(), 'likes' => 0, 'reposts' => 0,
    ]);
    $inbox = PostTarget::factory()->for(Post::factory()->for($this->workspace)->create())->create([
        'connected_account_id' => $account->id, 'status' => PostTargetStatus::AwaitingAction,
        'remote_id' => 'inbox-is-not-live',
    ]);
    $foreign = PostTarget::factory()->published()->create(['sections' => ['private foreign caption']]);
    PostTarget::factory()->published()->for($post)->create(['sections' => ['foreign account mismatch']]);

    ShoutrrrServer::actingAs($this->user)->tool(ListPostAnalyticsTool::class)
        ->assertOk()->assertSee($target->id)->assertSee('Exact caption @creator')->assertSee('public-video-id')
        ->assertSee('"likes": 0')->assertSee('"reposts": null')->assertSee('"origin": "sync"')
        ->assertSee('"measurement": "latest_lifetime_counters"')->assertSee('"paid_context": "unknown"')
        ->assertDontSee($inbox->id)->assertDontSee($foreign->id)->assertDontSee('foreign account mismatch');

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('analytics tools deny unbound and revoked memberships', function (string $tool): void {
    $unbound = User::factory()->create();
    ShoutrrrServer::actingAs($unbound)->tool($tool)->assertHasErrors();

    WorkspaceMembership::query()->where('user_id', $this->user->id)->where('workspace_id', $this->workspace->id)->delete();
    ShoutrrrServer::actingAs($this->user)->tool($tool)->assertHasErrors();
})->with([ListAccountAnalyticsTool::class, ListPostAnalyticsTool::class]);

test('analytics tools respect effective instance settings instead of only config', function (string $tool): void {
    app(InstanceSettings::class)->update(['metrics_enabled' => false]);
    ShoutrrrServer::actingAs($this->user)->tool($tool)->assertHasErrors()->assertSee('disabled');

    config(['metrics.enabled' => false]);
    app(InstanceSettings::class)->update(['metrics_enabled' => true]);
    ShoutrrrServer::actingAs($this->user)->tool($tool)->assertOk();
})->with([ListAccountAnalyticsTool::class, ListPostAnalyticsTool::class]);

test('analytics tools validate bounded queries and opaque cursors', function (string $tool, array $arguments): void {
    ShoutrrrServer::actingAs($this->user)->tool($tool, $arguments)->assertHasErrors();
})->with([
    [ListAccountAnalyticsTool::class, ['per_page' => 101]],
    [ListPostAnalyticsTool::class, ['days' => 366]],
    [ListPostAnalyticsTool::class, ['connected_account_id' => 'bad']],
    [ListAccountAnalyticsTool::class, ['cursor' => 'bad']],
    [ListPostAnalyticsTool::class, ['cursor' => base64_encode('{"_pointsToNextItems":true}')]],
]);

test('shared analytics service paginates and filters accounts without crossing workspaces', function (): void {
    $accounts = ConnectedAccount::factory()->count(3)->for($this->workspace)->create();
    $foreign = ConnectedAccount::factory()->create();
    Context::add('workspace_id', $this->workspace->id);
    $analytics = app(StoredAnalytics::class);
    $first = $analytics->accounts(2);
    $next = $analytics->accounts(2, $first['pagination']['next_cursor']);
    expect($first['data'])->toHaveCount(2)->and($next['data'])->toHaveCount(1)
        ->and(array_intersect(array_column($first['data'], 'id'), array_column($next['data'], 'id')))->toBeEmpty();
    expect($analytics->accounts(accountId: $accounts->first()->id)['data'])->toHaveCount(1);
    expect($analytics->accounts(accountId: $foreign->id)['data'])->toBeEmpty();
});

test('legacy private and inbox results stay excluded without breaking cursor pagination', function (): void {
    $account = ConnectedAccount::factory()->for($this->workspace)->create(['platform' => Platform::YouTube]);
    $public = PostTarget::factory()->published()->for(Post::factory()->for($this->workspace)->create())->create([
        'connected_account_id' => $account->id, 'platform' => Platform::YouTube, 'remote_id' => 'public-id',
    ]);
    PostTarget::factory()->published()->for(Post::factory()->for($this->workspace)->create())->create([
        'connected_account_id' => $account->id, 'platform' => Platform::YouTube,
        'remote_id' => 'legacy-private-id', 'media_upload_state' => ['video' => ['metadata' => ['privacy_status' => 'private']]],
    ]);
    PostTarget::factory()->published()->for(Post::factory()->for($this->workspace)->create())->create([
        'connected_account_id' => $account->id, 'platform' => Platform::TikTok,
        'remote_id' => 'inbox-id', 'media_upload_state' => ['publication' => ['status' => 'awaiting_action']],
    ]);
    Context::add('workspace_id', $this->workspace->id);
    $analytics = app(StoredAnalytics::class);
    $first = $analytics->posts(2);
    expect($first['data'])->toBeEmpty()->and($first['pagination']['next_cursor'])->not->toBeNull();
    $next = $analytics->posts(2, cursor: $first['pagination']['next_cursor']);
    expect($next['data'])->toHaveCount(1)->and($next['data'][0]['id'])->toBe($public->id);
});

test('account analytics reports only verified scope gaps and does not mistake unknown grants for reconnect requirements', function (): void {
    $known = ConnectedAccount::factory()->for($this->workspace)->create([
        'platform' => Platform::Instagram,
        'capabilities' => ['instagram_login' => true, 'oauth_scopes_verified' => true, 'oauth_scopes' => ['instagram_business_basic']],
    ]);
    $unknown = ConnectedAccount::factory()->for($this->workspace)->create([
        'platform' => Platform::Instagram, 'capabilities' => ['instagram_login' => true],
    ]);
    Context::add('workspace_id', $this->workspace->id);
    $rows = collect(app(StoredAnalytics::class)->accounts()['data'])->keyBy('id');
    expect($rows[$known->id]['permission_evidence']['status'])->toBe('missing')
        ->and($rows[$known->id]['permission_evidence']['missing_scopes'])->toBe(['instagram_business_manage_insights'])
        ->and($rows[$unknown->id]['permission_evidence']['status'])->toBe('unknown')
        ->and($rows[$unknown->id]['permission_evidence']['missing_scopes'])->toBeEmpty();
});

test('post analytics identifies all remote videos covered by aggregated TikTok metrics', function (): void {
    $account = ConnectedAccount::factory()->for($this->workspace)->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->for($this->workspace)->create();
    PostTarget::factory()->published()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::TikTok,
        'remote_id' => 'video-one', 'remote_ids' => ['video-one', 'video-two'], 'metrics_status' => MetricsStatus::Ok,
    ]);
    ShoutrrrServer::actingAs($this->user)->tool(ListPostAnalyticsTool::class)
        ->assertOk()->assertName('list_post_analytics')->assertSee('video-one')->assertSee('video-two')
        ->assertSee('combined_remote_post_ids');
});
