<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Support\InstanceSettings;

test('a provider upload flag and granted scope are both required for video publishing', function (
    Platform $platform,
    string $configKey,
    string $scope,
) {
    config()->set($configKey, false);
    $account = ConnectedAccount::factory()->create([
        'platform' => $platform,
        'capabilities' => ['oauth_scopes' => [$scope]],
    ]);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain('not enabled')
        ->and($account->publishingRecoveryKind())->toBe('operator_configuration');

    config()->set($configKey, true);
    $account->forceFill(['capabilities' => ['oauth_scopes' => []]])->save();

    expect($account->fresh()->canPublish())->toBeFalse()
        ->and($account->fresh()->publishingUnavailableReason())->toContain('Reconnect')
        ->and($account->fresh()->publishingRecoveryKind())->toBe('reconnect');

    $account->forceFill(['capabilities' => ['oauth_scopes' => [$scope]]])->save();

    expect($account->fresh()->canPublish())->toBeTrue()
        ->and($account->fresh()->publishingUnavailableReason())->toBeNull()
        ->and($account->fresh()->publishingRecoveryKind())->toBeNull();
})->with([
    'TikTok' => [Platform::TikTok, 'services.tiktok.inbox_enabled', 'video.upload'],
    'TikTok Direct Post' => [Platform::TikTok, 'services.tiktok.direct_post_enabled', 'video.publish'],
    'YouTube' => [Platform::YouTube, 'services.youtube.publishing_enabled', 'https://www.googleapis.com/auth/youtube.upload'],
]);

test('TikTok inbox permission does not claim direct post readiness', function () {
    config()->set('services.tiktok.direct_post_enabled', true);
    config()->set('services.tiktok.inbox_enabled', true);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.upload']],
    ]);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain('Direct Post')
        ->and($account->publishingRecoveryKind())->toBe('reconnect');
});

test('an account needing attention is never publish-ready', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'status' => ConnectedAccountStatus::NeedsAttention,
    ]);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain('Reconnect')
        ->and($account->publishingRecoveryKind())->toBe('reconnect');
});

test('an account on an instance-disabled platform is never publish-ready', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
    ]);
    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain('disabled on this installation')
        ->and($account->publishingRecoveryKind())->toBe('operator_configuration');
});

test('confirmed publishing grants are checked for the selected account type', function (Platform $platform, array $accountCapabilities, array $required) {
    $account = ConnectedAccount::factory()->make([
        'platform' => $platform,
        'capabilities' => [...$accountCapabilities, 'oauth_scopes_verified' => true, 'oauth_scopes' => $required],
    ]);

    expect($account->requiredPublishingScopes())->toBe($required)
        ->and($account->publishingPermissionStatus())->toBe('granted')
        ->and($account->canPublish())->toBeTrue()
        ->and($account->publishingUnavailableReason())->toBeNull();

    $missing = array_pop($required);
    $account->capabilities = [...$accountCapabilities, 'oauth_scopes_verified' => true, 'oauth_scopes' => $required];

    expect($account->publishingPermissionStatus())->toBe('missing')
        ->and($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain($missing)
        ->and($account->publishingRecoveryKind())->toBe('reconnect');
})->with([
    'X' => [Platform::X, [], ['tweet.read', 'users.read', 'tweet.write']],
    'Instagram Login' => [Platform::Instagram, ['instagram_login' => true], ['instagram_business_basic', 'instagram_business_content_publish']],
    'Instagram linked Page' => [Platform::Instagram, [], ['instagram_basic', 'instagram_content_publish', 'pages_read_engagement']],
    'Facebook Page' => [Platform::Facebook, [], ['pages_read_engagement', 'pages_manage_posts']],
    'Threads' => [Platform::Threads, [], ['threads_basic', 'threads_content_publish']],
    'LinkedIn member' => [Platform::LinkedIn, [], ['w_member_social']],
    'LinkedIn organization' => [Platform::LinkedIn, ['linkedin_account_type' => 'organization'], ['w_organization_social']],
]);

test('unknown historical grants do not disable existing publishing connections', function (Platform $platform) {
    $account = ConnectedAccount::factory()->make(['platform' => $platform, 'capabilities' => null]);

    expect($account->publishingPermissionStatus())->toBe('unknown')
        ->and($account->canPublish())->toBeTrue()
        ->and($account->publishingUnavailableReason())->toBeNull()
        ->and($account->publishingRecoveryKind())->toBeNull();

    $account->capabilities = ['oauth_scopes' => [], 'oauth_scopes_verified' => false];

    expect($account->publishingPermissionStatus())->toBe('unknown')
        ->and($account->canPublish())->toBeTrue();
})->with([Platform::X, Platform::Instagram, Platform::Threads, Platform::Facebook, Platform::LinkedIn]);

test('legacy instagram requested scopes do not become verified grants', function () {
    $account = ConnectedAccount::factory()->make([
        'platform' => Platform::Instagram,
        'capabilities' => ['instagram_login' => true, 'oauth_scopes' => ['instagram_business_basic', 'instagram_business_content_publish']],
    ]);

    expect($account->publishingPermissionStatus())->toBe('unknown')
        ->and($account->canPublish())->toBeTrue();
});

test('linkedin publishing grants cannot cross between members and organizations', function (bool $organization) {
    $account = ConnectedAccount::factory()->make([
        'platform' => Platform::LinkedIn,
        'capabilities' => [
            'linkedin_account_type' => $organization ? 'organization' : 'person',
            'oauth_scopes_verified' => true,
            'oauth_scopes' => [$organization ? 'w_member_social' : 'w_organization_social'],
        ],
    ]);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingPermissionStatus())->toBe('missing');
})->with([true, false]);

test('app password and webhook connections do not require OAuth grant metadata', function (Platform $platform) {
    $account = ConnectedAccount::factory()->make([
        'platform' => $platform,
        'capabilities' => ['oauth_scopes' => [], 'oauth_scopes_verified' => true],
    ]);

    expect($account->publishingPermissionStatus())->toBe('not_required')
        ->and($account->canPublish())->toBeTrue();
})->with([Platform::Bluesky, Platform::Discord]);
