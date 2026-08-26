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
        ->and($account->publishingUnavailableReason())->toContain('not enabled');

    config()->set($configKey, true);
    $account->forceFill(['capabilities' => ['oauth_scopes' => []]])->save();

    expect($account->fresh()->canPublish())->toBeFalse()
        ->and($account->fresh()->publishingUnavailableReason())->toContain('Reconnect');

    $account->forceFill(['capabilities' => ['oauth_scopes' => [$scope]]])->save();

    expect($account->fresh()->canPublish())->toBeTrue()
        ->and($account->fresh()->publishingUnavailableReason())->toBeNull();
})->with([
    'TikTok' => [Platform::TikTok, 'services.tiktok.inbox_enabled', 'video.upload'],
    'YouTube' => [Platform::YouTube, 'services.youtube.publishing_enabled', 'https://www.googleapis.com/auth/youtube.upload'],
]);

test('an account needing attention is never publish-ready', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'status' => ConnectedAccountStatus::NeedsAttention,
    ]);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain('Reconnect');
});

test('an account on an instance-disabled platform is never publish-ready', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
    ]);
    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    expect($account->canPublish())->toBeFalse()
        ->and($account->publishingUnavailableReason())->toContain('disabled on this installation');
});
