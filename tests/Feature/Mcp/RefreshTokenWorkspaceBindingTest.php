<?php

use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\ListPostsTool;
use App\Models\McpGrantWorkspace;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys', ['--no-interaction' => true]);
    }
});

/**
 * Exercise consent and PKCE code exchange rather than manufacturing a token or
 * dispatching token events: refresh lineage depends on Passport's real ordering.
 *
 * @return array{0: User, 1: Workspace, 2: Client, 3: array<string, mixed>}
 */
function issuedMcpRefreshGrant(string $scope = 'mcp:use read write'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->owner()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $redirect = 'http://127.0.0.1:1455/callback';
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'MCP refresh test', [$redirect], confidential: false, user: $user,
    );
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    test()->actingAs($user);
    test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => $scope,
        'state' => 'refresh-test',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();
    $approval = test()->post('/oauth/authorize', [
        'client_id' => $client->getKey(),
        'workspace_id' => $workspace->id,
        'auth_token' => session('authToken'),
    ])->assertRedirect();
    parse_str((string) parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toHaveKeys(['code', 'state']);

    $tokens = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirect,
        'code' => $query['code'],
        'code_verifier' => $verifier,
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json();

    expect(McpGrantWorkspace::where('access_token_id', mcpRefreshTokenId($tokens))->value('workspace_id'))
        ->toBe($workspace->id);

    return [$user, $workspace, $client, $tokens];
}

/** @param array<string, mixed> $tokens */
function mcpRefreshTokenId(array $tokens): string
{
    $payload = explode('.', $tokens['access_token'])[1];
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);

    return $claims['jti'];
}

/**
 * @param  array<string, mixed>  $tokens
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function refreshMcpGrant(Client $client, array $tokens, array $overrides = []): array
{
    return test()->postJson('/oauth/token', array_replace([
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'refresh_token' => $tokens['refresh_token'],
    ], $overrides))->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json();
}

/**
 * @param  array<string, mixed>  $tokens
 * @param  class-string<Tool>  $tool
 * @param  array<string, mixed>  $arguments
 */
function callMcpWithRefreshedToken(array $tokens, string $tool, array $arguments = []): TestResponse
{
    Auth::forgetGuards();

    return test()->withToken($tokens['access_token'])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => app($tool)->name(), 'arguments' => (object) $arguments],
    ]);
}

test('two real refresh rotations preserve the original workspace and pending consent', function (): void {
    [$user, $workspace, $client, $tokens] = issuedMcpRefreshGrant();
    $other = Workspace::factory()->create();
    WorkspaceMembership::factory()->owner()->create(['user_id' => $user->id, 'workspace_id' => $other->id]);
    $pending = McpGrantWorkspace::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'workspace_id' => $other->id,
        'access_token_id' => null,
    ]);
    Post::factory()->for($workspace)->create(['base_text' => 'Original workspace post']);
    Post::factory()->for($other)->create(['base_text' => 'Other workspace post']);

    for ($rotation = 0; $rotation < 2; $rotation++) {
        $oldTokenId = mcpRefreshTokenId($tokens);
        $tokens = refreshMcpGrant($client, $tokens);
        $newTokenId = mcpRefreshTokenId($tokens);

        expect($newTokenId)->not->toBe($oldTokenId)
            ->and(Passport::token()->findOrFail($oldTokenId)->revoked)->toBeTrue()
            ->and(McpGrantWorkspace::where('access_token_id', $oldTokenId)->value('workspace_id'))->toBe($workspace->id)
            ->and(McpGrantWorkspace::where('access_token_id', $newTokenId)->value('workspace_id'))->toBe($workspace->id)
            ->and($pending->fresh()->access_token_id)->toBeNull()
            ->and($pending->fresh()->workspace_id)->toBe($other->id);

        callMcpWithRefreshedToken($tokens, ListPostsTool::class)->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertSee('Original workspace post')
            ->assertDontSee('Other workspace post');
    }
});

