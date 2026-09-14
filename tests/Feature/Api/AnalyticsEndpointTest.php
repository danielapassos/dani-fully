<?php

use App\Enums\MetricsStatus;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['metrics.enabled' => true]);
    Http::preventStrayRequests();
});

test('analytics requires authentication and respects the instance toggle', function (string $path): void {
    $this->getJson($path)->assertUnauthorized();
    [, , $token] = issuedKey('read');
    config(['metrics.enabled' => false]);
    $this->withToken($token)->getJson($path)->assertNotFound();
})->with(['/api/v1/analytics/accounts', '/api/v1/analytics/posts']);

test('account analytics exposes only the latest workspace measurements without secrets or invented zeros', function (): void {
    [, $workspace, $token] = issuedKey('read');
    $account = ConnectedAccount::factory()->for($workspace)->create(['metrics_status' => MetricsStatus::Ok]);
    $missing = ConnectedAccount::factory()->for($workspace)->create();
    $other = ConnectedAccount::factory()->create();
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => now()->subDay(), 'followers' => 18]);
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'followers' => 0, 'following' => null, 'raw' => ['secret' => 'private-provider-data']]);
    AccountMetric::factory()->create(['connected_account_id' => $other->id]);
    Queue::fake();

    $response = $this->withToken($token)->getJson('/api/v1/analytics/accounts')->assertOk();
    $rows = collect($response->json('data'))->keyBy('id');
    expect($rows)->toHaveCount(2);
    expect($rows[$account->id]['followers'])->toBe(0);
    expect($rows[$account->id]['following'])->toBeNull();
    expect($rows[$missing->id]['followers'])->toBeNull();
    expect($rows[$missing->id]['captured_at'])->toBeNull();
    $response->assertDontSee('private-provider-data')->assertDontSee('access_token');
    Queue::assertNothingPushed();
    $this->withToken($token)->postJson('/api/v1/posts', [])->assertForbidden();
});

test('post analytics isolates both workspace relations and preserves measurement state', function (): void {
    [, $workspace, $token] = issuedKey('read');
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $post = Post::factory()->for($workspace)->create();
    $measured = PostTarget::factory()->published()->create([
        'post_id' => $post->id, 'connected_account_id' => $account->id,
        'metrics_status' => MetricsStatus::Failed, 'metrics_captured_at' => now(), 'likes' => 7,
    ]);
    $snapshot = PostTargetMetric::factory()->create(['post_target_id' => $measured->id, 'captured_at' => now()->subDays(3), 'likes' => 7]);
    $missing = PostTarget::factory()->published()->create(['post_id' => Post::factory()->for($workspace)->create()->id, 'connected_account_id' => $account->id, 'metrics_status' => MetricsStatus::Failed, 'metrics_captured_at' => now()]);
    PostTarget::factory()->published()->create(['connected_account_id' => $account->id]);
    PostTarget::factory()->published()->create(['post_id' => $post->id]);
    PostTarget::factory()->create(['post_id' => Post::factory()->for($workspace)->create()->id, 'connected_account_id' => $account->id]);
    PostTarget::factory()->published()->create(['post_id' => Post::factory()->for($workspace)->create()->id, 'connected_account_id' => $account->id, 'posted_at' => now()->subDays(100)]);
    Queue::fake();

    $response = $this->withToken($token)->getJson('/api/v1/analytics/posts')->assertOk();
    $rows = collect($response->json('data'))->keyBy('id');
    expect($rows)->toHaveCount(2);
    expect($rows[$measured->id]['likes'])->toBe(7);
    expect($rows[$measured->id]['metrics_status'])->toBe('failed');
    expect($rows[$measured->id]['captured_at'])->toBe($snapshot->captured_at->toIso8601String());
    expect($rows[$measured->id]['last_attempt_at'])->not->toBe($rows[$measured->id]['captured_at']);
    expect($rows[$missing->id]['likes'])->toBeNull();
    expect($rows[$missing->id]['captured_at'])->toBeNull();
    expect($rows[$missing->id]['paid_context'])->toBe('unknown');
    $response->assertJsonPath('meta.measurement', 'latest_lifetime_counters');
    Queue::assertNothingPushed();
});

test('analytics pages are bounded and cursor pagination does not repeat accounts', function (): void {
    [, $workspace, $token] = issuedKey('read');
    ConnectedAccount::factory()->count(3)->for($workspace)->create();
    $first = $this->withToken($token)->getJson('/api/v1/analytics/accounts?per_page=2')->assertOk();
    $next = $this->withToken($token)->getJson('/api/v1/analytics/accounts?per_page=2&cursor='.urlencode($first->json('pagination.next_cursor')))->assertOk();
    expect(array_intersect(array_column($first->json('data'), 'id'), array_column($next->json('data'), 'id')))->toBeEmpty();
    $next->assertJsonCount(1, 'data')->assertJsonPath('pagination.has_more', false);
    $this->withToken($token)->getJson('/api/v1/analytics/accounts?per_page=101')->assertUnprocessable();
    $this->withToken($token)->getJson('/api/v1/analytics/posts?days=366')->assertUnprocessable();
    $this->withToken($token)->getJson('/api/v1/analytics/posts?connected_account_id=bad')->assertUnprocessable();
});
