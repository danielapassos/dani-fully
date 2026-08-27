<?php

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Publishing\ManualPostTargetRetry;
use App\Support\InstanceSettings;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\Bus;

function publishingMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

function publishingAccountFor(Workspace $workspace, Platform $platform = Platform::X): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform->value,
    ]);
}

test('publish-now sets the post publishing and dispatches targets', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Draft]);
    $account = publishingAccountFor($workspace);
    PostTarget::factory()->for($post)->create(['connected_account_id' => $account->id]);

    test()->postJson("/posts/{$post->id}/publish")
        ->assertOk()
        ->assertJsonPath('post.status', 'publishing');

    expect($post->refresh()->status)->toBe(PostStatus::Publishing);
    Bus::assertDispatchedTimes(PublishPostTarget::class, 1);
});

test('publish-now is blocked (422) when the post has no targets and does not dispatch', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Draft]);

    test()->postJson("/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Select at least one account to publish.');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('publish-now rejects a failed post with no runnable targets and preserves its state', function (): void {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);

    test()->postJson("/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This post has no pending targets to publish. Use Retry on an eligible failed or skipped target.');

    expect($post->refresh()->status)->toBe(PostStatus::Failed)
        ->and($target->refresh()->status)->toBe(PostTargetStatus::Failed);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('publish-now is blocked across workspaces', function () {
    publishingMember();
    $foreign = Post::factory()->create();

    test()->postJson("/posts/{$foreign->id}/publish")->assertNotFound();
});

test('per-target retry resets a failed target to pending and dispatches it', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertOk()
        ->assertJsonPath('post.id', $post->id);

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Pending)
        ->and($target->error_kind)->toBeNull()
        ->and($target->error_message)->toBeNull();

    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($target));
});

test('manual retry restores the failure when the queue cannot accept the job', function () {
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Failed,
    ]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->failed()->create([
        'connected_account_id' => $account->id,
        'error_kind' => ErrorKind::RateLimited->value,
        'error_message' => 'Try later.',
        'next_attempt_at' => now()->addMinute(),
    ]);

    $dispatcher = Mockery::mock(BusDispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(BusDispatcher::class, $dispatcher);

    expect(fn () => app(ManualPostTargetRetry::class)->dispatch($target))
        ->toThrow(RuntimeException::class, 'queue unavailable');

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::RateLimited)
        ->and($target->error_message)->toBe('Try later.')
        ->and($target->next_attempt_at)->not->toBeNull()
        ->and($post->refresh()->status)->toBe(PostStatus::Failed);
});

test('per-target retry requires manual review for an unconfirmed provider outcome', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed]);
    $target = PostTarget::factory()->for($post)->failed()->create([
        'error_kind' => ErrorKind::Unknown->value,
        'error_message' => 'Instagram may already have published this post.',
    ]);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('message', 'The provider outcome is unconfirmed and may already be live. Check the connected platform before taking any further action.');

    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Unknown)
        ->and($target->error_message)->toBe('Instagram may already have published this post.');
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('per-target retry rejects an unsubscribed workspace before claiming the target', function () {
    Bus::fake();
    config()->set('subscriptions.enabled', true);
    [$user, $workspace] = publishingMember();
    $workspace->forceFill(['is_initial' => false])->save();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Failed,
    ]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->failed()->create([
        'connected_account_id' => $account->id,
        'error_kind' => ErrorKind::BillingRequired->value,
    ]);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Subscribe to publish this post.');

    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('per-target retry rejects an exhausted X quota before claiming the target', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Failed,
    ]);
    $account = publishingAccountFor($workspace, Platform::X);
    $target = PostTarget::factory()->for($post)->failed()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'error_kind' => ErrorKind::BillingRequired->value,
    ]);

    $gate = Mockery::mock(WorkspaceSubscriptionGate::class);
    $gate->shouldReceive('canPublish')->once()->andReturnTrue();
    $gate->shouldReceive('canPublishX')->once()->andReturnFalse();
    $gate->shouldReceive('remainingXPosts')->once()->andReturn(0);
    app()->instance(WorkspaceSubscriptionGate::class, $gate);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Monthly X publishing quota exceeded. Upgrade or wait for the next billing period.');

    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('per-target retry redirects after an Inertia retry request', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);

    test()->from('/dashboard')
        ->post("/posts/{$post->id}/targets/{$target->id}/retry", [], [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'test',
        ])
        ->assertRedirect('/dashboard');

    expect($target->refresh()->status)->toBe(PostTargetStatus::Pending);
    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($target));
});

test('per-target retry resets a skipped target to pending and dispatches it', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Partial]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'status' => PostTargetStatus::Skipped->value,
        'error_message' => 'X is disabled on this instance.',
    ]);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertOk()
        ->assertJsonPath('post.id', $post->id);

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Pending)
        ->and($target->error_kind)->toBeNull()
        ->and($target->error_message)->toBeNull();

    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($target));
});

test('retrying a skipped target whose platform is still frozen is rejected before dispatch', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Partial]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'status' => PostTargetStatus::Skipped->value,
        'error_message' => 'X is disabled on this instance.',
    ]);

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('message', 'X is disabled on this installation. Enable it before posting.');

    expect($target->fresh()->status)->toBe(PostTargetStatus::Skipped);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('per-target retry reruns publish prechecks before changing state', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->failed()->create([
        'connected_account_id' => $account->id,
        'sections' => [str_repeat('x', 281)],
    ]);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('message', "A section is over X's length limit.");

    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Validation);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('a second manual retry cannot enqueue the same target again', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed]);
    $account = publishingAccountFor($workspace);
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")->assertOk();
    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only failed or skipped targets can be retried.');

    expect($target->fresh()->status)->toBe(PostTargetStatus::Pending);
    Bus::assertDispatchedTimes(PublishPostTarget::class, 1);
});

test('retry rejects a non-failed target with 409 and dispatches nothing', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Published]);
    $target = PostTarget::factory()->for($post)->published()->create();

    test()->postJson("/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(409);

    expect($target->refresh()->status)->toBe(PostTargetStatus::Published);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('retry rejects a target belonging to another post', function () {
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    $otherTarget = PostTarget::factory()->create(); // different post + workspace

    test()->postJson("/posts/{$post->id}/targets/{$otherTarget->id}/retry")->assertNotFound();
});

test('publish-now is blocked (422) for an empty post and does not dispatch', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Draft]);
    $account = publishingAccountFor($workspace);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'sections' => [''],
    ]);

    test()->postJson("/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('blocked.0.issues.0', 'empty');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('publish-now is blocked (422) for an Instagram target with a caption but no media', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Draft]);
    $account = publishingAccountFor($workspace, Platform::Instagram);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['Test'],
    ]);

    test()->postJson("/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('blocked.0.platform', 'instagram')
        ->assertJsonPath('blocked.0.issues.0', 'media_required');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('publish-now is blocked (422) when a target is over the limit and does not dispatch', function () {
    Bus::fake();
    [$user, $workspace] = publishingMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Draft]);
    $account = publishingAccountFor($workspace, Platform::Bluesky);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky->value,
        'sections' => [str_repeat('x', 400)],
        'auto_split' => false,
    ]);

    test()->postJson("/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('blocked.0.platform', 'bluesky')
        ->assertJsonPath('blocked.0.issues.0', 'section_too_long');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Bus::assertNotDispatched(PublishPostTarget::class);
});
