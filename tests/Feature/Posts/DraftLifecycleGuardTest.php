<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;

function draftLifecycleFixture(PostStatus $status = PostStatus::Draft, array $targetAttributes = []): array
{
    [$user, $workspace, $token] = issuedKey();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::YouTube]);
    $post = Post::factory()->for($workspace)->create(['status' => $status, 'segments' => ['Original'], 'base_text' => 'Original']);
    $video = PostMedia::factory()->for($workspace)->for($post)->video()->create();
    $otherVideo = PostMedia::factory()->for($workspace)->video()->create(['post_id' => null]);
    $cover = PostMedia::factory()->for($workspace)->create(['post_id' => null, 'kind' => 'image', 'mime' => 'image/jpeg', 'size_bytes' => 100]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id, 'platform' => Platform::YouTube,
        'sections' => ['Original'], 'placements_explicit' => true,
        'content_override' => ['youtube' => ['thumbnail_media_id' => $cover->id]],
        ...$targetAttributes,
    ]);
    $placement = PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id, 'post_media_id' => $video->id,
        'segment_ref' => '__head__', 'position' => 0,
    ]);

    return [$user, $workspace, $token, $post, $account, $target, $video, $otherVideo, $cover, $placement];
}

function draftLifecyclePayload(ConnectedAccount $account): array
{
    return [
        'segments' => ['Changed'], 'base_text' => 'Changed',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id]],
    ];
}

