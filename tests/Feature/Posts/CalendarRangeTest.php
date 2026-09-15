<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    Context::add('workspace_id', $this->workspace->id);
});

it('exposes the calendar at its own top-level endpoint', function (): void {
    expect(route('calendar.index', absolute: false))->toBe('/calendar');
    expect(route('calendar.month', ['yyyymm' => '2026-06'], absolute: false))->toBe('/calendar/2026-06');
});

it('bare calendar redirects to the current month', function (): void {
    $this->actingAs($this->user)
        ->get(route('calendar.index'))
        ->assertRedirect(route('calendar.month', ['yyyymm' => now()->format('Y-m')]));
});

it('returns scheduled + published posts whose date falls in the visible window', function (): void {
    Post::factory()->for($this->workspace)->create([
        'author_id' => $this->user->id, 'status' => PostStatus::Scheduled->value,
        'scheduled_at' => '2026-06-15 09:00:00',
    ]);
    Post::factory()->for($this->workspace)->create([
        'author_id' => $this->user->id, 'status' => PostStatus::Published->value,
        'published_at' => '2026-06-20 12:00:00',
    ]);
    Post::factory()->for($this->workspace)->create([
        'author_id' => $this->user->id, 'status' => PostStatus::Draft->value,
    ]); // no date → excluded

    $this->actingAs($this->user)
        ->get(route('calendar.month', ['yyyymm' => '2026-06']))
        ->assertInertia(fn ($page) => $page
            ->component('posts/calendar/index')
            ->where('view', 'month')
            ->missing('posts')               // deferred — streamed in after the grid frame paints
            ->loadDeferredProps(fn ($reload) => $reload->has('posts', 2)));
});

it('keeps inbox deliveries and nonpublic uploads on their completion date in the calendar', function (PostStatus $status): void {
    Post::factory()->for($this->workspace)->create([
        'author_id' => $this->user->id,
        'status' => $status,
        'scheduled_at' => null,
        'published_at' => null,
        'updated_at' => '2026-06-15 09:00:00',
    ]);

    $this->actingAs($this->user)
        ->get(route('calendar.month', ['yyyymm' => '2026-06']))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload->has('posts', 1)->where('posts.0.status', $status->value)));
})->with([PostStatus::AwaitingAction, PostStatus::Completed]);
