<?php

declare(strict_types=1);

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Exceptions\PostTargetRetryRejected;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetAttempt;
use App\Services\Publishing\TikTokAccounts\TikTokAccountsClient;
use App\Services\Publishing\TikTokAccountsRecovery;
use App\Services\Publishing\TikTokPublishingRoute;
use Illuminate\Bus\UniqueLock;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Http::preventStrayRequests();
    config(['services.tiktok.publishing_provider' => 'accounts_api']);
    $this->route = Mockery::mock(TikTokPublishingRoute::class)->makePartial();
    $this->route->shouldReceive('unavailableReason')->andReturnNull()->byDefault();
    app()->instance(TikTokPublishingRoute::class, $this->route);
    $this->client = Mockery::mock(TikTokAccountsClient::class);
    app()->instance(TikTokAccountsClient::class, $this->client);
});

/** @return array{PostTarget, PostMedia, PostTargetAttempt} */
function rejectedNativeTikTokTarget(): array
{
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $post->workspace_id, 'platform' => Platform::TikTok,
        'handle' => '@reviewed_creator', 'remote_account_id' => 'native-open-id',
    ]);
    $target = PostTarget::factory()->failed()->create([
        'post_id' => $post->id, 'connected_account_id' => $account->id, 'platform' => Platform::TikTok,
        'sections' => ['The original reviewed caption #travel'],
        'content_override' => ['tiktok' => [
            'privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_comment' => false,
            'disable_duet' => true, 'disable_stitch' => true,
        ]],
        'error_kind' => ErrorKind::Unsupported,
        'error_message' => 'TikTok has not approved this developer app for public Direct Post.',
    ]);
    $media = PostMedia::factory()->video()->create([
        'post_id' => $post->id, 'workspace_id' => $post->workspace_id,
        'path' => 'media/original-video.mp4', 'size_bytes' => 10,
    ]);
    Storage::disk('public')->put($media->path, 'video-file');
    $attempt = PostTargetAttempt::factory()->create([
        'post_target_id' => $target->id, 'status' => 'failed', 'error_kind' => ErrorKind::Unsupported,
        'http_status' => 403,
        'response_excerpt' => json_encode([
            'code' => TikTokAccountsRecovery::REJECTION_CODE, 'log_id' => '202609220001TEST',
        ], JSON_THROW_ON_ERROR),
    ]);
    $target->forceFill(['media_upload_state' => [
        '_tiktok_provider' => 'native',
        $media->id => ['metadata' => [
            'publish_mode' => 'direct', 'source' => 'PULL_FROM_URL', 'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'post_options' => $target->content_override['tiktok'],
            'init_rejection' => [
                ...TikTokAccountsRecovery::snapshot(new PublishContext($target, $target->sections, [$media], $account, []), $media),
                'code' => TikTokAccountsRecovery::REJECTION_CODE, 'http_status' => 403, 'log_id' => '202609220001TEST',
            ],
        ]],
    ]])->save();

    return [$target->fresh(), $media->fresh(), $attempt];
}

