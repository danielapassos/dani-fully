<?php

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('API inbox submission returns the exact effective caption and truthful queued state', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('services.tiktok.publishing_provider', 'native');
    config()->set('services.tiktok.inbox_enabled', true);
    config()->set('services.tiktok.direct_post_enabled', false);
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'base_text' => 'Base caption']);
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.upload']],
    ]);
    $caption = "Approved account override\n@woodchucksato\n\n#tabi";
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'sections' => [$caption],
    ]);
    PostMedia::factory()->for($workspace)->video()->create(['post_id' => $post->id, 'duration_seconds' => 13]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/publish")
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('post.targets.0.status', 'publishing')
        ->assertJsonPath('post.targets.0.manual_completion.kind', 'tiktok_inbox')
        ->assertJsonPath('post.targets.0.manual_completion.caption', $caption)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Delivery is not yet confirmed'));
    expect($target->fresh()->remote_id)->toBeNull();
    Http::assertNothingSent();
});

test('schedules a post for a future time', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $when = now()->addDay()->toIso8601String();

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/schedule", ['scheduled_at' => $when])
        ->assertOk()
        ->assertJsonPath('post.status', 'scheduled');
});

test('rejects a past scheduled time', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/schedule", [
        'scheduled_at' => now()->subDay()->toIso8601String(),
    ])->assertStatus(422);
});

test('publishing dispatches and returns 202', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $account = ConnectedAccount::factory()->for($workspace)->create();
    PostTarget::factory()->for($post)->create(['connected_account_id' => $account->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/publish")
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');
});

test('publishing a failed post requires the guarded target retry path', function (): void {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id,
        'status' => 'failed',
    ]);
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This post has no pending targets to publish. Use Retry on an eligible failed or skipped target.');

    expect($post->fresh()->status->value)->toBe('failed')
        ->and($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Queue::assertNotPushed(PublishPostTarget::class);
});

test('a read-only key cannot publish', function () {
    [$user, $workspace, $token] = issuedKey('read');
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/publish")->assertForbidden();
});

test('queueing returns 422 when no posting-schedule slot is available', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/queue")->assertStatus(422);
});

test('retrying a failed target dispatches and returns 202', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');

    expect($target->fresh()->status)->toBe(PostTargetStatus::Pending);
});

test('retrying an unconfirmed provider outcome is rejected for manual review', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $target = PostTarget::factory()->for($post)->failed()->create([
        'error_kind' => ErrorKind::Unknown->value,
    ]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('message', 'The provider outcome is unconfirmed and may already be live. Check the connected platform before taking any further action.');

    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Unknown);
    Queue::assertNotPushed(PublishPostTarget::class);
});

test('retrying through the API rejects an account that is not publish-ready', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create(['connected_account_id' => $account->id]);
    $target->account()->firstOrFail()->forceFill(['disabled_at' => now()])->save();

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This account is disabled. Re-enable it before posting.');

    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Queue::assertNotPushed(PublishPostTarget::class);
});

test('retrying a non-failed target is rejected', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $target = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Published->value]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(422);
});
