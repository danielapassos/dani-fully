<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;

test('lists connected accounts for the bound workspace only', function () {
    [, $workspace, $token] = issuedKey();
    $mine = ConnectedAccount::factory()->for($workspace)->create();
    $other = ConnectedAccount::factory()->create(); // different workspace

    $response = $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($mine->id)->not->toContain($other->id);
});

test('API shows TikTok approval gates without exposing the separate token', function () {
    [, $workspace, $token] = issuedKey();
    config()->set('services.tiktok.publishing_provider', 'accounts_api');
    config()->set('services.tiktok_accounts.approved', false);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'session' => ['tiktok_accounts' => ['access_token' => 'must-not-leak']],
    ]);
    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertOk()
        ->assertJsonPath('data.0.publishing_ready', false)
        ->assertJsonPath('data.0.publishing_provider', 'accounts_api')
        ->assertJsonPath('data.0.publishing_recovery_kind', 'operator_configuration')
        ->assertDontSee('must-not-leak');
});
