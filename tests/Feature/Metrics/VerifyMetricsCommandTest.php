<?php

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Models\Workspace;
use App\Services\Metrics\Connectors\BlueskyMetricsConnector;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['metrics.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
});

test('metrics verification captures only exact workspace and published targets without publishing or leaking provider payloads', function (): void {
    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->bluesky()->create();
    $foreign = ConnectedAccount::factory()->bluesky()->create();
    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->published()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Bluesky,
    ]);
    $inbox = PostTarget::factory()->for(Post::factory()->for($workspace)->create())->create([
        'connected_account_id' => $account->id, 'status' => PostTargetStatus::AwaitingAction,
    ]);
    $this->mock(BlueskyMetricsConnector::class, function ($mock) use ($account, $target): void {
        $mock->shouldReceive('fetchAccount')->once()->withArgs(fn ($a): bool => $a->id === $account->id)
            ->andReturn(AccountMetricsResult::ok(7, raw: ['private_raw' => 'do not expose']));
        $mock->shouldReceive('fetchPost')->once()->withArgs(fn ($a, $t): bool => $a->id === $account->id && $t->id === $target->id)
            ->andReturn(PostMetricsResult::ok(3, 2, 1));
    });

    $this->artisan('metrics:verify', ['--workspace' => $workspace->id, '--posts' => 2, '--json' => true])->assertSuccessful();

    expect($account->fresh()->metrics_status)->toBe(MetricsStatus::Ok)
        ->and($target->fresh()->metrics_status)->toBe(MetricsStatus::Ok)
        ->and($foreign->fresh()->metrics_status)->toBeNull()
        ->and($inbox->fresh()->metrics_status)->toBeNull()
        ->and(AccountMetric::count())->toBe(1)->and(PostTargetMetric::count())->toBe(1)
        ->and(Context::get('workspace_id'))->toBeNull();
    Queue::assertNothingPushed();
});

test('failed verification preserves the prior sample and returns failure', function (): void {
    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->bluesky()->create();
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'followers' => 91, 'captured_at' => now()->subDay()]);
    $this->mock(BlueskyMetricsConnector::class, fn ($mock) => $mock->shouldReceive('fetchAccount')->once()->andReturn(AccountMetricsResult::failed('private provider error')));

    $this->artisan('metrics:verify', ['--workspace' => $workspace->id, '--json' => true])->assertFailed();

    expect($account->fresh()->metrics_status)->toBe(MetricsStatus::Failed)
        ->and(AccountMetric::count())->toBe(1)->and(AccountMetric::first()->followers)->toBe(91);
});

test('metrics verification rejects invalid scope and bounds before provider calls', function (array $options): void {
    $this->artisan('metrics:verify', $options)->assertFailed();
    Http::assertNothingSent();
})->with([
    [[]],
    [['--workspace' => 'not-an-id']],
    [['--workspace' => '01992b13-a056-7847-a296-e3a0d76029f0', '--posts' => 21]],
]);

test('metrics verification cannot select a foreign account and honors disabled collection', function (): void {
    $workspace = Workspace::factory()->create();
    $foreign = ConnectedAccount::factory()->bluesky()->create();
    $this->artisan('metrics:verify', ['--workspace' => $workspace->id, '--account' => $foreign->id])->assertFailed();
    config(['metrics.enabled' => false]);
    $this->artisan('metrics:verify', ['--workspace' => $workspace->id])->assertFailed();
    Http::assertNothingSent();
});
