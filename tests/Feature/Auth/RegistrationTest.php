<?php

use App\Dto\Workspace\InvitationAcceptanceResult;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Workspace\WorkspaceInvitationService;
use App\Support\InstanceSettings;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registration screen preloads the email for a valid invitation', function () {
    [$plain, $hash] = WorkspaceInvitation::generateToken();
    WorkspaceInvitation::factory()->create([
        'email' => 'invited@example.com',
        'token' => $hash,
    ]);

    $this->get(route('register', ['invitation' => $plain]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('invitation', $plain)
            ->where('invitationEmail', 'invited@example.com'));
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('first registered user becomes the instance owner', function () {
    $this->post(route('register.store'), [
        'name' => 'Instance Owner',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(auth()->user()->isInstanceOwner())->toBeTrue();
});

test('public registration is disabled by default after the first user registers', function () {
    $this->post(route('register.store'), [
        'name' => 'Instance Owner',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    auth()->logout();

    $this->post(route('register.store'), [
        'name' => 'Blocked User',
        'email' => 'blocked@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'blocked@example.com')->exists())->toBeFalse();
});

test('registration can be disabled after the instance owner exists', function () {
    User::factory()->instanceOwner()->create();

    app(InstanceSettings::class)->update([
        'registrations_enabled' => false,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Blocked User',
        'email' => 'blocked@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');
});

test('registration screen redirects to login when public registration is disabled', function () {
    User::factory()->instanceOwner()->create();

    app(InstanceSettings::class)->update([
        'registrations_enabled' => false,
    ]);

    $this->get(route('register'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionMissing('status');
});

test('login screen knows public registration is disabled', function () {
    User::factory()->instanceOwner()->create();

    app(InstanceSettings::class)->update([
        'registrations_enabled' => false,
    ]);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canRegister', false)
            ->where('registrationDisabledMessage', 'Registration is disabled for this instance.'));
});

test('closed registration rejects invalid expired and accepted invitation tokens', function (string $kind) {
    User::factory()->instanceOwner()->create();
    app(InstanceSettings::class)->update(['registrations_enabled' => false]);
    [$plain, $hash] = WorkspaceInvitation::generateToken();

    if ($kind !== 'invalid') {
        WorkspaceInvitation::factory()->create([
            'token' => $hash,
            'expires_at' => $kind === 'expired' ? now()->subMinute() : now()->addDay(),
            'accepted_at' => $kind === 'accepted' ? now() : null,
        ]);
    }

    $userCount = User::count();
    $workspaceCount = Workspace::count();

    $this->get(route('register', ['invitation' => $plain]))->assertRedirect(route('login'));
    $this->get(route('login', ['invitation' => $plain]))
        ->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
    $this->post(route('register.store'), [
        'name' => 'Uninvited User',
        'email' => 'uninvited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $plain,
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::count())->toBe($userCount)
        ->and(Workspace::count())->toBe($workspaceCount);
})->with(['invalid', 'expired', 'accepted']);

test('closed registration still permits the recipient of a valid invitation', function () {
    User::factory()->instanceOwner()->create();
    app(InstanceSettings::class)->update(['registrations_enabled' => false]);
    [$plain, $hash] = WorkspaceInvitation::generateToken();
    $invitation = WorkspaceInvitation::factory()->create([
        'email' => 'invited@example.com',
        'token' => $hash,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Invited User',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $plain,
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(auth()->user()->current_workspace_id)->toBe($invitation->workspace_id)
        ->and($invitation->fresh()->isAccepted())->toBeTrue();
});

test('closed registration rolls back provisioning if the invitation can no longer be accepted', function () {
    User::factory()->instanceOwner()->create();
    app(InstanceSettings::class)->update(['registrations_enabled' => false]);
    [$plain, $hash] = WorkspaceInvitation::generateToken();
    WorkspaceInvitation::factory()->create(['email' => 'invited@example.com', 'token' => $hash]);
    $userCount = User::count();
    $workspaceCount = Workspace::count();

    $this->mock(WorkspaceInvitationService::class)
        ->shouldReceive('acceptByToken')->once()
        ->andReturn(new InvitationAcceptanceResult(false, 'Invitation was revoked.', 'error'));

    $this->post(route('register.store'), [
        'name' => 'Invited User',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $plain,
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::count())->toBe($userCount)
        ->and(Workspace::count())->toBe($workspaceCount);
});

test('malformed invitation input does not bypass closed registration or break login', function () {
    User::factory()->instanceOwner()->create();
    $this->get(route('login', ['invitation' => ['unexpected']]))
        ->assertInertia(fn (Assert $page) => $page->where('canRegister', false)->where('invitation', null));
    $this->post(route('register.store'), [
        'name' => 'Uninvited User',
        'email' => 'uninvited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => ['unexpected'],
    ])->assertSessionHasErrors('email');
});
