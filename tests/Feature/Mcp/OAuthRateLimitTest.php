<?php

use App\Models\McpGrantWorkspace;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    $this->freezeTime();
});

test('OAuth discovery tolerates repeated connector handshakes without issuing credentials', function (): void {
    $clientsBefore = Passport::client()->count();
    $endpoints = [
        '/.well-known/oauth-authorization-server',
        '/.well-known/oauth-authorization-server/mcp',
        '/.well-known/oauth-protected-resource',
        '/.well-known/oauth-protected-resource/mcp',
    ];

    for ($handshake = 0; $handshake < 8; $handshake++) {
        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint)->assertOk()
                ->assertHeader('X-RateLimit-Limit', '120')
                ->assertJsonPath('scopes_supported', ['mcp:use', 'read', 'write']);
        }
    }

    expect(Passport::client()->count())->toBe($clientsBefore)
        ->and(Passport::token()->count())->toBe(0)
        ->and(Passport::refreshToken()->count())->toBe(0)
        ->and(McpGrantWorkspace::count())->toBe(0);
});

test('GET and HEAD discovery share a bounded budget across root and nested paths', function (): void {
    for ($request = 0; $request < 120; $request++) {
        $method = $request % 2 === 0 ? 'GET' : 'HEAD';
        $endpoint = match ($request % 4) {
            0 => '/.well-known/oauth-authorization-server',
            1 => '/.well-known/oauth-authorization-server/mcp',
            2 => '/.well-known/oauth-protected-resource',
            3 => '/.well-known/oauth-protected-resource/mcp/'.$request,
        };

        $this->json($method, $endpoint)->assertOk()
            ->assertHeader('X-RateLimit-Remaining', (string) (119 - $request));
    }

    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertStatus(429)
        ->assertHeader('Retry-After', '60')
        ->assertHeader('X-RateLimit-Remaining', '0');
    $this->json('HEAD', '/.well-known/oauth-authorization-server')->assertStatus(429);

    $this->travel(61)->seconds();

    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertHeader('X-RateLimit-Remaining', '119');
});

test('discovery exhaustion does not block client registration or another IP', function (): void {
    for ($request = 0; $request < 120; $request++) {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk();
    }

    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertStatus(429);

    $this->postJson('/oauth/register', [
        'client_name' => 'Isolated registration budget',
        'redirect_uris' => ['http://127.0.0.1:1455/callback'],
    ])->assertCreated()
        ->assertHeader('X-RateLimit-Limit', '20')
        ->assertHeader('X-RateLimit-Remaining', '19');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertHeader('X-RateLimit-Remaining', '119');
});

test('client registration retains its independent twenty request limit', function (): void {
    $clientsBefore = Passport::client()->count();

    for ($request = 0; $request < 20; $request++) {
        $this->postJson('/oauth/register', ['scope' => '*'])->assertStatus(400)
            ->assertJsonPath('error', 'invalid_client_metadata');
    }

    $this->postJson('/oauth/register', [
        'client_name' => 'Rate limited registration',
        'redirect_uris' => ['http://127.0.0.1:1455/callback'],
    ])->assertStatus(429)
        ->assertHeader('X-RateLimit-Limit', '20')
        ->assertHeader('Retry-After', '60');

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertHeader('X-RateLimit-Remaining', '119');

    expect(Passport::client()->count())->toBe($clientsBefore)
        ->and(Passport::token()->count())->toBe(0);
});

test('an exhausted unrelated numeric throttle does not block OAuth discovery or registration', function (): void {
    for ($request = 0; $request < 30; $request++) {
        $this->get('/share/does-not-exist')->assertNotFound();
    }

    $this->get('/share/does-not-exist')->assertStatus(429);

    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertHeader('X-RateLimit-Remaining', '119');
    $this->postJson('/oauth/register', [
        'client_name' => 'Independent OAuth limiter',
        'redirect_uris' => ['http://127.0.0.1:1455/callback'],
    ])->assertCreated()
        ->assertHeader('X-RateLimit-Remaining', '19');

    $this->get('/share/does-not-exist')->assertStatus(429);
});