test('noneditable posts reject stale web API and MCP updates before any mutation', function (PostStatus $status, string $surface) {
    Http::preventStrayRequests();
    [$user, $workspace, $token, $post, $account, $target, $video, $replacement, , $placement] = draftLifecycleFixture($status);
    $payload = [...draftLifecyclePayload($account), 'media_ids' => [$replacement->id], 'destination' => ['kind' => 'none'], 'targets' => []];
    $updatedAt = $post->updated_at;
    if ($surface === 'mcp') {
        bindTokenToWorkspace($user, $workspace);
        ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, ['post_id' => $post->id, ...$payload])->assertHasErrors();
    } elseif ($surface === 'api') {
        $this->withToken($token)->patchJson('/api/v1/posts/'.$post->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('post');
    } else {
        $this->actingAs($user)->putJson(route('posts.update', $post), $payload)->assertUnprocessable()->assertJsonValidationErrors('post');
    }
    expect($post->fresh()->base_text)->toBe('Original')
        ->and($post->fresh()->status)->toBe($status)
        ->and($post->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($post->targets()->sole()->id)->toBe($target->id)
        ->and($video->fresh()->post_id)->toBe($post->id)
        ->and($replacement->fresh()->post_id)->toBeNull()
        ->and($placement->fresh())->not->toBeNull();
    Http::assertNothingSent();
})->with([
    'publishing web' => [PostStatus::Publishing, 'web'],
    'published web' => [PostStatus::Published, 'web'],
    'completed web' => [PostStatus::Completed, 'web'],
    'awaiting action web' => [PostStatus::AwaitingAction, 'web'],
    'partial web' => [PostStatus::Partial, 'web'],
    'failed web' => [PostStatus::Failed, 'web'],
    'publishing API' => [PostStatus::Publishing, 'api'],
    'completed API' => [PostStatus::Completed, 'api'],
    'publishing MCP' => [PostStatus::Publishing, 'mcp'],
    'completed MCP' => [PostStatus::Completed, 'mcp'],
]);

test('a started YouTube draft cannot replace media remove targets or change placements', function (string $started, string $mutation) {
    Http::preventStrayRequests();
    [$user, , , $post, $account, $target, $video, $replacement, $cover, $placement] = draftLifecycleFixture();
    $attributes = match ($started) {
        'session' => ['media_upload_state' => [$video->id => ['remote_ref' => 'session-original']]],
        'binding' => ['media_upload_state' => [$video->id => ['metadata' => ['thumbnail' => ['media_id' => $cover->id, 'sha256' => str_repeat('a', 64)]]]]],
        'pending init' => ['media_upload_state' => [$video->id => ['metadata' => ['thumbnail_session_pending' => true]]]],
        'remote id' => ['remote_id' => 'original-video'],
        'remote ids' => ['remote_ids' => ['original-video']],
    };
    $target->forceFill($attributes)->save();
    $payload = draftLifecyclePayload($account);
    if ($mutation === 'media') {
        $payload['media_ids'] = [$replacement->id];
    } elseif ($mutation === 'targets') {
        $payload['destination'] = ['kind' => 'none'];
        $payload['targets'] = [];
    } else {
        $payload['targets'][0]['placements'] = [];
    }
    $this->actingAs($user)->putJson(route('posts.update', $post), $payload)->assertUnprocessable()->assertJsonValidationErrors('post');
    expect($post->fresh()->base_text)->toBe('Original')
        ->and($post->targets()->sole()->id)->toBe($target->id)
        ->and($target->fresh()->content_override['youtube']['thumbnail_media_id'])->toBe($cover->id)
        ->and($target->fresh()->media_upload_state)->toBe($attributes['media_upload_state'] ?? null)
        ->and($target->fresh()->remote_id)->toBe($attributes['remote_id'] ?? null)
        ->and($target->fresh()->remote_ids)->toBe($attributes['remote_ids'] ?? null)
        ->and($video->fresh()->post_id)->toBe($post->id)
        ->and($replacement->fresh()->post_id)->toBeNull()
        ->and($placement->fresh())->not->toBeNull();
    Http::assertNothingSent();
})->with(['session', 'binding', 'pending init', 'remote id', 'remote ids'])->with(['media', 'targets', 'placements']);

test('draft and scheduled content can still change before YouTube upload starts', function (PostStatus $status) {
    [$user, , , $post, $account, $target, , $replacement, $cover] = draftLifecycleFixture($status);
    $payload = [...draftLifecyclePayload($account), 'media_ids' => [$replacement->id]];
    $payload['targets'][0]['placements'] = [['media_id' => $replacement->id, 'segment_ref' => '__head__', 'position' => 0]];
    $this->actingAs($user)->putJson(route('posts.update', $post), $payload)->assertOk();
    expect($post->fresh()->base_text)->toBe('Changed')
        ->and($post->fresh()->status)->toBe($status)
        ->and($replacement->fresh()->post_id)->toBe($post->id)
        ->and($target->fresh()->content_override['youtube']['thumbnail_media_id'])->toBe($cover->id);
})->with([PostStatus::Draft, PostStatus::Scheduled]);

test('another draft cannot take media from a completed or started source post', function (string $sourceState) {
    [$user, $workspace, , $source, , $sourceTarget, $sourceVideo, , , $sourcePlacement] = draftLifecycleFixture();
    if ($sourceState === 'published') {
        $source->forceFill(['status' => PostStatus::Published])->save();
    } else {
        $sourceTarget->forceFill(['media_upload_state' => [$sourceVideo->id => ['metadata' => ['thumbnail_session_pending' => true]]]])->save();
    }
    $destination = Post::factory()->for($workspace)->create(['base_text' => 'Destination unchanged', 'segments' => ['Destination unchanged']]);
    $destinationVideo = PostMedia::factory()->for($workspace)->for($destination)->video()->create();
    $this->actingAs($user)->putJson(route('posts.update', $destination), [
        'segments' => ['Must roll back'], 'destination' => ['kind' => 'none'], 'media_ids' => [$sourceVideo->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('media_ids');
    expect($sourceVideo->fresh()->post_id)->toBe($source->id)
        ->and($sourcePlacement->fresh())->not->toBeNull()
        ->and($source->targets()->sole()->id)->toBe($sourceTarget->id)
        ->and($destinationVideo->fresh()->post_id)->toBe($destination->id)
        ->and($destination->fresh()->base_text)->toBe('Destination unchanged');
})->with(['published', 'pending init']);

test('moving media between editable drafts remains supported before upload begins', function () {
    [$user, $workspace, , $source, , , $video] = draftLifecycleFixture();
    $destination = Post::factory()->for($workspace)->create();
    $this->actingAs($user)->putJson(route('posts.update', $destination), [
        'segments' => ['Moved'], 'destination' => ['kind' => 'none'], 'media_ids' => [$video->id],
    ])->assertOk();
    expect($video->fresh()->post_id)->toBe($destination->id)
        ->and($source->media()->count())->toBe(0);
});
