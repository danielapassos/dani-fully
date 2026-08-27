<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\PostView;

test('post view exposes per-target publish status and root published_at', function () {
    $post = Post::factory()->create(['status' => PostStatus::Partial, 'published_at' => now()]);
    PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Failed->value,
        'error_kind' => ErrorKind::RateLimited->value,
        'error_message' => 'slow down',
        'attempts' => 3,
        'remote_id' => 'abc',
    ]);

    $view = PostView::make($post->fresh(['targets.account', 'media']));

    expect($view['published_at'])->not->toBeNull()
        ->and($view['targets'][0]['status'])->toBe('failed')
        ->and($view['targets'][0]['error_kind'])->toBe('rate_limited')
        ->and($view['targets'][0]['error_message'])->toBe('slow down')
        ->and($view['targets'][0]['can_retry'])->toBeTrue()
        ->and($view['targets'][0]['retry_blocked_reason'])->toBeNull()
        ->and($view['targets'][0]['attempts'])->toBe(3)
        ->and($view['targets'][0]['remote_id'])->toBe('abc');
});

test('post view marks an unconfirmed provider outcome for manual review', function () {
    $post = Post::factory()->create(['status' => PostStatus::Failed]);
    PostTarget::factory()->for($post)->failed()->create([
        'error_kind' => ErrorKind::Unknown->value,
        'error_message' => 'Threads may already have published this segment.',
    ]);

    $view = PostView::make($post->fresh(['targets.account', 'media']));

    expect($view['targets'][0]['can_retry'])->toBeFalse()
        ->and($view['targets'][0]['retry_blocked_reason'])
        ->toBe('The provider outcome is unconfirmed and may already be live. Check the connected platform before taking any further action.');
});

test('post view replaces retry with account recovery when a connection needs attention', function () {
    $post = Post::factory()->create(['status' => PostStatus::Failed]);
    $target = PostTarget::factory()->for($post)->failed()->create([
        'error_kind' => ErrorKind::AuthExpired->value,
        'error_message' => 'The access token expired.',
    ]);
    $target->account()->update(['status' => ConnectedAccountStatus::NeedsAttention->value]);

    $view = PostView::make($post->fresh(['targets.account', 'media']));

    expect($view['targets'][0]['can_retry'])->toBeFalse()
        ->and($view['targets'][0]['retry_blocked_reason'])->toContain('Reconnect')
        ->and($view['targets'][0]['retry_recovery_kind'])->toBe('reconnect');
});

test('post view hides retry behind billing while the workspace cannot publish', function () {
    config()->set('subscriptions.enabled', true);
    $post = Post::factory()->create(['status' => PostStatus::Failed]);
    $post->workspace()->firstOrFail()->forceFill(['is_initial' => false])->save();
    PostTarget::factory()->for($post)->failed()->create([
        'error_kind' => ErrorKind::BillingRequired->value,
        'error_message' => 'Subscription required.',
    ]);

    $view = PostView::make($post->fresh(['targets.account', 'media']));

    expect($view['targets'][0]['can_retry'])->toBeFalse()
        ->and($view['targets'][0]['retry_blocked_reason'])->toBe('Subscribe to publish this post.')
        ->and($view['targets'][0]['retry_recovery_kind'])->toBe('billing');
});
