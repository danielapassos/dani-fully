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
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
});

function makePost(Workspace $w, User $u, PostStatus $status): Post
{
    return Post::factory()->for($w)->create([
        'author_id' => $u->id,
        'status' => $status->value,
    ]);
}

it('lists workspace posts and excludes deleted', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Deleted);

    $this->actingAs($this->user)
        ->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page
            ->component('posts/index')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.status', 'draft')));
});

it('filters by status tab', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Scheduled);

    $this->actingAs($this->user)
        ->get(route('posts.index', ['status' => 'scheduled']))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.status', 'scheduled')));
});

it('exposes per-status tab counts that exclude deleted', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Scheduled);
    makePost($this->workspace, $this->user, PostStatus::Deleted);

    $this->actingAs($this->user)
        ->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('counts.all', 3)
            ->where('counts.draft', 2)
            ->where('counts.scheduled', 1)
            ->where('counts.published', 0));
});

it('filters by text query on base_text', function (): void {
    Post::factory()->for($this->workspace)->create(['author_id' => $this->user->id, 'base_text' => 'launch announcement']);
    Post::factory()->for($this->workspace)->create(['author_id' => $this->user->id, 'base_text' => 'weekly recap']);

    $this->actingAs($this->user)
        ->get(route('posts.index', ['q' => 'launch']))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.base_text', 'launch announcement')));
});

it('the completed tab includes uploads and public posts but excludes unfinished posts', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Published);
    makePost($this->workspace, $this->user, PostStatus::Completed);
    makePost($this->workspace, $this->user, PostStatus::AwaitingAction);
    makePost($this->workspace, $this->user, PostStatus::Publishing);

    $this->actingAs($this->user)
        ->get(route('posts.index', ['status' => 'published']))
        ->assertInertia(fn ($page) => $page
            ->where('counts.published', 2)
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 2)
                ->where('posts.data', fn ($posts): bool => collect($posts)->pluck('status')->sort()->values()->all() === ['completed', 'published'])));
});
