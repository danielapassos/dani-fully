<?php

use App\Enums\Platform;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\ListConnectedAccountsTool;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\User;
use App\Models\Workspace;

test('list_connected_accounts returns accounts in the bound workspace', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    ConnectedAccount::factory()->for($workspace)->create(['handle' => 'acme_co']);

    $response = ShoutrrrServer::actingAs($user)->tool(ListConnectedAccountsTool::class, []);
    $response->assertOk()->assertSee('acme_co');
});

test('MCP distinguishes TikTok app approval from an account sign in and omits credentials', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    config()->set('services.tiktok.publishing_provider', 'accounts_api');
    config()->set('services.tiktok_accounts.approved', false);
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'session' => ['tiktok_accounts' => ['access_token' => 'must-never-leak']],
    ]);

    ShoutrrrServer::actingAs($user)->tool(ListConnectedAccountsTool::class, [])
        ->assertOk()->assertSee('operator_configuration')->assertSee('app approval')->assertDontSee('must-never-leak');
});
