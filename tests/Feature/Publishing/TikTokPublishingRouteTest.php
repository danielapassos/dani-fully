<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\ConnectedAccounts\TikTok\TikTokCreatorInfo;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\Metricool\MetricoolClient;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TikTokPublishingRoute;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function metricoolRouteAccount(): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok,
        'handle' => '@a-creator',
        'capabilities' => ['oauth_scopes' => ['user.info.basic']],
    ]);
    config()->set('services.metricool', [
        'token' => 'server-only-metricool-token',
        'user_id' => '12345',
        'workspace_id' => $account->workspace_id,
        'accounts' => [$account->id => '67890'],
    ]);

    return $account;
}

function metricoolRouteTarget(ConnectedAccount $account, array $state = []): PostTarget
{
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);

    return PostTarget::factory()->for($post)->create([
        'platform' => Platform::TikTok,
        'connected_account_id' => $account->id,
        'status' => PostTargetStatus::Pending,
        'sections' => ['Approved video caption'],
        'media_upload_state' => $state,
    ]);
}

beforeEach(function () {
    config()->set('services.tiktok.publishing_provider', 'metricool');
    config()->set('services.tiktok.direct_post_enabled', false);
    config()->set('services.tiktok.inbox_enabled', false);
    Http::preventStrayRequests();
    Notification::fake();
    Bus::fake();
});

test('Metricool readiness uses the exact workspace and account mapping without native publishing scopes or requests', function () {
    $account = metricoolRouteAccount();
    $account->forceFill(['status' => ConnectedAccountStatus::NeedsAttention])->save();

    expect($account->canPublish())->toBeTrue()
        ->and($account->requiredPublishingScopes())->toBe([])
        ->and($account->publishingPermissionStatus())->toBe('not_required')
        ->and($account->publishingUnavailableReason())->toBeNull()
        ->and($account->publishingRecoveryKind())->toBeNull();

    config()->set('services.metricool.workspace_id', 'a-different-workspace');
    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain('this workspace')
        ->and($account->publishingRecoveryKind())->toBe('operator_configuration');
    Http::assertNothingSent();
});

test('incomplete Metricool configuration blocks publishing without directing native reconnects', function (string $key, mixed $value) {
    $account = metricoolRouteAccount();
    config()->set('services.metricool.'.$key, $value);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingRecoveryKind())->toBe('operator_configuration')
        ->and($account->publishingUnavailableReason())->not->toContain('Reconnect');
})->with([
    'missing token' => ['token', ''],
    'missing user' => ['user_id', null],
    'negative user' => ['user_id', '-1'],
    'missing mapping' => ['accounts', []],
    'invalid mapping' => ['accounts', 'invalid-json'],
]);

test('disabled TikTok accounts remain blocked with the configured Metricool route', function () {
    $account = metricoolRouteAccount();
    $account->forceFill(['disabled_at' => now()])->save();

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingRecoveryKind())->toBe('enable_account');
});

test('new submissions pin their provider and never follow a later provider setting change', function () {
    $target = metricoolRouteTarget(metricoolRouteAccount());
    $route = app(TikTokPublishingRoute::class);
    expect($route->pin($target))->toBe('metricool');

    config()->set('services.tiktok.publishing_provider', 'native');
    expect($route->forTarget($target->fresh()))->toBe('metricool')
        ->and($target->fresh()->media_upload_state['_tiktok_provider'])->toBe('metricool');
});

test('legacy native submissions including lost responses are never rerouted to Metricool', function (array $state) {
    $target = metricoolRouteTarget(metricoolRouteAccount(), $state);
    expect(app(TikTokPublishingRoute::class)->pin($target))->toBe('native')
        ->and($target->fresh()->media_upload_state['_tiktok_provider'])->toBe('native');
})->with([
    'inbox reference' => [['media-1' => ['remote_ref' => 'inbox-operation', 'metadata' => ['publish_mode' => 'inbox']]]],
    'direct reference' => [['media-1' => ['remote_ref' => 'direct-operation', 'metadata' => ['publish_mode' => 'direct']]]],
    'lost init response' => [['media-1' => ['metadata' => ['init_outcome_unknown' => true]]]],
    'pending native setup' => [['media-1' => ['metadata' => ['publish_mode' => 'direct']]]],
]);

test('saved Metricool operations stay Metricool without relying on the current account map', function () {
    $target = metricoolRouteTarget(metricoolRouteAccount(), ['_metricool' => ['post_id' => 987, 'blog_id' => 67890]]);
    config()->set('services.tiktok.publishing_provider', 'native');
    config()->set('services.metricool.accounts', []);

    expect(app(TikTokPublishingRoute::class)->pin($target))->toBe('metricool')
        ->and($target->fresh()->media_upload_state['_metricool']['post_id'])->toBe(987);
});

