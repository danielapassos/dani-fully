<?php

use App\Services\Auth\Socialite\YouTubeProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    config()->set('services.youtube.client_id', 'youtube-client-id');
    config()->set('services.youtube.client_secret', 'youtube-client-secret');
    config()->set('services.youtube.redirect', 'https://app.test/accounts/callback/youtube');
});

test('the youtube driver requests durable read and analytics access by default', function () {
    expect(Socialite::driver('youtube'))->toBeInstanceOf(YouTubeProvider::class);

    request()->setLaravelSession(app('session')->driver());
    $location = Socialite::driver('youtube')->redirect()->getTargetUrl();
    $query = [];
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and(explode(' ', (string) $query['scope']))
        ->toContain(
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
        )
        ->not->toContain('https://www.googleapis.com/auth/youtube.upload');
});

test('the youtube driver maps exactly one owned channel', function () {
    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [[
                'id' => 'UC-dani',
                'snippet' => [
                    'title' => 'Dani on YouTube',
                    'customUrl' => '@daniela',
                    'thumbnails' => ['high' => ['url' => 'https://example.test/youtube.jpg']],
                ],
                'statistics' => ['subscriberCount' => '1200'],
            ]],
        ]),
    ]);

    $user = Socialite::driver('youtube')->userFromToken('youtube-token');

    expect($user->getId())->toBe('UC-dani')
        ->and($user->getNickname())->toBe('@daniela')
        ->and($user->getName())->toBe('Dani on YouTube')
        ->and($user->getAvatar())->toBe('https://example.test/youtube.jpg');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/channels')
        && $request->hasHeader('Authorization', 'Bearer youtube-token'));
});
