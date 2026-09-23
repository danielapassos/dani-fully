<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMention;
use App\Support\InstanceSettings;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

test('composer and shared shell expose explicit TikTok inbox mode while the post carries the saved caption', function (): void {
    [$user, $workspace, $accounts] = actingMember(1);
    config()->set('services.tiktok.publishing_provider', 'native');
    config()->set('services.tiktok.inbox_enabled', true);
    config()->set('services.tiktok.direct_post_enabled', false);
    $account = $accounts->first();
    $account->forceFill(['platform' => Platform::TikTok])->save();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'sections' => ["Saved caption\n@brand"],
    ]);

    $this->get("/posts/{$post->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accounts.0.tiktok_inbox_enabled', true)
        ->where('shell.accounts.0.tiktok_inbox_enabled', true)
        ->where('accounts.0.tiktok_direct_post_enabled', false)
        ->where('post.targets.0.manual_completion.caption', "Saved caption\n@brand"));
});

function actingMember(int $accounts = 2): array
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

    $list = collect(range(1, $accounts))->map(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]));

    return [$user, $workspace, $list];
}

test('POST /posts lazily creates a draft and returns its view as JSON', function () {
    [$user, $workspace, $accounts] = actingMember(2);

    $response = test()->postJson('/posts', [
        'base_text' => 'first draft',
        'segments' => ['first draft'],
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('post.base_text', 'first draft')
        ->assertJsonPath('post.status', 'draft')
        ->assertJsonCount(2, 'post.targets');

    expect(Post::withoutGlobalScopes()->count())->toBe(1);
});

test('POST /posts persists the auto_repost override sent on create', function () {
    [$user, $workspace, $accounts] = actingMember(1);

    test()->postJson('/posts', [
        'base_text' => 'boosted from the first save',
        'segments' => ['boosted from the first save'],
        'destination' => ['kind' => 'all'],
        'auto_repost' => true,
    ])->assertCreated()->assertJsonPath('post.auto_repost', true);

    expect(Post::withoutGlobalScopes()->first()->auto_repost)->toBeTrue();
});

test('POST /posts accepts a custom accounts destination', function () {
    [$user, $workspace, $accounts] = actingMember(3);

    test()->postJson('/posts', [
        'base_text' => 'custom draft',
        'segments' => ['custom draft'],
        'destination' => ['kind' => 'accounts', 'ids' => [$accounts[0]->id, $accounts[2]->id]],
    ])->assertCreated()
        ->assertJsonPath('post.destination.kind', 'accounts')
        ->assertJsonPath('post.destination.ids', collect([$accounts[0]->id, $accounts[2]->id])->sort()->values()->all())
        ->assertJsonCount(2, 'post.targets');
});

test('PUT /posts/{post} accepts a "none" destination and clears every target', function () {
    [$user, $workspace, $accounts] = actingMember(2);
    $created = test()->postJson('/posts', [
        'base_text' => 'a',
        'segments' => ['a'],
        'destination' => ['kind' => 'all'],
    ])->assertCreated()->json('post');

    expect($created['targets'])->toHaveCount(2);

    test()->putJson("/posts/{$created['id']}", [
        'base_text' => 'a',
        'segments' => ['a'],
        'destination' => ['kind' => 'none'],
        'targets' => [],
        'expected_updated_at' => $created['updated_at'],
    ])
        ->assertOk()
        ->assertJsonPath('post.destination.kind', 'none')
        ->assertJsonCount(0, 'post.targets');
});

test('PUT /posts/{post} autosaves edits and returns the new baseline', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $created = test()->postJson('/posts', ['base_text' => 'a', 'segments' => ['a'], 'destination' => ['kind' => 'all']])->json('post');

    $response = test()->putJson("/posts/{$created['id']}", [
        'base_text' => 'edited',
        'segments' => ['edited'],
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $accounts[0]->id, 'auto_split' => true]],
        'expected_updated_at' => $created['updated_at'],
    ]);

    $response->assertOk()->assertJsonPath('post.base_text', 'edited');
});

test('PUT text autosave preserves a targeted disabled account with its override and placements', function () {
    [$user, $workspace] = actingMember(0);
    $account = ConnectedAccount::factory()->disabled()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'handle' => '@disabled-draft-target',
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $user->id,
        'segments' => ['canonical before'],
        'base_text' => 'canonical before',
    ]);
    $media = PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'post_id' => $post->id,
    ]);
    $override = ['segments' => ['disabled custom copy'], 'media_ids' => [$media->id]];
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'sections' => ['disabled custom copy'],
        'content_override' => $override,
        'segment_breaks' => ['disabled-break'],
        'section_sources' => [0],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $media->id,
        'segment_ref' => 'disabled-break',
        'position' => 0,
    ]);

    test()->putJson("/posts/{$post->id}", [
        'segments' => ['canonical after'],
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ])->assertOk();

    $preserved = PostTarget::withoutGlobalScopes()->find($target->id);
    expect($preserved)->not->toBeNull()
        ->and($preserved->content_override)->toBe($override)
        ->and($preserved->sections)->toBe(['disabled custom copy'])
        ->and($preserved->segment_breaks)->toBe(['disabled-break'])
        ->and($preserved->placements()->count())->toBe(1)
        ->and($preserved->placements()->firstOrFail()->segment_ref)->toBe('disabled-break');
});

