<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Events\PostTargetPublished;
use App\Jobs\PublishPostTarget;
use App\Models\PostTargetAttempt;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

test('successful publish marks the target published with remote ids', function () {
    $target = publishTarget(['one', 'two']);
    bindConnector(PublishResult::success(['111', '222']));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('111')
        ->and($target->remote_ids)->toBe(['111', '222'])
        ->and($target->posted_at)->not->toBeNull();

    expect(PostTargetAttempt::where('post_target_id', $target->id)->where('status', 'published')->count())->toBe(1);
    expect($target->post->refresh()->status)->toBe(PostStatus::Published);
});

test('a completed handoff stops processing without announcing a public post or sending it again', function (string $outcome, array $remoteIds) {
    $target = publishTarget(['one']);
    $target->forceFill([
        'media_upload_state' => ['media-1' => ['remote_ref' => 'upload-operation', 'state' => 'processing']],
        'next_attempt_at' => now()->addMinute(),
    ])->save();
    Bus::fake();
    Event::fake([PostTargetPublished::class]);
    Notification::fake();
    $message = $outcome === 'awaiting_action' ? 'Open TikTok to finish posting; this is not live.' : 'Uploaded privately.';
    bindConnector($outcome === 'awaiting_action'
        ? PublishResult::awaitingAction($remoteIds, $message)
        : PublishResult::completed($remoteIds, $message));

    $job = new PublishPostTarget($target);
    $job->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));

    $target->refresh();
    expect($target->status->value)->toBe($outcome)
        ->and($target->post->refresh()->status->value)->toBe($outcome)
        ->and($target->posted_at)->toBeNull()
        ->and($target->post->published_at)->toBeNull()
        ->and($target->remote_ids)->toBe($remoteIds)
        ->and($target->remote_id)->toBe($remoteIds[0] ?? null)
        ->and(data_get($target->media_upload_state, 'media-1.remote_ref'))->toBe('upload-operation')
        ->and(data_get($target->media_upload_state, 'publication.message'))->toBe($message)
        ->and($target->next_attempt_at)->toBeNull()
        ->and($target->canRetryManually())->toBeFalse();
    expect($target->attemptLogs()->sole()->status)->toBe($outcome);

    bindConnector(fn () => throw new RuntimeException('completed transfers must not run again'));
    $job->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));
    $job->failed(new RuntimeException('stale queue callback'));

    expect($target->fresh()->status->value)->toBe($outcome);
    Notification::assertNothingSent();
    Bus::assertNotDispatched(PublishPostTarget::class);
    Event::assertNotDispatched(PostTargetPublished::class);
})->with([
    'TikTok inbox' => ['awaiting_action', []],
    'private YouTube video' => ['completed', ['video_42']],
    'TikTok without public id' => ['completed', []],
]);

