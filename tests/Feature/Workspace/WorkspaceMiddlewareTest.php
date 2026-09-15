<?php

use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('middleware sets current workspace in context', function () {
    Route::middleware('web')->get('/__ctx', fn () => Context::get('workspace_id'));

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    $this->actingAs($user)->get('/__ctx')->assertSee($workspace->id);
});

test('missing or unauthorized workspace exposes no dashboard tenant data', function (string $selection) {
    $workspace = Workspace::factory()->create();
    $post = Post::factory()->for($workspace)->create(['base_text' => 'Private foreign draft']);
    PostMedia::factory()->for($post)->create(['workspace_id' => $workspace->id]);
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $user = User::factory()->create([
        'current_workspace_id' => $selection === 'missing' ? null : $workspace->id,
    ]);
    if ($selection === 'revoked') {
        WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id])->delete();
    }
    $user->load('currentWorkspace');
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding', null)
            ->has('shell.accounts', 0)
            ->has('shell.sets', 0)
            ->has('savedMentions', 0)
            ->loadDeferredProps(fn (Assert $reload) => $reload->has('posts', 0)));

    expect(Context::has('workspace_id'))->toBeTrue()
        ->and(Context::get('workspace_id'))->toBeNull()
        ->and($user->currentWorkspace)->toBeNull()
        ->and(Post::query()->count())->toBe(0)
        ->and(PostMedia::query()->count())->toBe(0)
        ->and(ConnectedAccount::query()->count())->toBe(0);

    $this->get(route('posts.index'))->assertForbidden();
    $this->postJson(route('posts.store'), [
        'base_text' => 'Must not save',
        'destination' => ['kind' => 'none'],
    ])->assertForbidden();
    expect(Post::withoutGlobalScopes()->count())->toBe(1);
})->with(['missing', 'foreign', 'revoked']);

test('a user without a current workspace can create one and recover access', function () {
    $foreign = Workspace::factory()->create();
    Post::factory()->for($foreign)->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('workspaces.store'), ['name' => 'Recovered workspace'])
        ->assertRedirect(route('dashboard'));

    $workspaceId = $user->fresh()->current_workspace_id;
    expect($workspaceId)->not->toBeNull()
        ->and($workspaceId)->not->toBe($foreign->id)
        ->and($user->isMemberOfWorkspace($workspaceId))->toBeTrue();

    $this->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->has('posts', 0)));
});

test('a user without a workspace can accept an invitation and recover access', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    [$plain, $hash] = WorkspaceInvitation::generateToken();
    WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => $user->email,
        'token' => $hash,
    ]);

    $this->actingAs($user)->get(route('workspace.invitation', $plain))
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->current_workspace_id)->toBe($workspace->id)
        ->and($user->isMemberOfWorkspace($workspace->id))->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
});

test('instance administration and profile remain available without workspace membership', function () {
    $owner = User::factory()->instanceOwner()->create();
    $this->actingAs($owner)->get(route('instance-settings.edit'))->assertOk();
    $this->get(route('profile.edit'))->assertOk();
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

test('a guest request clears stale authenticated workspace context', function () {
    Context::add('workspace_id', Workspace::factory()->create()->id);

    $this->get(route('login'))->assertOk();

    expect(Context::has('workspace_id'))->toBeFalse();
});
