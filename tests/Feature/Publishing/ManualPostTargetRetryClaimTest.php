<?php

use App\Enums\ErrorKind;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Services\Publishing\ManualPostTargetRetry;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\Bus;

test('manual retry owns the publishing claim before the general dispatcher can inspect it', function (): void {
    $target = publishTarget(status: 'failed');
    $target->forceFill(['error_kind' => ErrorKind::RateLimited])->save();
    Bus::fake();

    $rollup = Mockery::mock(PostStatusRollup::class);
    $rollup->shouldReceive('recompute')->andReturnUsing(function (Post $post): void {
        (new PostStatusRollup)->recompute($post);
        app(PublishDispatcher::class)->dispatchForPost($post);
    });
    app()->instance(PostStatusRollup::class, $rollup);

    $claimed = app(ManualPostTargetRetry::class)->dispatch($target);

    expect($claimed->status)->toBe(PostTargetStatus::Publishing);
    Bus::assertDispatchedTimes(PublishPostTarget::class, 1);
});

test('manual retry queue rollback cannot replace a claim already advanced by a worker', function (PostTargetStatus $status): void {
    $target = publishTarget(status: 'failed');
    $target->forceFill([
        'error_kind' => ErrorKind::RateLimited,
        'error_message' => 'Try later.',
        'attempts' => 2,
    ])->save();

    $dispatcher = Mockery::mock(BusDispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (PublishPostTarget $job) use ($status): never {
        $job->target->fresh()->forceFill([
            'status' => $status,
            'attempts' => 3,
            'media_upload_state' => ['media-1' => ['remote_ref' => 'accepted-operation']],
        ])->save();

        throw new RuntimeException('queue acknowledgement lost');
    });
    app()->instance(BusDispatcher::class, $dispatcher);

    expect(fn () => app(ManualPostTargetRetry::class)->dispatch($target))
        ->toThrow(RuntimeException::class, 'queue acknowledgement lost');

    expect($target->refresh()->status)->toBe($status)
        ->and($target->attempts)->toBe(3)
        ->and($target->error_kind)->toBeNull()
        ->and($target->error_message)->toBeNull()
        ->and(data_get($target->media_upload_state, 'media-1.remote_ref'))->toBe('accepted-operation');
})->with([PostTargetStatus::Publishing, PostTargetStatus::Published]);
