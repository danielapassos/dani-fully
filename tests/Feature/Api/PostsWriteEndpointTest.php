<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Jobs\DeletePostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('creates a draft post', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'Hello from the API',
        'destination' => ['kind' => 'all'],
    ])
        ->assertCreated()
        ->assertJsonPath('post.base_text', 'Hello from the API');
});

test('validates destination', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->postJson('/api/v1/posts', ['base_text' => 'x'])
        ->assertStatus(422);
});

test('API draft writes preserve explicit publishing choices without filling missing declarations', function (string $operation, Platform $platform, string $key, array $options): void {
    Http::preventStrayRequests();
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => $platform]);
    $post = $operation === 'update'
        ? Post::factory()->for($workspace)->create(['author_id' => $user->id])
        : null;
    $payload = [
        'base_text' => 'Declared publishing choices',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'content_override' => [$key => $options],
        ]],
    ];

    $response = $post === null
        ? $this->withToken($token)->postJson('/api/v1/posts', $payload)
        : $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", $payload);

    $response->assertStatus($post === null ? 201 : 200)
        ->assertJsonPath("post.targets.0.content_override.{$key}", $options);
    $saved = Post::query()->findOrFail($response->json('post.id'));
    expect($saved->targets()->where('connected_account_id', $account->id)->sole()->content_override[$key])->toBe($options);
    Http::assertNothingSent();
})->with(['create', 'update'])->with('declared publishing options');

test('API draft writes reject invalid publishing declarations', function (string $operation, Platform $platform, string $key, array $options): void {
    Http::preventStrayRequests();
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => $platform]);
    $post = $operation === 'update'
        ? Post::factory()->for($workspace)->create(['author_id' => $user->id, 'base_text' => 'Unchanged'])
        : null;
    $payload = [
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'content_override' => [$key => $options],
        ]],
    ];

    $response = $post === null
        ? $this->withToken($token)->postJson('/api/v1/posts', $payload)
        : $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", $payload);

    $response->assertUnprocessable();
    expect(Post::query()->where('workspace_id', $workspace->id)->where('base_text', 'Must not save')->exists())->toBeFalse();
    if ($post !== null) {
        expect($post->fresh()->base_text)->toBe('Unchanged');
    }
    Http::assertNothingSent();
})->with(['create', 'update'])->with('invalid publishing options');

test('updates a draft post', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Edited',
        'destination' => ['kind' => 'all'],
    ])
        ->assertOk()
        ->assertJsonPath('post.base_text', 'Edited');
});

test('API caption edits preserve omitted TikTok choices and honor explicit replacements', function (array $stored, array $override, array $expected): void {
    Http::preventStrayRequests();
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'content_override' => ['segments' => ['Original'], 'tiktok' => $stored],
    ]);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Edited',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'content_override' => $override,
        ]],
    ])->assertOk()
        ->assertJsonPath('post.targets.0.content_override.tiktok', $expected);

    expect($target->fresh()->content_override['tiktok'])->toBe($expected)
        ->and($target->fresh()->sections)->toBe(['Edited']);
    Http::assertNothingSent();
})->with('TikTok publishing settings edits');

test('updates media placements including an explicit empty account selection', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $media = PostMedia::factory()->for($workspace)->create();

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'First\nSecond',
        'segments' => ['First', 'Second'],
        'destination' => ['kind' => 'all'],
        'media_ids' => [$media->id],
        'segment_breaks' => ['break-1'],
        'placements' => [[
            'media_id' => $media->id,
            'segment_ref' => '__head__',
            'position' => 0,
        ]],
        'targets' => [[
            'connected_account_id' => $account->id,
            'segment_breaks' => ['break-1'],
            'placements' => [],
        ]],
    ])
        ->assertOk()
        ->assertJsonPath('post.targets.0.placements_explicit', true)
        ->assertJsonPath('post.targets.0.placements', []);

    $target = $post->targets()->where('connected_account_id', $account->id)->sole();
    expect($target->placements_explicit)->toBeTrue()
        ->and($target->placements()->count())->toBe(0)
        ->and($post->media()->pluck('post_media.id')->all())->toBe([$media->id]);
});

test('deletes a draft post', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => 'draft']);

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(Post::whereKey($post->id)->exists())->toBeFalse();
});

test('returns 409 on a stale write', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Edited',
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ])
        ->assertStatus(409);
});

test('deleting a published post dispatches remote deletion for targets with a remote_id', function () {
    Queue::fake();

    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id,
        'status' => PostStatus::Published->value,
    ]);
    PostTarget::factory()->for($post)->create(['remote_id' => 'remote-abc']);

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('remote', true);

    Queue::assertPushed(DeletePostTarget::class);

    $post->refresh();
    expect(Post::whereKey($post->id)->exists())->toBeTrue()
        ->and($post->status)->toBe(PostStatus::Deleted);
});
