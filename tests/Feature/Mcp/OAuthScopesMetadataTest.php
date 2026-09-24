<?php

use App\Mcp\Tools\CreatePostTool;
use App\Models\McpGrantWorkspace;
use App\Models\Post;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

test('oauth discovery advertises optional permissions on root and nested endpoints', function (string $endpoint): void {
    $response = $this->getJson($endpoint)->assertOk()
        ->assertJsonPath('scopes_supported', ['mcp:use', 'read', 'write']);

    if (str_contains($endpoint, 'authorization-server')) {
        $response->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);
    } else {
        $response->assertJsonPath('resource', url(str_ends_with($endpoint, '/mcp') ? '/mcp' : '/'))
            ->assertJsonPath('authorization_servers', [url('/')]);
    }

    expect(Passport::token()->count())->toBe(0)
        ->and(McpGrantWorkspace::count())->toBe(0);
})->with([
    '/.well-known/oauth-authorization-server',
    '/.well-known/oauth-authorization-server/mcp',
    '/.well-known/oauth-protected-resource',
    '/.well-known/oauth-protected-resource/mcp',
]);

test('registration acknowledges only explicitly requested scopes without issuing a grant', function (?string $requested, string $expected): void {
    $body = [
        'client_name' => 'Scope metadata test',
        'redirect_uris' => ['http://127.0.0.1:1455/callback'],
    ];

    if ($requested !== null) {
        $body['scope'] = $requested;
    }

    $this->postJson('/oauth/register', $body)->assertCreated()
        ->assertJsonPath('scope', $expected)
        ->assertJsonPath('token_endpoint_auth_method', 'none');

    expect(Passport::token()->count())->toBe(0)
        ->and(Passport::refreshToken()->count())->toBe(0)
        ->and(McpGrantWorkspace::count())->toBe(0);
})->with([
    'omitted stays MCP only' => [null, 'mcp:use'],
    'MCP only' => ['mcp:use', 'mcp:use'],
    'read only' => ['mcp:use read', 'mcp:use read'],
    'explicit write' => ['mcp:use read write', 'mcp:use read write'],
    'duplicates' => ['read mcp:use read', 'read mcp:use'],
]);

test('registration rejects malformed or unsupported scopes without creating a client', function (mixed $scope): void {
    $clientsBefore = Passport::client()->count();

    $this->postJson('/oauth/register', [
        'client_name' => 'Invalid scope test',
        'redirect_uris' => ['http://127.0.0.1:1455/callback'],
        'scope' => $scope,
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');

    expect(Passport::client()->count())->toBe($clientsBefore);
})->with([
    'unknown' => ['mcp:use admin'],
    'wildcard' => ['*'],
    'array' => [['mcp:use', 'write']],
    'null' => [null],
    'empty' => [''],
    'tabs' => ["mcp:use\twrite"],
    'oversized' => [str_repeat('read ', 60).'mcp:use'],
]);

test('registration requesting write cannot add write to a read-only PKCE consent or its refresh', function (): void {
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys', ['--no-interaction' => true]);
    }
    [$user, $workspace] = ownerActingIn();
    $redirect = 'http://127.0.0.1:1455/callback';
    $clientId = $this->postJson('/oauth/register', [
        'client_name' => 'Explicit write metadata',
        'redirect_uris' => [$redirect],
        'scope' => 'mcp:use read write',
    ])->assertCreated()->json('client_id');
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'mcp:use read',
        'state' => 'scope-metadata-test',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk()->assertSee($workspace->name);
    $approval = $this->post('/oauth/authorize', [
        'client_id' => $clientId,
        'workspace_id' => $workspace->id,
        'auth_token' => session('authToken'),
    ])->assertRedirect();
    parse_str((string) parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('scope-metadata-test');

    $tokens = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'code' => $query['code'],
        'code_verifier' => $verifier,
    ])->assertOk()->json();

    for ($rotation = 0; $rotation < 2; $rotation++) {
        Auth::forgetGuards();
        $this->withToken($tokens['access_token'])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => app(CreatePostTool::class)->name(),
                'arguments' => ['base_text' => 'Must not create', 'destination' => ['kind' => 'all']],
            ],
        ])->assertOk()->assertJsonPath('result.isError', true)->assertSee('does not have write access');

        expect(Passport::token()->where('revoked', false)->firstOrFail()->can('write'))->toBeFalse()
            ->and(McpGrantWorkspace::query()->latest()->value('workspace_id'))->toBe($workspace->id)
            ->and(Post::withoutGlobalScopes()->count())->toBe(0);

        if ($rotation === 0) {
            $tokens = $this->postJson('/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'refresh_token' => $tokens['refresh_token'],
            ])->assertOk()->json();
        }
    }
});
