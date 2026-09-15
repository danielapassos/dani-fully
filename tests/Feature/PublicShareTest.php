<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostShare;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Publishing\SegmentMediaResolver;

function shareFor(string $token, ?callable $state = null): PostShare
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id, 'base_text' => 'shared body',
    ]);
    $factory = PostShare::factory()->for($post)->state(['token_hash' => hash('sha256', $token)]);
    if ($state !== null) {
        $factory = $state($factory);
    }

    return $factory->create();
}

it('renders a read-only view for a valid token', function (): void {
    shareFor('good-token');

    $this->get('/share/good-token')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn ($page) => $page
            ->component('share/show')
            ->where('post.base_text', 'shared body'));
});

it('renders the media selected for each target section in stable array order', function (): void {
    $share = shareFor('placed-media');
    $post = $share->post;
    $account = ConnectedAccount::factory()->create(['workspace_id' => $post->workspace_id]);
    $media = PostMedia::factory()->create([
        'workspace_id' => $post->workspace_id,
        'post_id' => $post->id,
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'sections' => ['First', 'Second'],
        'segment_breaks' => ['break-1'],
        'section_sources' => [0, 1],
        'placements_explicit' => true,
    ]);
    $target->placements()->create([
        'post_media_id' => $media->id,
        'segment_ref' => 'break-1',
        'position' => 0,
    ]);

    $this->get('/share/placed-media')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('post.targets.0.media_by_section.0', [])
            ->where('post.targets.0.media_by_section.1.0.id', $media->id));
});

it('does not expose an attachment excluded from every shared target', function (): void {
    $share = shareFor('excluded-media');
    $post = $share->post;
    $account = ConnectedAccount::factory()->create(['workspace_id' => $post->workspace_id]);
    $media = PostMedia::factory()->create([
        'workspace_id' => $post->workspace_id,
        'post_id' => $post->id,
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'sections' => ['Text only'],
        'section_sources' => [0],
        'placements_explicit' => true,
    ]);

    $this->get('/share/excluded-media')
        ->assertOk()
        ->assertDontSee($media->id)
        ->assertInertia(fn ($page) => $page
            ->where('post.targets.0.media_by_section.0', [])
            ->where('post.media', []));
});

it('shows not-available for unknown / revoked / expired tokens', function (): void {
    $this->get('/share/nope')->assertInertia(fn ($page) => $page
        ->component('share/show')->where('post', null));

    shareFor('revoked-token', fn ($f) => $f->revoked());
    $this->get('/share/revoked-token')->assertInertia(fn ($page) => $page->where('post', null));

    shareFor('expired-token', fn ($f) => $f->expired());
    $this->get('/share/expired-token')->assertInertia(fn ($page) => $page->where('post', null));
});

it('a valid share keeps its selected media and account for users outside its workspace', function (string $selection): void {
    $share = shareFor('authorized-share');
    $post = $share->post;
    $account = ConnectedAccount::factory()->create(['workspace_id' => $post->workspace_id]);
    $media = PostMedia::factory()->for($post)->create(['workspace_id' => $post->workspace_id]);
    $excluded = PostMedia::factory()->for($post)->create(['workspace_id' => $post->workspace_id]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'sections' => ['Shared media'],
        'section_sources' => [0],
        'placements_explicit' => true,
    ]);
    $target->placements()->create([
        'post_media_id' => $media->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);
    $user = User::factory()->create();
    if ($selection === 'another') {
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    }

    $this->actingAs($user)->get('/share/authorized-share')
        ->assertOk()
        ->assertDontSee($excluded->id)
        ->assertInertia(fn ($page) => $page
            ->where('post.targets.0.handle', $account->handle)
            ->where('post.targets.0.media_by_section.0.0.id', $media->id));
})->with(['missing', 'another']);
