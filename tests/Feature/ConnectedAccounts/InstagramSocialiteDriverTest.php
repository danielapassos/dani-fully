<?php

use App\Services\Auth\Socialite\InstagramProvider;
use App\Support\OAuthGrantedScopes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    config()->set('services.instagram.client_id', 'instagram-app-id');
    config()->set('services.instagram.client_secret', 'instagram-app-secret');
    config()->set('services.instagram.redirect', 'https://app.test/accounts/callback/instagram');
    config()->set('services.instagram.graph_version', 'v25.0');
});

test('the instagram driver requests direct professional account scopes', function () {
    expect(Socialite::driver('instagram'))->toBeInstanceOf(InstagramProvider::class);

    $location = Socialite::driver('instagram')->stateless()->redirect()->getTargetUrl();
    $query = [];
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.instagram.com/oauth/authorize?')
        ->and($query['client_id'])->toBe('instagram-app-id')
        ->and(explode(',', (string) $query['scope']))
        ->toContain(
            'instagram_business_basic',
            'instagram_business_manage_insights',
            'instagram_business_content_publish',
            'instagram_business_manage_comments',
        );
});

test('the instagram driver exchanges the code for a long lived token', function () {
    Http::fake([
        'https://api.instagram.com/oauth/access_token' => Http::response([
            'data' => [[
                'access_token' => 'short-token',
                'user_id' => 'ig-42',
                'permissions' => ['instagram_business_basic', 'instagram_business_content_publish'],
            ]],
        ]),
        'https://graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long-token',
            'token_type' => 'bearer',
            'expires_in' => 5_184_000,
        ]),
    ]);

    $response = Socialite::driver('instagram')->stateless()->getAccessTokenResponse('auth-code');

    expect($response['access_token'])->toBe('long-token')
        ->and($response['expires_in'])->toBe(5_184_000)
        ->and($response['user_id'])->toBe('ig-42')
        ->and($response['scope'])->toBe('instagram_business_basic,instagram_business_content_publish');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.instagram.com/oauth/access_token'
        && $request['client_id'] === 'instagram-app-id'
        && $request['client_secret'] === 'instagram-app-secret'
        && $request['redirect_uri'] === 'https://app.test/accounts/callback/instagram'
        && $request['code'] === 'auth-code');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://graph.instagram.com/access_token?')
        && $request['grant_type'] === 'ig_exchange_token'
        && $request['access_token'] === 'short-token');
});

test('the instagram driver maps a professional account profile', function () {
    Http::fake([
        'https://graph.instagram.com/v25.0/me*' => Http::response([
            'data' => [[
                'user_id' => 'ig-42',
                'username' => 'danicreator',
                'name' => 'Dani Creator',
                'account_type' => 'CREATOR',
                'profile_picture_url' => 'https://example.test/dani.jpg',
            ]],
        ]),
    ]);

    $user = Socialite::driver('instagram')->userFromToken('instagram-token');

    expect($user->getId())->toBe('ig-42')
        ->and($user->getNickname())->toBe('danicreator')
        ->and($user->getName())->toBe('Dani Creator')
        ->and($user->getAvatar())->toBe('https://example.test/dani.jpg');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://graph.instagram.com/v25.0/me?')
        && $request->hasHeader('Authorization', 'Bearer instagram-token'));
});

test('instagram grants come only from permissions returned by the provider', function (array $permissionFields, array $expected) {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'short-token',
            'user_id' => 'ig-42',
            ...$permissionFields,
        ]),
        'https://graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long-token',
            'expires_in' => 5_184_000,
        ]),
    ]);

    $response = Socialite::driver('instagram')->stateless()->getAccessTokenResponse('auth-code');
    $capabilities = OAuthGrantedScopes::capabilities($response['scope']);

    expect($capabilities['oauth_scopes'])->toBe($expected)
        ->and($capabilities['oauth_scopes_verified'])->toBe($expected !== []);
})->with([
    'omitted permissions' => [[], []],
    'null permissions' => [['permissions' => null], []],
    'invalid permissions' => [['permissions' => false], []],
    'empty permissions' => [['permissions' => []], []],
    'only basic granted' => [['permissions' => ['instagram_business_basic']], ['instagram_business_basic']],
    'string permissions' => [['permissions' => ' instagram_business_basic,instagram_business_content_publish,instagram_business_basic '], ['instagram_business_basic', 'instagram_business_content_publish']],
]);
