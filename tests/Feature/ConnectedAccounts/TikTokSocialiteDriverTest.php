<?php

use App\Services\Auth\Socialite\TikTokProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    config()->set('services.tiktok.client_id', 'tiktok-client-key');
    config()->set('services.tiktok.client_secret', 'tiktok-client-secret');
    config()->set('services.tiktok.redirect', 'https://app.test/accounts/callback/tiktok');
});

test('the tiktok driver resolves and requests inbox publishing scopes', function () {
    expect(Socialite::driver('tiktok'))->toBeInstanceOf(TikTokProvider::class);

    $location = Socialite::driver('tiktok')->stateless()->redirect()->getTargetUrl();
    $query = [];
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.tiktok.com/v2/auth/authorize/')
        ->and($query['client_key'])->toBe('tiktok-client-key')
        ->and(explode(',', (string) $query['scope']))
        ->toContain('video.upload', 'video.list', 'user.info.stats');
});

test('the tiktok driver maps the creator profile', function () {
    Http::fake([
        'https://open.tiktokapis.com/v2/user/info/*' => Http::response([
            'data' => ['user' => [
                'open_id' => 'open-42',
                'display_name' => 'Dani Creator',
                'username' => 'danicreator',
                'avatar_url' => 'https://example.test/dani.jpg',
            ]],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $user = Socialite::driver('tiktok')->userFromToken('tiktok-token');

    expect($user->getId())->toBe('open-42')
        ->and($user->getNickname())->toBe('danicreator')
        ->and($user->getName())->toBe('Dani Creator')
        ->and($user->getAvatar())->toBe('https://example.test/dani.jpg');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://open.tiktokapis.com/v2/user/info/')
        && $request->hasHeader('Authorization', 'Bearer tiktok-token'));
});
