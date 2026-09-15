<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

test('member can leave and current workspace is reassigned', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $user->id]);

    $this->actingAs($user)->delete(route('workspaces.leave', $workspace))->assertRedirect();

    $this->assertFalse($user->fresh()->isMemberOfWorkspace($workspace->id));
    $this->assertSame($other->id, $user->fresh()->current_workspace_id);
});

test('sole owner with other members cannot leave', function () {
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $member = User::factory()->create();
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id]);

    $this->actingAs($owner)->delete(route('workspaces.leave', $workspace))->assertSessionHasErrors();

    $this->assertTrue($owner->fresh()->isMemberOfWorkspace($workspace->id));
});

test('the final workspace cannot be left even by its sole member', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $membership = WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)->delete(route('workspaces.leave', $workspace))
        ->assertSessionHasErrors('workspace');

    $this->assertModelExists($membership);
    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);
});