test('mixed or unrecognized saved provider state fails closed', function (array $state) {
    $target = metricoolRouteTarget(metricoolRouteAccount(), $state);
    expect(fn () => app(TikTokPublishingRoute::class)->pin($target))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([
    'unknown pin' => [['_tiktok_provider' => 'other-service']],
    'mixed operations' => [['_metricool' => ['post_id' => 987], 'media-1' => ['remote_ref' => 'native-operation']]],
    'native pin with bridge state' => [['_tiktok_provider' => 'native', '_metricool' => ['post_id' => 987]]],
    'bridge pin with ambiguous native init' => [['_tiktok_provider' => 'metricool', 'media-1' => ['metadata' => ['init_outcome_unknown' => true]]]],
]);

test('Metricool job publishing skips native credentials even when native OAuth needs attention', function () {
    $account = metricoolRouteAccount();
    $account->forceFill(['status' => ConnectedAccountStatus::NeedsAttention])->save();
    $target = metricoolRouteTarget($account);
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    bindConnector(function (PublishContext $context): PublishResult {
        expect($context->credentials)->toBe([])
            ->and($context->target->media_upload_state['_tiktok_provider'])->toBe('metricool');

        return PublishResult::success(['real-tiktok-video-id']);
    });

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($target->fresh()->remote_id)->toBe('real-tiktok-video-id');
    Http::assertNothingSent();
});

test('Metricool authentication failure never refreshes or invalidates native TikTok OAuth', function () {
    $account = metricoolRouteAccount();
    $target = metricoolRouteTarget($account);
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    $calls = 0;
    bindConnector(function () use (&$calls): PublishResult {
        $calls++;

        return PublishResult::failure(ErrorKind::AuthExpired, 'Upstream authentication rejected.', 401);
    });

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($calls)->toBe(1)
        ->and($account->fresh()->status)->toBe(ConnectedAccountStatus::Active)
        ->and($target->fresh()->error_kind)->toBe(ErrorKind::Unsupported)
        ->and($target->fresh()->error_message)->toContain('Metricool');
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('an existing bridge operation can be polled after installation provider changes', function () {
    $account = metricoolRouteAccount();
    $target = metricoolRouteTarget($account, ['_tiktok_provider' => 'metricool', '_metricool' => ['post_id' => 987]]);
    config()->set('services.tiktok.publishing_provider', 'native');
    config()->set('services.metricool.accounts', []);
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    bindConnector(PublishResult::failure(ErrorKind::MediaProcessing, 'Metricool is processing the video.', retryAfter: 30));

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($target->fresh()->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->fresh()->media_upload_state['_metricool']['post_id'])->toBe(987)
        ->and($target->fresh()->attempts)->toBe(0);
    Bus::assertDispatched(PublishPostTarget::class);
});

test('composer creator information comes from the selected bridge without native token access', function () {
    $account = metricoolRouteAccount();
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    app()->instance(TokenManager::class, $tokens);
    $creator = [
        'creator_username' => 'a-creator',
        'creator_nickname' => 'Creator',
        'creator_avatar_url' => null,
        'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
        'comment_disabled' => false,
        'duet_disabled' => true,
        'stitch_disabled' => true,
        'max_video_post_duration_sec' => 600,
    ];
    $client = Mockery::mock(MetricoolClient::class);
    $client->shouldReceive('creatorInfo')->once()->with($account)->andReturn($creator);
    app()->instance(MetricoolClient::class, $client);

    expect(app(TikTokCreatorInfo::class)->query($account))->toBe($creator);
    Http::assertNothingSent();
});

test('native transfers still read native creator restrictions after switching the default to Metricool', function () {
    $account = metricoolRouteAccount();
    $client = Mockery::mock(MetricoolClient::class);
    $client->shouldNotReceive('creatorInfo');
    app()->instance(MetricoolClient::class, $client);
    Http::fake(['*/creator_info/query/' => Http::response([
        'error' => ['code' => 'ok'],
        'data' => [
            'creator_username' => 'native-creator', 'creator_nickname' => 'Native creator',
            'privacy_level_options' => ['SELF_ONLY'], 'comment_disabled' => false,
            'duet_disabled' => true, 'stitch_disabled' => true, 'max_video_post_duration_sec' => 600,
        ],
    ])]);

    expect(app(TikTokCreatorInfo::class)->query($account, 'native-transfer-token')['privacy_level_options'])->toBe(['SELF_ONLY']);
    Http::assertSentCount(1);
});

test('disabling publishing does not abandon read-only reconciliation of an accepted bridge operation', function (array $operation) {
    $account = metricoolRouteAccount();
    $target = metricoolRouteTarget($account, ['_tiktok_provider' => 'metricool', '_metricool' => $operation]);
    $account->forceFill(['disabled_at' => now()])->save();
    $settings = Mockery::mock(InstanceSettings::class)->makePartial();
    $settings->shouldReceive('platformAvailable')->andReturn(false);
    app()->instance(InstanceSettings::class, $settings);
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    bindConnector(PublishResult::failure(ErrorKind::MediaProcessing, 'Checking the accepted operation.', retryAfter: 30));

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($target->fresh()->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->fresh()->remote_id)->toBeNull();
    Bus::assertDispatched(PublishPostTarget::class);
})->with([
    'accepted operation' => [['post_id' => 987]],
    'submission result uncertain' => [['create_outcome_unknown' => true, 'uuid' => 'saved-operation']],
]);

test('disabling a staged bridge attempt still prevents its first submission', function () {
    $account = metricoolRouteAccount();
    $target = metricoolRouteTarget($account, ['_tiktok_provider' => 'metricool', '_metricool' => ['uuid' => 'staged-media', 'create_outcome_unknown' => false]]);
    $account->forceFill(['disabled_at' => now()])->save();
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    bindConnector(fn () => throw new RuntimeException('A disabled account cannot submit a staged post.'));

    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));

    expect($target->fresh()->status)->toBe(PostTargetStatus::Skipped);
    Bus::assertNotDispatched(PublishPostTarget::class);
});
