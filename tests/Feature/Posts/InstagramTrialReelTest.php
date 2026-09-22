<?php

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\InstagramTrialReel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;

function trialReelMember(): array
{
    [$user, $workspace, $token] = issuedKey();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$user, $workspace, $token];
}

function trialReelTarget(array $attributes = []): PostTarget
{
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $post->workspace_id, 'platform' => Platform::Instagram]);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram,
        'format' => PostFormat::Reels, 'sections' => ['Trial caption'],
        'content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'MANUAL']]],
        ...$attributes,
    ]);
}

test('web and API trial drafts preserve trial and cover independently during partial updates', function (string $surface, string $strategy) {
    [$user, $workspace, $token] = trialReelMember();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $cover = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null, 'kind' => 'image', 'mime' => 'image/jpeg', 'size_bytes' => 100]);
    $trial = ['graduation_strategy' => $strategy];
    $payload = [
        'segments' => ['Trial caption'], 'base_text' => 'Trial caption',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'format' => 'reels', 'content_override' => ['instagram' => ['cover_media_id' => $cover->id, 'trial_params' => $trial]]]],
    ];
    $response = $surface === 'web'
        ? $this->actingAs($user)->postJson(route('posts.store'), $payload)
        : $this->withToken($token)->postJson('/api/v1/posts', $payload);
    $response->assertCreated()->assertJsonPath('post.targets.0.content_override.instagram.trial_params', $trial)
        ->assertJsonPath('post.targets.0.format', 'reels');
    $postId = $response->json('post.id');

    foreach ([['cover_media_id' => null], ['cover_media_id' => $cover->id], ['trial_params' => $trial]] as $settings) {
        $payload['targets'][0]['content_override'] = ['instagram' => $settings];
        $response = $surface === 'web'
            ? $this->putJson(route('posts.update', $postId), $payload)
            : $this->patchJson('/api/v1/posts/'.$postId, $payload);
        $response->assertOk()->assertJsonPath('post.targets.0.content_override.instagram.trial_params', $trial)
            ->assertJsonPath('post.targets.0.content_override.instagram.cover_media_id', $settings['cover_media_id'] ?? (array_key_exists('cover_media_id', $settings) ? null : $cover->id));
    }
    $payload['targets'][0]['content_override'] = ['segments' => ['Edited caption']];
    $response = $surface === 'web'
        ? $this->putJson(route('posts.update', $postId), $payload)
        : $this->patchJson('/api/v1/posts/'.$postId, $payload);
    $response->assertOk()->assertJsonPath('post.targets.0.content_override.instagram.trial_params', $trial);

    $payload['targets'][0]['content_override'] = ['instagram' => ['trial_params' => null]];
    $response = $surface === 'web'
        ? $this->putJson(route('posts.update', $postId), $payload)
        : $this->patchJson('/api/v1/posts/'.$postId, $payload);
    $response->assertOk()->assertJsonPath('post.targets.0.content_override.instagram.trial_params', null)
        ->assertJsonPath('post.targets.0.content_override.instagram.cover_media_id', $cover->id);
    expect($cover->fresh()->post_id)->toBeNull();
})->with(['web', 'api'])->with(['MANUAL', 'SS_PERFORMANCE']);

test('MCP creates updates and explicitly clears trial settings without losing a cover', function () {
    [$user, $workspace] = trialReelMember();
    bindTokenToWorkspace($user, $workspace);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $cover = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null, 'kind' => 'image', 'mime' => 'image/jpeg', 'size_bytes' => 100]);
    $payload = [
        'base_text' => 'Trial via MCP', 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'format' => 'reels', 'content_override' => ['instagram' => ['cover_media_id' => $cover->id, 'trial_params' => ['graduation_strategy' => 'MANUAL']]]]],
    ];
    ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, $payload)->assertOk();
    $post = Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->sole();
    expect($post->targets->sole()->format)->toBe(PostFormat::Reels)
        ->and($post->targets->sole()->content_override['instagram']['trial_params'])->toBe(['graduation_strategy' => 'MANUAL']);
    $payload['post_id'] = $post->id;
    $payload['targets'][0]['content_override'] = ['instagram' => ['trial_params' => ['graduation_strategy' => 'SS_PERFORMANCE']]];
    ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, $payload)->assertOk();
    expect($post->targets()->sole()->content_override['instagram'])->toBe(['cover_media_id' => $cover->id, 'trial_params' => ['graduation_strategy' => 'SS_PERFORMANCE']]);
    $payload['targets'][0]['content_override'] = ['instagram' => ['trial_params' => null]];
    ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, $payload)->assertOk();
    expect($post->targets()->sole()->content_override['instagram'])->toBe(['cover_media_id' => $cover->id, 'trial_params' => null]);
});