test('legacy confirmed TikTok inbox evidence is reconciled without invoking the connector', function () {
    $target = publishTarget(['one']);
    $target->forceFill([
        'platform' => Platform::TikTok,
        'status' => PostTargetStatus::Publishing,
        'media_upload_state' => ['media-1' => ['remote_ref' => 'legacy-inbox-operation', 'metadata' => ['upload_complete' => true]]],
        'next_attempt_at' => now()->addMinute(),
    ])->save();
    PostTargetAttempt::create([
        'post_target_id' => $target->id,
        'attempt_no' => 1,
        'status' => 'retrying',
        'error_kind' => ErrorKind::MediaProcessing,
        'error_message' => 'Video sent to TikTok. Open TikTok to finish the native post.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    Bus::fake();
    Notification::fake();
    bindConnector(fn () => throw new RuntimeException('must not resend an inbox delivery'));

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($target->refresh()->status)->toBe(PostTargetStatus::AwaitingAction)
        ->and($target->remote_id)->toBeNull()
        ->and(data_get($target->media_upload_state, 'media-1.remote_ref'))->toBe('legacy-inbox-operation')
        ->and($target->next_attempt_at)->toBeNull()
        ->and($target->post->refresh()->status)->toBe(PostStatus::AwaitingAction);
    Bus::assertNotDispatched(PublishPostTarget::class);
    Notification::assertNothingSent();
});

test('a YouTube upload id alone cannot reconcile a dead worker as publicly published', function () {
    $target = publishTarget(['one']);
    $target->forceFill([
        'platform' => Platform::YouTube,
        'status' => PostTargetStatus::Publishing,
        'remote_id' => 'video_42',
        'remote_ids' => ['video_42'],
        'attempts' => 1,
    ])->save();
    Bus::fake();
    Notification::fake();

    (new PublishPostTarget($target))->failed(new RuntimeException('worker died before checking processing and privacy'));

    expect($target->refresh()->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->posted_at)->toBeNull();
    Bus::assertDispatched(PublishPostTarget::class);
    Notification::assertNothingSent();
});

test('a retained TikTok transfer may finish polling with its original upload grant', function (bool $hasReference) {
    config()->set('services.tiktok.direct_post_enabled', true);
    $target = publishTarget(['one']);
    $target->forceFill([
        'platform' => Platform::TikTok,
        'media_upload_state' => $hasReference ? ['media-1' => ['remote_ref' => 'existing-operation']] : null,
    ])->save();
    $target->account()->firstOrFail()->forceFill([
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.upload']],
    ])->save();
    $calls = 0;
    bindConnector(function () use (&$calls): PublishResult {
        $calls++;

        return PublishResult::awaitingAction([], 'Open TikTok to finish posting.');
    });

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($calls)->toBe($hasReference ? 1 : 0)
        ->and($target->refresh()->status)->toBe($hasReference ? PostTargetStatus::AwaitingAction : PostTargetStatus::Skipped);
})->with([true, false]);

test('retryable failure schedules a retry and re-dispatches', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::RateLimited, 'slow', 429));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->next_attempt_at)->not->toBeNull()
        ->and($target->attempts)->toBe(1);

    Bus::assertDispatched(PublishPostTarget::class);
    expect(PostTargetAttempt::where('post_target_id', $target->id)->where('status', 'retrying')->count())->toBe(1);
});

test('rate limited retry honors the provider retry-after delay', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::RateLimited, 'slow', 429, retryAfter: 900));

    Date::setTestNow(now()->startOfSecond());
    $expected = now()->addSeconds(900);

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->next_attempt_at->equalTo($expected))->toBeTrue();

    Bus::assertDispatched(PublishPostTarget::class, function (PublishPostTarget $job): bool {
        return $job->delay === 900;
    });

    Date::setTestNow();
});

test('terminal failure marks the target failed without retry', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::Validation, 'bad', 400));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Validation);

    Bus::assertNotDispatched(PublishPostTarget::class);
    expect($target->post->refresh()->status)->toBe(PostStatus::Failed);
});

test('publish fails immediately when the account already needs attention', function () {
    Bus::fake();
    $target = publishTarget();
    $target->account()->firstOrFail()->forceFill([
        'status' => ConnectedAccountStatus::NeedsAttention->value,
        'refresh_failed_at' => now(),
        'refresh_failure_reason' => 'X rejected the refresh token.',
    ])->save();

    bindConnector(fn () => throw new RuntimeException('connector should not be called'));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::AuthExpired)
        ->and($target->error_message)->toBe('X account needs attention. Reconnect it before publishing.')
        ->and($target->attempts)->toBe(1)
        ->and($target->next_attempt_at)->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->sole();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->error_kind)->toBe(ErrorKind::AuthExpired);

    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('job skips an account that lost publishing readiness after dispatch', function () {
    Bus::fake();
    config()->set('services.youtube.publishing_enabled', true);
    $target = publishTarget();
    $target->forceFill(['platform' => Platform::YouTube->value])->save();
    $target->account()->firstOrFail()->forceFill([
        'platform' => Platform::YouTube->value,
        'capabilities' => ['oauth_scopes' => []],
    ])->save();

    bindConnector(fn () => throw new RuntimeException('connector should not be called'));

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Skipped)
        ->and($target->error_message)->toContain('grant YouTube video upload access')
        ->and($target->attempts)->toBe(0);
    expect(PostTargetAttempt::where('post_target_id', $target->id)->count())->toBe(0);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('auth expired result refreshes credentials once and retries the connector', function () {
    $target = publishTarget();
    $target->account()->firstOrFail()->secret()->firstOrFail()->forceFill([
        'refresh_token' => 'refresh-old',
    ])->save();

    config()->set('services.x.client_id', 'client-id');
    config()->set('services.x.client_secret', 'client-secret');
    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([
            'access_token' => 'fresh-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 7200,
        ]),
    ]);

    $tokens = [];
    bindConnector(function (PublishContext $context) use (&$tokens): PublishResult {
        $tokens[] = $context->credentials['access_token'];

        return count($tokens) === 1
            ? PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401)
            : PublishResult::success(['111']);
    });

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($tokens)->toBe(['tok', 'fresh-token'])
        ->and($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->attempts)->toBe(1)
        ->and($target->account()->firstOrFail()->status)->toBe(ConnectedAccountStatus::Active);

    Http::assertSentCount(1);
});