test('refreshing with reduced scopes preserves read access without granting writes', function (): void {
    [$user, $workspace, $client, $tokens] = issuedMcpRefreshGrant();
    $tokens = refreshMcpGrant($client, $tokens, ['scope' => 'mcp:use read']);

    expect(Passport::token()->findOrFail(mcpRefreshTokenId($tokens))->can('write'))->toBeFalse();
    callMcpWithRefreshedToken($tokens, ListPostsTool::class)->assertOk()->assertJsonPath('result.isError', false);
    callMcpWithRefreshedToken($tokens, CreatePostTool::class, [
        'base_text' => 'Must not publish',
        'destination' => ['kind' => 'all'],
    ])->assertOk()->assertJsonPath('result.isError', true)->assertSee('does not have write access');

    expect(Post::withoutGlobalScopes()->count())->toBe(0);
});

test('refresh never substitutes pending consent when the original binding is missing or mismatched', function (string $fault): void {
    [$user, $workspace, $client, $tokens] = issuedMcpRefreshGrant();
    $binding = McpGrantWorkspace::where('access_token_id', mcpRefreshTokenId($tokens))->firstOrFail();

    match ($fault) {
        'missing' => $binding->delete(),
        'user' => $binding->update(['user_id' => User::factory()->create()->id]),
        'client' => $binding->update(['client_id' => 'different-client']),
        'missing token' => Passport::token()->whereKey(mcpRefreshTokenId($tokens))->delete(),
    };

    $pending = McpGrantWorkspace::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'workspace_id' => $workspace->id,
        'access_token_id' => null,
    ]);
    $tokens = refreshMcpGrant($client, $tokens);

    expect(McpGrantWorkspace::where('access_token_id', mcpRefreshTokenId($tokens))->exists())->toBeFalse()
        ->and($pending->fresh()->access_token_id)->toBeNull();
    callMcpWithRefreshedToken($tokens, ListPostsTool::class)->assertOk()->assertJsonPath('result.isError', true)
        ->assertSee('not bound to a workspace');
})->with(['missing', 'user', 'client', 'missing token']);

test('a refreshed token cannot recover a revoked workspace membership', function (): void {
    [$user, $workspace, $client, $tokens] = issuedMcpRefreshGrant();
    WorkspaceMembership::where('user_id', $user->id)->where('workspace_id', $workspace->id)->firstOrFail()->delete();
    $tokens = refreshMcpGrant($client, $tokens);

    expect(McpGrantWorkspace::where('access_token_id', mcpRefreshTokenId($tokens))->exists())->toBeFalse();
    callMcpWithRefreshedToken($tokens, ListPostsTool::class)->assertOk()->assertJsonPath('result.isError', true);
});

test('invalid refresh requests never create or move workspace bindings', function (string $fault): void {
    if ($fault === 'expired') {
        Passport::refreshTokensExpireIn(now()->subMinute());
    }

    [$user, $workspace, $client, $tokens] = issuedMcpRefreshGrant('mcp:use read');
    $pending = McpGrantWorkspace::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'workspace_id' => $workspace->id,
        'access_token_id' => null,
    ]);
    $payload = [
        'grant_type' => 'refresh_token',
        'client_id' => $client->id,
        'refresh_token' => $tokens['refresh_token'],
    ];

    if ($fault === 'invalid') {
        $payload['refresh_token'] = 'not-an-encrypted-refresh-token';
    } elseif ($fault === 'revoked') {
        Passport::refreshToken()->where('access_token_id', mcpRefreshTokenId($tokens))->firstOrFail()->revoke();
    } elseif ($fault === 'wrong client') {
        $payload['client_id'] = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Other MCP client', ['http://127.0.0.1/callback'], confidential: false, user: $user,
        )->id;
    } elseif ($fault === 'scope escalation') {
        $payload['scope'] = 'mcp:use read write';
    }

    $count = Passport::token()->count();
    $this->postJson('/oauth/token', $payload)->assertStatus(400);

    expect(Passport::token()->count())->toBe($count)
        ->and(McpGrantWorkspace::count())->toBe(2)
        ->and($pending->fresh()->access_token_id)->toBeNull();
})->with(['invalid', 'expired', 'revoked', 'wrong client', 'scope escalation']);