test('trial draft validation allows only explicit supported graduation choices', function (mixed $params, bool $valid) {
    $payload = ['targets' => [['content_override' => ['instagram' => ['trial_params' => $params]]]]];
    expect(Validator::make($payload, InstagramTrialReel::draftRules())->passes())->toBe($valid);
})->with([
    'manual' => [['graduation_strategy' => 'MANUAL'], true],
    'performance' => [['graduation_strategy' => 'SS_PERFORMANCE'], true],
    'remove' => [null, true],
    'empty' => [[], false],
    'unknown' => [['graduation_strategy' => 'AUTO'], false],
    'null choice' => [['graduation_strategy' => null], false],
    'boolean' => [true, false],
    'unknown nested field' => [['graduation_strategy' => 'MANUAL', 'share_to_feed' => true], false],
]);

test('trial settings cannot be silently applied to another platform or Stories', function (Platform $platform, string $format) {
    [$user, $workspace] = trialReelMember();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => $platform]);
    $this->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['Trial caption'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'format' => $format, 'content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'MANUAL']]]]],
    ])->assertUnprocessable();
    expect(Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
})->with([[Platform::Instagram, 'story'], [Platform::Facebook, 'reels']]);

test('trial precheck accepts only one effective Instagram Reel video', function (PostFormat $format, string $mediaKind, int $count, bool $valid) {
    $target = trialReelTarget(['format' => $format]);
    PostMedia::factory()->count($count)->create([
        'workspace_id' => $target->post->workspace_id, 'post_id' => $target->post_id,
        'kind' => $mediaKind, 'mime' => $mediaKind === 'video' ? 'video/mp4' : 'image/jpeg',
        'duration_seconds' => 10, 'size_bytes' => 100,
    ]);
    $blocked = app(PublishPrecheck::class)->blockingTargets($target->post->fresh(['targets.account', 'media']));
    expect($blocked === [])->toBe($valid);
    if (! $valid) {
        expect($blocked[0]['issues'])->toContain('instagram_trial_requires_reel');
    }
})->with([
    [PostFormat::Reels, 'video', 1, true], [PostFormat::Feed, 'video', 1, true],
    [PostFormat::Story, 'video', 1, false], [PostFormat::Feed, 'image', 1, false],
    [PostFormat::Feed, 'video', 2, false], [PostFormat::Reels, 'video', 0, false],
]);

test('invalid saved trial settings block precheck instead of being ignored', function () {
    $target = trialReelTarget(['content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'OTHER']]]]);
    $video = PostMedia::factory()->create(['post_id' => $target->post_id, 'workspace_id' => $target->post->workspace_id, 'kind' => 'video']);
    expect(app(InstagramTrialReel::class)->issues($target, [$video]))->toBe(['instagram_trial_invalid']);
});

test('Instagram trial changes cannot alter an already started upload', function (array $state, ?array $original, ?array $replacement, PostTargetStatus $status) {
    [$user, $workspace] = trialReelMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram, 'status' => $status,
        'content_override' => ['instagram' => ['trial_params' => $original]], 'media_upload_state' => $state,
    ]);
    $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Trial caption'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['instagram' => ['trial_params' => $replacement]]]],
    ])->assertUnprocessable();
    expect($target->fresh()->content_override['instagram']['trial_params'])->toBe($original)
        ->and($target->fresh()->media_upload_state)->toBe($state);
})->with([
    'container exists' => [['container' => ['remote_ref' => 'existing-container']], ['graduation_strategy' => 'MANUAL'], null, PostTargetStatus::Pending],
    'regular snapshot' => [['container' => ['metadata' => ['instagram_trial_params' => null]]], null, ['graduation_strategy' => 'MANUAL'], PostTargetStatus::Failed],
    'trial snapshot' => [['container' => ['metadata' => ['instagram_trial_params' => ['graduation_strategy' => 'MANUAL']]]], ['graduation_strategy' => 'MANUAL'], ['graduation_strategy' => 'SS_PERFORMANCE'], PostTargetStatus::Failed],
    'worker claimed' => [[], null, ['graduation_strategy' => 'MANUAL'], PostTargetStatus::Publishing],
]);

test('a failed regular container snapshot permits unrelated caption edits', function () {
    [$user, $workspace] = trialReelMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram, 'status' => PostTargetStatus::Failed,
        'media_upload_state' => ['container' => ['metadata' => ['instagram_trial_params' => null]]],
    ]);
    $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Edited caption'], 'destination' => ['kind' => 'account', 'id' => $account->id],
    ])->assertOk()->assertJsonPath('post.base_text', 'Edited caption');
});

