<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\Publishing\AccountPublishingReadiness;
use Illuminate\Support\Facades\Http;

test('saved readiness is explicitly distinct from live account and browser checks', function (): void {
    Http::preventStrayRequests();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'capabilities' => null]);

    $view = app(AccountPublishingReadiness::class)->view($account);

    expect($account->canPublish())->toBeTrue()
        ->and($view['publishing_readiness'])->toMatchArray([
            'basis' => 'stored_configuration_and_credentials', 'provider_verified' => false,
            'provider_checked_at' => null, 'permission_status' => 'unknown', 'browser_session_verified' => false,
        ]);
    Http::assertNothingSent();
});

test('reports music implementation limitations per destination without claiming native music was attached', function (Platform $platform, array $capabilities, string $message): void {
    $account = ConnectedAccount::factory()->create(['platform' => $platform, 'capabilities' => $capabilities]);
    $music = app(AccountPublishingReadiness::class)->view($account)['music_capabilities'];

    expect($music)->toMatchArray(['native_catalog_search' => false, 'native_track_attachment' => false, 'existing_file_audio' => true])
        ->and($music['message'])->toContain($message)
        ->and($music['license_note'])->toContain('not licensed for reuse');
})->with([
    'TikTok' => [Platform::TikTok, [], 'TikTok'],
    'Instagram Login' => [Platform::Instagram, ['instagram_login' => true], 'does not support the Instagram Audio API'],
    'Facebook Login' => [Platform::Instagram, [], 'does not implement Instagram Audio API'],
    'YouTube' => [Platform::YouTube, [], 'eligible YouTube tools'],
]);