test('auth expired after the recovery refresh marks the target failed without retrying', function () {
    Bus::fake();
    $target = publishTarget();
    $target->account()->firstOrFail()->secret()->firstOrFail()->forceFill([
        'refresh_token' => 'refresh-old',
    ])->save();
    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([], 400),
    ]);
    bindConnector(PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::AuthExpired)
        ->and($target->error_message)->toStartWith('Token refresh failed for account ')
        ->and($target->attempts)->toBe(1)
        ->and($target->next_attempt_at)->toBeNull();
    expect($target->account()->firstOrFail()->status)->toBe(ConnectedAccountStatus::NeedsAttention);

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->sole();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->attempt_no)->toBe(1)
        ->and($attempt->error_kind)->toBe(ErrorKind::AuthExpired);

    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('a transient refresh failure retries the publish without flipping the account', function () {
    Bus::fake();
    $target = publishTarget();
    $target->account()->firstOrFail()->secret()->firstOrFail()->forceFill([
        'refresh_token' => 'refresh-old',
    ])->save();
    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([], 503),
    ]);
    bindConnector(PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->error_kind)->toBe(ErrorKind::ServerError)
        ->and($target->next_attempt_at)->not->toBeNull();
    expect($target->account()->firstOrFail()->status)->toBe(ConnectedAccountStatus::Active);

    Bus::assertDispatched(PublishPostTarget::class);
});

test('retry stops after five attempts', function () {
    Bus::fake();
    $target = publishTarget();
    $target->forceFill(['attempts' => 4])->save();
    bindConnector(PublishResult::failure(ErrorKind::ServerError, 'boom', 500));

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('an uncaught exception closes the attempt and marks the target failed (never stuck publishing)', function () {
    $target = publishTarget();
    bindConnector(function (): never {
        throw new RuntimeException('boom');
    });

    $job = new PublishPostTarget($target);

    try {
        $job->handle(
            app(PublishConnectorRegistry::class),
            app(TokenManager::class),
            app(PostStatusRollup::class),
            app(BackoffSchedule::class),
        );
    } catch (RuntimeException) {
        // Laravel invokes failed() when the job throws.
        $job->failed(new RuntimeException('boom'));
    }

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Unknown)
        ->and($target->canRetryManually())->toBeFalse()
        ->and($target->error_message)->not->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->latest('id')->first();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->error_kind)->toBe(ErrorKind::Unknown)
        ->and($attempt->finished_at)->not->toBeNull();

    expect($target->post->refresh()->status)->toBe(PostStatus::Failed);
});

test('failed() reconciles a fully-posted target to published (orphaned redelivery)', function () {
    // Simulates the SHOUTRRR-E scenario: a worker died after every segment was
    // posted; the DB queue redelivered the reserved message and tries=1 rejected it
    // as "attempted too many times" before handle() could record success.
    $target = publishTarget(['one', 'two']);
    $target->forceFill([
        'status' => PostTargetStatus::Publishing->value,
        'remote_ids' => ['111', '222'],
        'remote_id' => '111',
    ])->save();

    PostTargetAttempt::create([
        'post_target_id' => $target->id,
        'attempt_no' => 1,
        'status' => 'retrying',
        'started_at' => now(),
    ]);

    (new PublishPostTarget($target->fresh()))->failed(
        new MaxAttemptsExceededException('App\Jobs\PublishPostTarget has been attempted too many times.'),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('111')
        ->and($target->remote_ids)->toBe(['111', '222'])
        ->and($target->posted_at)->not->toBeNull()
        ->and($target->error_message)->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->latest('id')->first();
    expect($attempt->status)->toBe('published')
        ->and($attempt->finished_at)->not->toBeNull();

    expect($target->post->refresh()->status)->toBe(PostStatus::Published);
});

test('failed() resumes a partially-posted thread instead of failing it', function () {
    Bus::fake();
    $target = publishTarget(['one', 'two']);
    $target->forceFill([
        'status' => PostTargetStatus::Publishing->value,
        'attempts' => 1,
        'remote_ids' => ['111'],
        'remote_id' => '111',
    ])->save();

    PostTargetAttempt::create([
        'post_target_id' => $target->id,
        'attempt_no' => 1,
        'status' => 'retrying',
        'started_at' => now(),
    ]);

    (new PublishPostTarget($target->fresh()))->failed(new RuntimeException('worker killed mid-thread'));

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->next_attempt_at)->not->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->latest('id')->first();
    expect($attempt->status)->toBe('retrying')
        ->and($attempt->finished_at)->not->toBeNull();

    Bus::assertDispatched(PublishPostTarget::class);
});

