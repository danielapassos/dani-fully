<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\DeletePostTarget;
use App\Jobs\PublishPostTarget;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\DeletePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TikTokPublishingRoute;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function metricoolDeletionPost(User $user, Workspace $workspace, string $status, array $state, ?string $remoteId): Post
{
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => PostStatus::from($status)]);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'status' => PostTargetStatus::from($status),
        'media_upload_state' => ['_tiktok_provider' => 'metricool', ...$state],
        'remote_id' => $remoteId,
        'remote_ids' => $remoteId === null ? [] : [$remoteId],
    ]);

    return $post;
}

dataset('Metricool deletion guards', [
    'publishing before submission state' => ['publishing', [], null],
    'ambiguous submission' => ['awaiting_action', ['_metricool' => ['create_outcome_unknown' => true]], null],
    'accepted provider job' => ['publishing', ['_metricool' => ['post_id' => 987, 'provider_status' => 'PENDING']], null],
    'provider confirmation required' => ['awaiting_action', ['_metricool' => ['post_id' => 987]], null],
    'public video' => ['published', ['_metricool' => ['post_id' => 987, 'terminal' => true, 'terminal_outcome' => 'published']], '123456789'],
    'restricted video' => ['completed', ['_metricool' => ['post_id' => 987, 'terminal' => true, 'terminal_outcome' => 'restricted']], null],
]);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

test('web deletion cannot erase Metricool publishing records or imply remote cancellation', function (string $status, array $state, ?string $remoteId) {
    [$user, $workspace] = issuedKey();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);
    $post = metricoolDeletionPost($user, $workspace, $status, $state, $remoteId);

    $this->actingAs($user)->deleteJson(route('posts.destroy', $post))
        ->assertUnprocessable()->assertJsonPath('message', TikTokPublishingRoute::DELETION_MESSAGE);

    expect($post->fresh()->status->value)->toBe($status)
        ->and($post->fresh()->deleted_at)->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with('Metricool deletion guards');

test('MCP deletion refuses confirmed requests that cannot cancel the Metricool operation', function (string $status, array $state, ?string $remoteId) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = metricoolDeletionPost($user, $workspace, $status, $state, $remoteId);

    ShoutrrrServer::actingAs($user)->tool(DeletePostTool::class, ['post_id' => $post->id, 'confirm' => true])
        ->assertHasErrors()->assertSee(TikTokPublishingRoute::DELETION_MESSAGE);

    expect($post->fresh()->status->value)->toBe($status)
        ->and($post->fresh()->deleted_at)->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with('Metricool deletion guards');

test('REST deletion cannot bypass the Metricool operation guard', function (string $status, array $state, ?string $remoteId) {
    [$user, $workspace, $token] = issuedKey();
    $post = metricoolDeletionPost($user, $workspace, $status, $state, $remoteId);

    $this->withToken($token)->deleteJson('/api/v1/posts/'.$post->id)
        ->assertUnprocessable()->assertJsonPath('message', TikTokPublishingRoute::DELETION_MESSAGE);

    expect($post->fresh()->status->value)->toBe($status)
        ->and($post->fresh()->deleted_at)->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with('Metricool deletion guards');

test('stale deletion jobs do not fetch native tokens or claim a Metricool video was deleted', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $post = metricoolDeletionPost($user, $workspace, 'published', ['_metricool' => ['post_id' => 987]], '123456789');
    $target = $post->targets()->sole();
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    $job = new DeletePostTarget($target);

    expect(fn () => $job->handle(app(PublishConnectorRegistry::class), $tokens))->toThrow(RuntimeException::class, TikTokPublishingRoute::DELETION_MESSAGE);
    $job->failed(new RuntimeException('Queued deletion is unsupported.'));

    expect($target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($target->fresh()->error_message)->toBe(TikTokPublishingRoute::DELETION_MESSAGE);
    Http::assertNothingSent();
});

test('a queued publishing job cannot resurrect a hard deleted target', function () {
    $target = publishTarget();
    $job = new PublishPostTarget($target);
    $target->delete();
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    bindConnector(fn () => throw new RuntimeException('A deleted target cannot publish.'));

    $job->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect(PostTarget::query()->find($target->id))->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('a bridge upload that failed before submission remains deletable', function () {
    [$user, $workspace] = issuedKey();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);
    $post = metricoolDeletionPost($user, $workspace, 'failed', ['_metricool' => ['uuid' => 'staged', 'create_outcome_unknown' => false]], null);

    $this->actingAs($user)->deleteJson(route('posts.destroy', $post))->assertRedirect();

    expect($post->fresh()->status)->toBe(PostStatus::Deleted);
    Queue::assertNothingPushed();
});

test('deletion checks reload the target state inside their transaction before deciding', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $post = metricoolDeletionPost($user, $workspace, 'failed', ['_metricool' => ['uuid' => 'staged']], null);
    $post->load('targets');
    $post->targets()->update(['status' => PostTargetStatus::Publishing]);

    app(TikTokPublishingRoute::class)->withDeletionLock($post, function () use ($post): void {
        expect(app(TikTokPublishingRoute::class)->requiresProviderDeletion($post->targets->sole()))->toBeTrue();
    });
});

test('deletion winning before the worker row claim prevents submission', function () {
    $target = publishTarget();
    $job = new PublishPostTarget($target);
    $settings = Mockery::mock(InstanceSettings::class)->makePartial();
    $settings->shouldReceive('platformAvailable')->once()->andReturnUsing(function () use ($target): bool {
        $target->delete();

        return true;
    });
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    bindConnector(fn () => throw new RuntimeException('A deleted target cannot publish.'));

    $job->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class), null, $settings);

    expect(PostTarget::query()->find($target->id))->toBeNull();
    Http::assertNothingSent();
});