test('PUT text autosave preserves a targeted frozen-platform account with its override and placements', function () {
    [$user, $workspace] = actingMember(0);
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'handle' => '@frozen-draft-target',
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $user->id,
        'segments' => ['canonical before'],
        'base_text' => 'canonical before',
    ]);
    $media = PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'post_id' => $post->id,
    ]);
    $override = ['segments' => ['frozen custom copy'], 'media_ids' => [$media->id]];
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'sections' => ['frozen custom copy'],
        'content_override' => $override,
        'segment_breaks' => ['frozen-break'],
        'section_sources' => [0],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $media->id,
        'segment_ref' => 'frozen-break',
        'position' => 0,
    ]);
    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    test()->putJson("/posts/{$post->id}", [
        'segments' => ['canonical after'],
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ])->assertOk();

    $preserved = PostTarget::withoutGlobalScopes()->find($target->id);
    expect($preserved)->not->toBeNull()
        ->and($preserved->content_override)->toBe($override)
        ->and($preserved->sections)->toBe(['frozen custom copy'])
        ->and($preserved->segment_breaks)->toBe(['frozen-break'])
        ->and($preserved->placements()->count())->toBe(1)
        ->and($preserved->placements()->firstOrFail()->segment_ref)->toBe('frozen-break');
});

test('POST /posts accepts the real client payload with segments and no base_text', function () {
    [$user, $workspace, $accounts] = actingMember(2);

    // Mirrors use-autosave.ts createPost(): {segments, mentions, destination} — no base_text.
    $response = test()->postJson('/posts', [
        'destination' => ['kind' => 'all'],
        'segments' => ['first post', 'second post'],
        'mentions' => [],
    ]);

    $response->assertCreated()
        ->assertJsonPath('post.segments', ['first post', 'second post'])
        ->assertJsonPath('post.base_text', "first post\nsecond post");
});

test('POST /posts rejects an over-long linkedin_urn handle', function () {
    [$user, $workspace, $accounts] = actingMember(1);

    test()->postJson('/posts', [
        'destination' => ['kind' => 'all'],
        'segments' => ['hello'],
        'mentions' => [[
            'id' => 'coolify',
            'label' => '@coolify',
            'handles' => ['linkedin_urn' => str_repeat('a', 256)],
        ]],
    ])->assertInvalid(['mentions.0.handles.linkedin_urn']);
});

test('PUT /posts/{post} keeps a Discord mention handle through request validation', function () {
    // Regression: `validated()` strips any mentions.*.handles.* key without an
    // explicit rule (excludeUnvalidatedArrayKeys), which dropped the Discord
    // markup so it posted as the plain label instead of a native mention.
    [$user, $workspace] = actingMember(0);
    $discord = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Discord->value,
    ]);

    $created = test()->postJson('/posts', [
        'destination' => ['kind' => 'all'],
        'segments' => ['Ping @adiology'],
        'mentions' => [],
    ])->json('post');

    $response = test()->putJson("/posts/{$created['id']}", [
        'segments' => ['Ping @adiology'],
        'destination' => ['kind' => 'accounts', 'ids' => [$discord->id]],
        'targets' => [['connected_account_id' => $discord->id, 'auto_split' => true]],
        'mentions' => [[
            'id' => 'adiology',
            'label' => '@adiology',
            'handles' => ['discord' => '<@136549806079344640>'],
        ]],
        'expected_updated_at' => $created['updated_at'],
    ]);

    $response->assertOk()
        ->assertJsonPath('post.mentions.0.handles.discord', '<@136549806079344640>')
        ->assertJsonPath('post.targets.0.sections', ['Ping <@136549806079344640>']);
});

test('PUT /posts/{post} accepts the real buildPutBody payload with no base_text', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $created = test()->postJson('/posts', [
        'destination' => ['kind' => 'all'],
        'segments' => ['a'],
        'mentions' => [],
    ])->json('post');

    // Mirrors composer-state.ts buildPutBody(): no base_text key.
    $response = test()->putJson("/posts/{$created['id']}", [
        'segments' => ['edited'],
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $accounts[0]->id, 'auto_split' => true, 'content_override' => null]],
        'media_ids' => [],
        'mentions' => [],
        'expected_updated_at' => $created['updated_at'],
    ]);

    $response->assertOk()
        ->assertJsonPath('post.segments', ['edited'])
        ->assertJsonPath('post.base_text', 'edited');
});

