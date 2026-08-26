<?php

use App\Enums\Platform;

test('platform capability flags are correct', function () {
    expect(Platform::X->supportsOAuth())->toBeTrue()
        ->and(Platform::X->supportsAppPassword())->toBeFalse()
        ->and(Platform::LinkedIn->supportsOAuth())->toBeTrue()
        ->and(Platform::LinkedIn->supportsAppPassword())->toBeFalse()
        ->and(Platform::Bluesky->supportsOAuth())->toBeFalse()
        ->and(Platform::Bluesky->supportsAppPassword())->toBeTrue();
});

test('x scopes include users.email so Socialite can read confirmed_email', function () {
    // Socialite's X driver always requests the confirmed_email user field, which
    // 403s unless the users.email scope was granted. Regression guard for that.
    expect(Platform::X->scopes())->toContain('users.email')
        ->and(Platform::X->scopes())->toContain('tweet.write')
        // media.write is required for v2 media upload (/2/media/upload).
        ->and(Platform::X->scopes())->toContain('media.write');
});

test('x scopes include like.write so the engagement inbox can like and unlike', function () {
    // Regression guard for a real production bug: without like.write, X 403s
    // POST/DELETE /2/users/{id}/likes ("Missing required OAuth2 scopes"), the
    // connector maps that 403 to `unsupported`, and likes silently never persist.
    // One scope covers both like and unlike. Do not drop it.
    expect(Platform::X->scopes())->toContain('like.write');
});

test('platforms report whether their connector can like a reply', function () {
    // Only Threads still has no like/unlike write for replies; Instagram gained
    // it with the Like Media and Comments API (2026-04-22).
    expect(Platform::X->supportsReplyLikes())->toBeTrue()
        ->and(Platform::Bluesky->supportsReplyLikes())->toBeTrue()
        ->and(Platform::LinkedIn->supportsReplyLikes())->toBeTrue()
        ->and(Platform::Facebook->supportsReplyLikes())->toBeTrue()
        ->and(Platform::Discord->supportsReplyLikes())->toBeTrue()
        ->and(Platform::Instagram->supportsReplyLikes())->toBeTrue()
        ->and(Platform::TikTok->supportsReplyLikes())->toBeFalse()
        ->and(Platform::YouTube->supportsReplyLikes())->toBeFalse()
        ->and(Platform::Threads->supportsReplyLikes())->toBeFalse();
});

test('instagram requests the engagement scope that powers reply likes', function () {
    expect(Platform::Instagram->metaGraphScopes())->toContain('instagram_manage_engagement');
});

test('direct instagram scopes use the instagram login permission family', function () {
    expect(Platform::Instagram->scopes())
        ->toContain('instagram_business_basic')
        ->toContain('instagram_business_manage_insights')
        ->toContain('instagram_business_content_publish')
        ->toContain('instagram_business_manage_comments')
        ->not->toContain('pages_show_list');
});

test('socialite driver names match core socialite keys', function () {
    expect(Platform::X->socialiteDriver())->toBe('x')
        ->and(Platform::LinkedIn->socialiteDriver())->toBe('linkedin-openid')
        ->and(Platform::TikTok->socialiteDriver())->toBe('tiktok')
        ->and(Platform::YouTube->socialiteDriver())->toBe('youtube')
        ->and(Platform::Bluesky->socialiteDriver())->toBeNull();
});

test('oauth platform is configured only when client id and secret are present', function () {
    config()->set('services.x.client_id', null);
    config()->set('services.x.client_secret', null);
    expect(Platform::X->isConfigured())->toBeFalse();

    // The redirect URI is derived from the request at connect time (not config),
    // so credentials alone determine whether the connect button is usable.
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    expect(Platform::X->isConfigured())->toBeTrue();
});

test('oauth platform with blank credentials is not configured', function () {
    // env_file passthrough turns an unset var into an empty string, which must
    // not count as configured.
    config()->set('services.x.client_id', '');
    config()->set('services.x.client_secret', '');
    expect(Platform::X->isConfigured())->toBeFalse();

    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', '');
    expect(Platform::X->isConfigured())->toBeFalse();
});

test('app-password platform is always configured', function () {
    expect(Platform::Bluesky->isConfigured())->toBeTrue();
});

test('capabilities array exposes one entry per platform for the frontend', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');

    $caps = Platform::capabilities();

    expect($caps)->toHaveCount(9)
        ->and($caps[0])->toHaveKeys(['platform', 'label', 'supportsOAuth', 'supportsAppPassword', 'supportsWebhook', 'configured', 'directlyConfigured', 'launched', 'enabled']);
});