test('explicit recovery preserves the exact failed target and audits its state without dispatching', function () {
    [$target, $media, $attempt] = rejectedNativeTikTokTarget();
    $original = $target->getAttributes();
    $state = $target->media_upload_state;
    $this->client->shouldReceive('binding')->once()
        ->with(Mockery::on(fn (ConnectedAccount $account): bool => $account->id === $target->connected_account_id))
        ->andReturn([
            'open_id' => 'accounts-open-id', 'client_id' => 'approved-app', 'expected_handle' => 'reviewed_creator',
            'creator' => ['privacy_level_options' => ['PUBLIC_TO_EVERYONE']],
        ]);
    Bus::fake();

    $this->artisan('tiktok:recover-accounts', ['target' => $target->id])
        ->expectsOutputToContain('Nothing was published or queued.')->assertSuccessful();

    $target->refresh();
    expect($target->media_upload_state['_tiktok_provider'])->toBe('accounts_api')
        ->and($target->media_upload_state['_tiktok_accounts_recovery']['previous_media_upload_state'])->toBe($state)
        ->and($target->media_upload_state['_tiktok_accounts_recovery']['attempt_id'])->toBe($attempt->id)
        ->and($target->media_upload_state['_tiktok_accounts_recovery']['accounts_open_id'])->toBe('accounts-open-id')
        ->and($target->media_upload_state['_tiktok_accounts']['open_id'])->toBe('accounts-open-id')
        ->and($target->media_upload_state['_tiktok_accounts']['client_id'])->toBe('approved-app')
        ->and($target->media_upload_state['_tiktok_accounts']['caption'])->toBe($target->sections[0])
        ->and($target->media_upload_state['_tiktok_accounts']['post_options'])->toBe($target->content_override['tiktok'])
        ->and($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->attemptLogs()->count())->toBe(1)
        ->and($media->fresh()->getAttributes())->toBe($media->getAttributes());
    foreach (['id', 'post_id', 'connected_account_id', 'sections', 'content_override', 'error_kind', 'error_message', 'attempts', 'remote_id', 'remote_ids'] as $field) {
        expect($target->getRawOriginal($field))->toBe($original[$field]);
    }
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

test('legacy error text alone never authorizes recovery', function () {
    [$target, $media] = rejectedNativeTikTokTarget();
    $state = $target->media_upload_state;
    unset($state[$media->id]['metadata']['init_rejection']);
    $target->update(['media_upload_state' => $state]);

    $this->artisan('tiktok:recover-accounts', ['target' => $target->id])
        ->expectsOutputToContain('lacks sufficient evidence')->assertFailed();

    expect($target->fresh()->media_upload_state)->toBe($state);
});

test('recovery refuses an unproven or unfinished attempt', function (array $changes) {
    [$target, $media, $attempt] = rejectedNativeTikTokTarget();
    $attempt->update($changes);
    $before = $target->getAttributes();

    expect(fn () => app(TikTokAccountsRecovery::class)->recover($target))->toThrow(PostTargetRetryRejected::class);
    expect($target->fresh()->getAttributes())->toBe($before);
})->with([
    'unfinished' => [['finished_at' => null]],
    'active' => [['status' => 'publishing']],
    'server error' => [['http_status' => 503]],
    'unknown outcome' => [['error_kind' => ErrorKind::Unknown]],
    'wrong rejection' => [['response_excerpt' => '{"code":"spam_risk"}']],
    'missing provider request log' => [['response_excerpt' => '{"code":"unaudited_client_can_only_post_to_private_accounts"}']],
    'wrong attempt number' => [['attempt_no' => 2]],
]);

test('recovery does not discard an older uncertain attempt', function () {
    [$target] = rejectedNativeTikTokTarget();
    PostTargetAttempt::factory()->create([
        'post_target_id' => $target->id, 'attempt_no' => 0, 'status' => 'failed', 'error_kind' => ErrorKind::Unknown,
    ]);

    expect(fn () => app(TikTokAccountsRecovery::class)->recover($target))->toThrow(PostTargetRetryRejected::class);
});

test('recovery refuses accepted uncertain or extra saved publishing state', function (string $field, mixed $value) {
    [$target, $media] = rejectedNativeTikTokTarget();
    $state = $target->media_upload_state;
    data_set($state, str_replace('MEDIA', $media->id, $field), $value);
    $target->update(['media_upload_state' => $state]);

    expect(fn () => app(TikTokAccountsRecovery::class)->recover($target))->toThrow(RuntimeException::class);
    expect($target->fresh()->media_upload_state)->toBe($state);
})->with([
    'accepted native publish id' => ['MEDIA.remote_ref', 'accepted-id'],
    'unknown initialization' => ['MEDIA.metadata.init_outcome_unknown', true],
    'provider processing status' => ['MEDIA.metadata.provider_status', 'PUBLISH_COMPLETE'],
    'other native media operation' => ['another-media.remote_ref', 'another-id'],
    'accepted Accounts operation' => ['_tiktok_accounts.publish_id', 'accepted-id'],
    'accepted Metricool operation' => ['_metricool.post_id', 'accepted-id'],
    'inbox state' => ['publication.status', 'awaiting_action'],
]);

test('recovery rejects nonterminal target or publication evidence', function (array $changes) {
    [$target] = rejectedNativeTikTokTarget();
    $target->update($changes);
    $before = $target->getAttributes();

    expect(fn () => app(TikTokAccountsRecovery::class)->recover($target))->toThrow(PostTargetRetryRejected::class);
    expect($target->fresh()->getAttributes())->toBe($before);
})->with([
    'publishing' => [['status' => PostTargetStatus::Publishing]],
    'unknown result' => [['error_kind' => ErrorKind::Unknown]],
    'scheduled retry' => [['next_attempt_at' => '2030-01-01 00:00:00']],
    'published identifier' => [['remote_id' => '1234567890']],
    'published identifiers' => [['remote_ids' => ['1234567890']]],
    'reposted identifier' => [['repost_remote_id' => '1234567890']],
]);

test('recovery rejects changed original caption account media or options', function (string $change) {
    [$target, $media] = rejectedNativeTikTokTarget();
    match ($change) {
        'caption' => $target->update(['sections' => ['A different caption']]),
        'options' => $target->update(['content_override' => ['tiktok' => ['privacy_level' => 'SELF_ONLY']]]),
        'account' => $target->account->update(['remote_account_id' => 'another-native-account']),
        'handle' => $target->account->update(['handle' => '@another_creator']),
        'media' => $media->update(['path' => 'media/replaced-video.mp4']),
        'selection' => $target->update(['placements_explicit' => true]),
    };
    $before = $target->fresh()->getAttributes();

    expect(fn () => app(TikTokAccountsRecovery::class)->recover($target))->toThrow(PostTargetRetryRejected::class);
    expect($target->fresh()->getAttributes())->toBe($before);
})->with(['caption', 'options', 'account', 'handle', 'media', 'selection']);

test('recovery requires the original video file', function (bool $missing) {
    [$target, $media] = rejectedNativeTikTokTarget();
    if ($missing) {
        Storage::disk('public')->delete($media->path);
    } else {
        Storage::disk('public')->put($media->path, 'changed-size');
    }

    expect(fn () => app(TikTokAccountsRecovery::class)->recover($target))->toThrow(PostTargetRetryRejected::class, 'original video file');
})->with([true, false]);

test('recovery requires the selected installation to be approved and configured', function (bool $selected) {
    [$target] = rejectedNativeTikTokTarget();
    if ($selected) {
        $this->route->shouldReceive('unavailableReason')->once()->andReturn('Accounts API application approval is required.');
    } else {
        config(['services.tiktok.publishing_provider' => 'native']);
    }

    expect(fn () => app(TikTokAccountsRecovery::class)->recover($target))->toThrow(PostTargetRetryRejected::class);
    expect($target->fresh()->media_upload_state['_tiktok_provider'])->toBe('native');
})->with([true, false]);

test('recovery requires live binding and public capability', function (bool $bindingFailure) {
    [$target] = rejectedNativeTikTokTarget();
    $before = $target->getAttributes();
    if ($bindingFailure) {
        $this->client->shouldReceive('binding')->once()->andThrow(new RuntimeException('Binding did not match'));
    } else {
        $this->client->shouldReceive('binding')->once()->andReturn([
            'open_id' => 'accounts-open-id', 'creator' => ['privacy_level_options' => ['SELF_ONLY']],
        ]);
    }

    $this->artisan('tiktok:recover-accounts', ['target' => $target->id])->assertFailed();
    expect($target->fresh()->getAttributes())->toBe($before);
})->with([true, false]);

test('recovery respects active unique and publishing locks without releasing their owner', function (bool $queued) {
    [$target] = rejectedNativeTikTokTarget();
    $job = new PublishPostTarget($target);
    $key = $queued ? UniqueLock::getKey($job) : (new WithoutOverlapping('publish-post-target:'.$target->id))->getLockKey($job);
    $lock = Cache::lock($key, 180);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => app(TikTokAccountsRecovery::class)->recover($target))->toThrow(PostTargetRetryRejected::class);
        expect(Cache::lock($key, 180)->get())->toBeFalse();
        expect($target->fresh()->media_upload_state['_tiktok_provider'])->toBe('native');
    } finally {
        $lock->release();
    }
})->with([true, false]);

test('native audit failure remains an operator gate when the installation provider changes', function (string $message) {
    [$target] = rejectedNativeTikTokTarget();
    $target->update(['error_message' => $message]);

    expect($target->canRetryManually())->toBeFalse()
        ->and($target->manualRetryRecoveryKind())->toBe('operator_configuration')
        ->and($target->manualRetryBlockedReason())->toStartWith('App approval required; signing in again will not fix this');
})->with([
    TikTokAccountsRecovery::REJECTION_CODE,
    'TikTok has not approved this app for public Direct Post.',
    'TikTok has not approved this developer app for public Direct Post.',
]);

test('unknown target recovery returns a failure without dispatching', function () {
    Bus::fake();
    $this->artisan('tiktok:recover-accounts', ['target' => 'missing-target'])
        ->expectsOutputToContain('does not exist')->assertFailed();
    Bus::assertNothingDispatched();
});