test('PUT with a stale expected_updated_at returns 409 with the latest view', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $created = test()->postJson('/posts', ['base_text' => 'a', 'segments' => ['a'], 'destination' => ['kind' => 'all']])->json('post');

    test()->putJson("/posts/{$created['id']}", [
        'base_text' => 'edited',
        'segments' => ['edited'],
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => '2000-01-01T00:00:00+00:00',
    ])->assertStatus(409)->assertJsonPath('post.id', $created['id']);
});

test('a user cannot autosave a post in another workspace', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $foreign = Post::factory()->create(); // different workspace

    test()->putJson("/posts/{$foreign->id}", [
        'base_text' => 'x',
        'segments' => ['x'],
        'destination' => ['kind' => 'all'],
    ])->assertNotFound();
});

test('GET /posts/{post} renders the composer page for an existing post', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $accounts[0]->forceFill([
        'status' => 'needs_attention',
        'capabilities' => ['auto_repost' => ['enabled' => true]],
    ])->save();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'base_text' => 'hello']);

    test()->get("/posts/{$post->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('compose/index')
            ->where('post.id', $post->id)
            ->where('post.base_text', 'hello')
            ->where('accounts.0.status', 'needs_attention')
            ->where('accounts.0.publishing_ready', false)
            ->where('accounts.0.publishing_unavailable_reason', fn (string $reason): bool => str_contains($reason, 'Reconnect'))
            ->where('accounts.0.auto_repost_enabled', true));
});

test('GET /posts/{post} preserves a targeted disabled account without advertising other disabled accounts', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $targeted = ConnectedAccount::factory()->disabled()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'handle' => '@targeted-disabled',
    ]);
    $unrelated = ConnectedAccount::factory()->disabled()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'handle' => '@unrelated-disabled',
    ]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $targeted->id,
        'platform' => Platform::X,
    ]);

    test()->get("/posts/{$post->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accounts', function (Collection $items) use ($accounts, $targeted, $unrelated): bool {
                $byId = $items->keyBy('id');

                return $byId->has($accounts[0]->id)
                    && $byId->has($targeted->id)
                    && ! $byId->has($unrelated->id)
                    && $byId[$targeted->id]['publishing_ready'] === false
                    && str_contains($byId[$targeted->id]['publishing_unavailable_reason'], 'Re-enable');
            }));
});

test('GET /posts/{post} preserves a targeted account on a frozen platform without advertising other frozen accounts', function () {
    [$user, $workspace] = actingMember(0);
    $targeted = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'handle' => '@targeted-frozen',
    ]);
    $unrelated = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'handle' => '@unrelated-frozen',
    ]);
    $available = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Bluesky,
        'handle' => '@available',
    ]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $targeted->id,
        'platform' => Platform::X,
    ]);
    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    test()->get("/posts/{$post->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accounts', function (Collection $items) use ($targeted, $unrelated, $available): bool {
                $byId = $items->keyBy('id');

                return $byId->has($targeted->id)
                    && ! $byId->has($unrelated->id)
                    && $byId->has($available->id)
                    && $byId[$targeted->id]['publishing_ready'] === false
                    && str_contains($byId[$targeted->id]['publishing_unavailable_reason'], 'disabled on this installation');
            }));
});

test('GET /posts/{post} includes saved workspace mentions', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    WorkspaceMention::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '@saved',
        'handles' => ['x' => '@saved_x'],
    ]);
    WorkspaceMention::factory()->create([
        'name' => '@foreign',
        'handles' => ['x' => '@foreign_x'],
    ]);

    test()->get("/posts/{$post->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('compose/index')
            ->has('savedMentions', 1)
            ->where('savedMentions.0.name', '@saved')
            ->where('savedMentions.0.handles.x', '@saved_x'));
});

test('the old /compose/{post} URL no longer exists', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);

    test()->get("/compose/{$post->id}")->assertNotFound();
});

test('DELETE /posts/{post} removes the draft and its targets', function () {
    [$user, $workspace, $accounts] = actingMember(2);
    $created = test()->postJson('/posts', ['base_text' => 'a', 'segments' => ['a'], 'destination' => ['kind' => 'all']])->json('post');

    test()->delete("/posts/{$created['id']}")->assertRedirect();

    expect(Post::withoutGlobalScopes()->whereKey($created['id'])->exists())->toBeFalse();
});