test('every platform is launched', function () {
    expect(Platform::X->isLaunched())->toBeTrue()
        ->and(Platform::Bluesky->isLaunched())->toBeTrue()
        ->and(Platform::LinkedIn->isLaunched())->toBeTrue()
        ->and(Platform::Facebook->isLaunched())->toBeTrue()
        ->and(Platform::Instagram->isLaunched())->toBeTrue()
        ->and(Platform::TikTok->isLaunched())->toBeTrue()
        ->and(Platform::YouTube->isLaunched())->toBeTrue()
        ->and(Platform::Threads->isLaunched())->toBeTrue()
        ->and(Platform::Discord->isLaunched())->toBeTrue();
});

test('tiktok and youtube base scopes cover profiles and metrics without unreviewed uploads', function () {
    expect(Platform::TikTok->scopes())
        ->toContain('video.list')
        ->toContain('user.info.stats')
        ->not->toContain('video.upload')
        ->and(Platform::YouTube->scopes())
        ->toContain('https://www.googleapis.com/auth/youtube.readonly')
        ->toContain('https://www.googleapis.com/auth/yt-analytics.readonly')
        ->not->toContain('https://www.googleapis.com/auth/youtube.upload');
});

test('tiktok and youtube use independent oauth credentials', function () {
    config()->set('services.tiktok.client_id', 'tiktok-client');
    config()->set('services.tiktok.client_secret', 'tiktok-secret');
    config()->set('services.youtube.client_id', 'youtube-client');
    config()->set('services.youtube.client_secret', 'youtube-secret');

    expect(Platform::TikTok->configKey())->toBe('services.tiktok')
        ->and(Platform::TikTok->isConfigured())->toBeTrue()
        ->and(Platform::YouTube->configKey())->toBe('services.youtube')
        ->and(Platform::YouTube->isConfigured())->toBeTrue();
});

test('facebook scopes cover the reconciled facebook-login set', function () {
    expect(Platform::Facebook->scopes())->toBe([
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'pages_read_user_content',
        'pages_manage_engagement',
        'read_insights',
        'business_management',
    ]);
});

test('meta platforms report oauth capability and no app password', function () {
    foreach ([Platform::Facebook, Platform::Instagram, Platform::Threads] as $platform) {
        expect($platform->supportsOAuth())->toBeTrue()
            ->and($platform->supportsAppPassword())->toBeFalse();
    }
});

test('meta socialite drivers and config keys are wired', function () {
    expect(Platform::Facebook->socialiteDriver())->toBe('facebook')
        ->and(Platform::Instagram->socialiteDriver())->toBe('instagram')
        ->and(Platform::Threads->socialiteDriver())->toBe('threads')
        ->and(Platform::Facebook->configKey())->toBe('services.facebook')
        ->and(Platform::Instagram->configKey())->toBe('services.instagram')
        ->and(Platform::Threads->configKey())->toBe('services.threads');
});

test('meta text limits and threading match the spec', function () {
    expect(Platform::Facebook->maxLength())->toBe(63_206)
        ->and(Platform::Instagram->maxLength())->toBe(2_200)
        ->and(Platform::Threads->maxLength())->toBe(500)
        ->and(Platform::Facebook->threadMax())->toBe(1)
        ->and(Platform::Instagram->threadMax())->toBe(1)
        ->and(Platform::Threads->threadMax())->toBeNull()
        ->and(Platform::Threads->measure('héllo'))->toBe(5);
});

test('instagram is connectable from direct or linked page credentials', function () {
    config()->set('services.instagram.client_id', 'instagram-id');
    config()->set('services.instagram.client_secret', 'instagram-secret');
    config()->set('services.facebook.client_id', null);
    config()->set('services.facebook.client_secret', null);

    expect(Platform::Instagram->isDirectlyConfigured())->toBeTrue()
        ->and(Platform::Instagram->isConfigured())->toBeTrue();

    config()->set('services.instagram.client_id', null);
    config()->set('services.instagram.client_secret', null);
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    expect(Platform::Facebook->isConfigured())->toBeTrue()
        ->and(Platform::Instagram->isDirectlyConfigured())->toBeFalse()
        ->and(Platform::Instagram->isConfigured())->toBeTrue();

    config()->set('services.facebook.client_id', '');
    expect(Platform::Instagram->isConfigured())->toBeFalse();
});
