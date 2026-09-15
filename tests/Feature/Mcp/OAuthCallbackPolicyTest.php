<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;

beforeEach(function (): void {
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys', ['--no-interaction' => true]);
    }
    [$this->owner, $this->workspace] = ownerActingIn();
});

function callbackFormAction(TestResponse $response): string
{
    preg_match('/(?:^|; )form-action ([^;]+)/', (string) $response->headers->get('Content-Security-Policy'), $matches);

    return $matches[1] ?? '';
}

/** @return array<string, string> */
function callbackConsentQuery(string $redirect): array
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Codex callback test', [$redirect], confidential: false, user: test()->owner,
    );

    return [
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'read write mcp:use',
        'state' => 'callback-state',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ];
}

test('consent permits only its validated loopback origin', function (string $origin): void {
    $query = callbackConsentQuery($origin.'/callback/session');
    $consent = $this->get('/oauth/authorize?'.http_build_query($query))->assertOk();

    expect(callbackFormAction($consent))->toBe("'self' https: http://localhost:8787 {$origin}");
})->with(['http://127.0.0.1:59379', 'http://localhost:49152']);

test('approval and cancellation preserve the validated callback after consuming consent', function (bool $approve): void {
    $origin = 'http://127.0.0.1:59379';
    $query = callbackConsentQuery($origin.'/callback/session');
    $this->get('/oauth/authorize?'.http_build_query($query))->assertOk();
    $data = [
        'client_id' => $query['client_id'],
        'workspace_id' => $this->workspace->id,
        'auth_token' => session('authToken'),
        // A submitted redirect must never override Passport's validated request.
        'redirect_uri' => 'http://127.0.0.1:60000/unrelated',
    ];
    $response = $approve ? $this->post('/oauth/authorize', $data) : $this->delete('/oauth/authorize', $data);
    $response->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $returned);

    expect($response->headers->get('Location'))->toStartWith($origin.'/callback/session?')
        ->and(callbackFormAction($response))->toBe("'self' https: http://localhost:8787 {$origin}")
        ->and(session('authRequest'))->toBeNull()
        ->and($returned['state'])->toBe('callback-state');
    if ($approve) {
        expect($returned['code'])->toBeString()->not->toBeEmpty();
    } else {
        expect($returned['error'])->toBe('access_denied');
    }
})->with([true, false]);

test('the sole registered callback works when redirect_uri is omitted', function (): void {
    $query = callbackConsentQuery('http://127.0.0.1:59379/callback/session');
    unset($query['redirect_uri']);
    $response = $this->get('/oauth/authorize?'.http_build_query($query))->assertOk();
    expect(callbackFormAction($response))->toBe("'self' https: http://localhost:8787 http://127.0.0.1:59379");
});

test('a pending consent cannot widen the policy on unrelated or invalid requests', function (): void {
    $query = callbackConsentQuery('http://127.0.0.1:59379/callback/session');
    $this->get('/oauth/authorize?'.http_build_query($query))->assertOk();
    expect(callbackFormAction($this->get('/login')))->toBe("'self' https: http://localhost:8787");

    $query['redirect_uri'] = 'http://attacker.example:59379/callback/session';
    $response = $this->get('/oauth/authorize?'.http_build_query($query))->assertStatus(401);
    expect(callbackFormAction($response))->not->toContain('127.0.0.1', 'attacker.example');
});

test('an incorrect consent token cannot widen the policy', function (): void {
    $query = callbackConsentQuery('http://127.0.0.1:59379/callback/session');
    $this->get('/oauth/authorize?'.http_build_query($query))->assertOk();
    $response = $this->post('/oauth/authorize', [
        'client_id' => $query['client_id'],
        'workspace_id' => $this->workspace->id,
        'auth_token' => 'wrong-token',
    ])->assertStatus(403);
    expect(callbackFormAction($response))->not->toContain('127.0.0.1');
});

test('registered non-loopback callbacks do not add an HTTP form source', function (string $callback): void {
    $query = callbackConsentQuery($callback);
    $response = $this->get('/oauth/authorize?'.http_build_query($query))->assertOk();
    expect(callbackFormAction($response))->toBe("'self' https: http://localhost:8787");
})->with(['https://client.example/callback', 'http://client.example/callback']);
