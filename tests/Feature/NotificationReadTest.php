<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;

function makeNotification(User $user, ?string $workspaceId, ?string $readAt = null): string
{
    $id = (string) Str::uuid();
    $user->notifications()->create([
        'id' => $id,
        'type' => 'App\\Notifications\\PostPublishedNotification',
        'data' => ['event' => 'post_published', 'title' => 'X', 'body' => '', 'href' => null, 'icon' => 'bell', 'workspace_id' => $workspaceId],
        'read_at' => $readAt,
    ]);

    return $id;
}

test('a user can mark one notification read', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    $id = makeNotification($user, $ws->id);

    $this->actingAs($user)->post(route('notifications.read', $id))->assertRedirect();

    expect($user->notifications()->find($id)->read_at)->not->toBeNull();
});

test('a user cannot mark another users notification read', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ws = Workspace::factory()->create();
    $id = makeNotification($owner, $ws->id);

    $this->actingAs($other)->post(route('notifications.read', $id))->assertNotFound();

    expect($owner->notifications()->find($id)->read_at)->toBeNull();
});

test('mark-all-read clears unread for the current workspace and global notifications only', function () {
    $user = User::factory()->create();
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();
    WorkspaceMembership::factory()->for($wsA)->for($user)->create();
    $user->forceFill(['current_workspace_id' => $wsA->id])->save();
    makeNotification($user, $wsA->id);
    makeNotification($user, null);
    $bId = makeNotification($user, $wsB->id);

    $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(1);
    expect($user->notifications()->find($bId)->read_at)->toBeNull();
});

test('a user can delete one notification', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    $id = makeNotification($user, $ws->id);

    $this->actingAs($user)->delete(route('notifications.destroy', $id))->assertRedirect();

    expect($user->notifications()->find($id))->toBeNull();
});

test('a user cannot delete another users notification', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ws = Workspace::factory()->create();
    $id = makeNotification($owner, $ws->id);

    $this->actingAs($other)->delete(route('notifications.destroy', $id))->assertNotFound();

    expect($owner->notifications()->find($id))->not->toBeNull();
});

test('delete-all removes notifications for the current workspace and global notifications only', function () {
    $user = User::factory()->create();
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();
    WorkspaceMembership::factory()->for($wsA)->for($user)->create();
    $user->forceFill(['current_workspace_id' => $wsA->id])->save();
    $aId = makeNotification($user, $wsA->id);
    $globalId = makeNotification($user, null);
    $bId = makeNotification($user, $wsB->id);

    $this->actingAs($user)->delete(route('notifications.destroy-all'))->assertRedirect();

    expect($user->notifications()->find($aId))->toBeNull();
    expect($user->notifications()->find($globalId))->toBeNull();
    expect($user->notifications()->find($bId))->not->toBeNull();
});

test('a json request can delete one notification without a redirect', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    $id = makeNotification($user, $ws->id);

    $this->actingAs($user)
        ->deleteJson(route('notifications.destroy', $id))
        ->assertNoContent();

    expect($user->notifications()->find($id))->toBeNull();
});

test('a json request can delete all notifications without a redirect', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceMembership::factory()->for($ws)->for($user)->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    $id = makeNotification($user, $ws->id);

    $this->actingAs($user)
        ->deleteJson(route('notifications.destroy-all'))
        ->assertNoContent();

    expect($user->notifications()->find($id))->toBeNull();
});

test('a json request can mark one notification read without a redirect', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    $id = makeNotification($user, $ws->id);

    $this->actingAs($user)
        ->postJson(route('notifications.read', $id))
        ->assertNoContent();

    expect($user->notifications()->find($id)->read_at)->not->toBeNull();
});

test('a json request can mark all notifications read without a redirect', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceMembership::factory()->for($ws)->for($user)->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    makeNotification($user, $ws->id);

    $this->actingAs($user)
        ->postJson(route('notifications.read-all'))
        ->assertNoContent();

    expect($user->unreadNotifications()->count())->toBe(0);
});