test('failed() gives up on a partial thread once the attempt budget is exhausted', function () {
    Bus::fake();
    $target = publishTarget(['one', 'two']);
    $target->forceFill([
        'status' => PostTargetStatus::Publishing->value,
        'attempts' => 5,
        'remote_ids' => ['111'],
        'remote_id' => '111',
    ])->save();

    (new PublishPostTarget($target->fresh()))->failed(new RuntimeException('still broken'));

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Unknown)
        ->and($target->canRetryManually())->toBeFalse();
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('failed() is a no-op when the target already reached a terminal state', function () {
    Bus::fake();
    // A redelivery can fire up to retry_after after the orphan was created, by which
    // point another path may have deleted the post. failed() must not resurrect it.
    $target = publishTarget(['one', 'two']);
    $target->forceFill([
        'status' => PostTargetStatus::Deleted->value,
        'remote_ids' => ['111', '222'],
        'remote_id' => '111',
    ])->save();

    (new PublishPostTarget($target->fresh()))->failed(
        new MaxAttemptsExceededException('App\Jobs\PublishPostTarget has been attempted too many times.'),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleted);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('a stale failed callback preserves an existing known failure', function () {
    Bus::fake();
    $target = publishTarget(['one']);
    $target->forceFill([
        'status' => PostTargetStatus::Failed->value,
        'error_kind' => ErrorKind::RateLimited->value,
        'error_message' => 'Try later.',
    ])->save();

    (new PublishPostTarget($target->fresh()))->failed(new RuntimeException('stale worker callback'));

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::RateLimited)
        ->and($target->error_message)->toBe('Try later.');
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('failed() with no posted segments marks the target failed', function () {
    $target = publishTarget(['one']);
    $target->forceFill(['status' => PostTargetStatus::Publishing->value])->save();

    (new PublishPostTarget($target->fresh()))->failed(new RuntimeException('boom'));

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Unknown)
        ->and($target->canRetryManually())->toBeFalse()
        ->and($target->error_message)->not->toBeNull();
});

test('job has tries=1 and a timeout below the queue retry_after', function () {
    $target = publishTarget();
    $job = new PublishPostTarget($target);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(900)
        ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe($target->id);

    // Invariant: the job timeout MUST stay below the queue connection's retry_after,
    // or a slow large-video run is released to a second worker mid-upload and double-posts.
    expect($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

test('async publish jobs discard overlapping mutations for the same target', function () {
    config()->set('queue.default', 'database');
    $target = publishTarget();
    $job = new PublishPostTarget($target);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe("publish-post-target:{$target->id}")
        ->and($middleware[0]->releaseAfter)->toBeNull()
        ->and($middleware[0]->expiresAfter)->toBe(960);
});

test('a stale job cannot implicitly retry a failed target', function () {
    $target = publishTarget(status: 'failed');

    bindConnector(fn () => throw new RuntimeException('connector must not be called'));

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->fresh()->attempts)->toBe(0);
    expect(PostTargetAttempt::where('post_target_id', $target->id)->count())->toBe(0);
});

test('handle is a no-op on a terminal published target (stale retry / double dispatch)', function () {
    $target = publishTarget(status: 'published');
    $target->forceFill(['remote_id' => 'rid', 'remote_ids' => ['rid']])->save();

    bindConnector(function (): never {
        throw new RuntimeException('connector must not be called');
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('rid')
        ->and($target->attempts)->toBe(0);

    expect(PostTargetAttempt::where('post_target_id', $target->id)->count())->toBe(0);
});

test('thread resumption passes already-posted ids to the connector', function () {
    $target = publishTarget(['one', 'two']);
    $target->forceFill(['remote_ids' => ['111']])->save();

    $seen = null;
    bindConnector(function (PublishContext $context) use (&$seen): PublishResult {
        $seen = $context->target->remote_ids;

        return PublishResult::success(['111', '222']);
    });

    (new PublishPostTarget($target->fresh()))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($seen)->toBe(['111']);
});
