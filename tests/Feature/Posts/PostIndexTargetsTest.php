<?php

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Inertia\Testing\AssertableInertia;

test('posts index payload includes per-target status and published_at', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Partial,
        'published_at' => now(),
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'status' => PostTargetStatus::Failed->value,
        'error_kind' => ErrorKind::RateLimited->value,
        'error_message' => 'slow down',
        'attempts' => 3,
    ]);

    $this->actingAs($user)
        ->get('/posts')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('posts/index')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('posts.data.0.published_at', fn ($value) => $value !== null)
                ->where('posts.data.0.targets.0.platform', 'x')
                ->where('posts.data.0.targets.0.status', 'failed')
                ->where('posts.data.0.targets.0.error_kind', 'rate_limited')
                ->where('posts.data.0.targets.0.error_message', 'slow down')
                ->where('posts.data.0.targets.0.can_retry', true)
                ->where('posts.data.0.targets.0.retry_blocked_reason', null)
                ->where('posts.data.0.targets.0.attempts', 3)));
});