test('started trial media and destinations cannot be removed through a draft update', function (string $change) {
    [$user, $workspace] = trialReelMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram,
        'content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'MANUAL']]],
        'media_upload_state' => ['container' => ['remote_ref' => 'existing-container']],
    ]);
    $video = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => $post->id, 'kind' => 'video', 'mime' => 'video/mp4']);
    $payload = ['segments' => ['Trial caption'], 'destination' => ['kind' => 'account', 'id' => $account->id]];
    if ($change === 'media') {
        $payload['media_ids'] = [];
    } else {
        $payload['destination'] = ['kind' => 'none'];
    }
    $this->actingAs($user)->putJson(route('posts.update', $post), $payload)->assertUnprocessable();
    expect($target->fresh())->not->toBeNull()->and($video->fresh()->post_id)->toBe($post->id);
})->with(['media', 'destination']);

test('scheduling and duplicating a trial draft preserves its explicit graduation settings', function () {
    [$user, $workspace] = trialReelMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $trial = ['graduation_strategy' => 'SS_PERFORMANCE'];
    PostTarget::factory()->for($post)->create(['connected_account_id' => $account->id, 'platform' => Platform::Instagram, 'content_override' => ['instagram' => ['trial_params' => $trial]]]);
    Bus::fake();
    $this->actingAs($user)->putJson('/posts/'.$post->id.'/schedule', ['scheduled_at' => now()->addDay()->toIso8601String()])
        ->assertOk()->assertJsonPath('post.targets.0.content_override.instagram.trial_params', $trial);
    $copy = app(PostDuplicator::class)->duplicate($post->fresh());
    expect($copy->targets->sole()->content_override['instagram']['trial_params'])->toBe($trial)
        ->and($copy->targets->sole()->media_upload_state)->toBeNull();
    Bus::assertNothingDispatched();
});

test('an inherited trial cannot become a Story when the override is omitted', function () {
    [$user, $workspace] = trialReelMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram, 'format' => PostFormat::Reels,
        'content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'MANUAL']]],
    ]);
    $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Caption'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'format' => 'story']],
    ])->assertUnprocessable();
    expect($target->fresh()->format)->toBe(PostFormat::Reels);
});

test('MCP rejects malformed trial settings before creating a draft', function () {
    [$user, $workspace] = trialReelMember();
    bindTokenToWorkspace($user, $workspace);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'Invalid trial', 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'AUTO']]]]],
    ])->assertHasErrors();
    expect(Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

test('trial settings do not bypass cover workspace ownership validation', function () {
    [$user, $workspace] = trialReelMember();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $foreignCover = PostMedia::factory()->create(['kind' => 'image', 'mime' => 'image/jpeg', 'size_bytes' => 100]);
    $this->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['Trial caption'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['instagram' => [
            'cover_media_id' => $foreignCover->id, 'trial_params' => ['graduation_strategy' => 'MANUAL'],
        ]]]],
    ])->assertUnprocessable();
    expect(Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

test('a started trial cannot change its format or effective media selection', function (array $declaration) {
    [$user, $workspace] = trialReelMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram, 'format' => PostFormat::Reels,
        'content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'MANUAL']]],
        'media_upload_state' => ['container' => ['metadata' => ['instagram_trial_params' => ['graduation_strategy' => 'MANUAL']]]],
    ]);
    $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Caption'], 'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, ...$declaration]],
    ])->assertUnprocessable();
    expect($target->fresh()->format)->toBe(PostFormat::Reels)
        ->and($target->fresh()->content_override)->not->toHaveKey('media_ids');
})->with([
    'format' => [['format' => 'feed']],
    'legacy media' => [['content_override' => ['media_ids' => []]]],
]);

test('a started regular Instagram target cannot be removed to reset its trial snapshot', function () {
    [$user, $workspace] = trialReelMember();
    $post = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram,
        'media_upload_state' => ['container' => ['metadata' => ['instagram_trial_params' => null]]],
    ]);
    $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Caption'], 'destination' => ['kind' => 'none'],
    ])->assertUnprocessable();
    expect($target->fresh()->media_upload_state)->toBe(['container' => ['metadata' => ['instagram_trial_params' => null]]]);
});

test('another draft cannot steal the video from a started Trial Reel', function () {
    [$user, $workspace] = trialReelMember();
    $source = Post::factory()->for($workspace)->create();
    $destination = Post::factory()->for($workspace)->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($source, 'post')->create([
        'connected_account_id' => $account->id, 'platform' => Platform::Instagram,
        'content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'MANUAL']]],
        'media_upload_state' => ['container' => ['metadata' => ['instagram_trial_params' => ['graduation_strategy' => 'MANUAL']]]],
    ]);
    $video = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => $source->id, 'kind' => 'video', 'mime' => 'video/mp4']);
    $this->actingAs($user)->putJson(route('posts.update', $destination), [
        'segments' => ['Caption'], 'destination' => ['kind' => 'account', 'id' => $account->id], 'media_ids' => [$video->id],
    ])->assertUnprocessable();
    expect($video->fresh()->post_id)->toBe($source->id);
});
