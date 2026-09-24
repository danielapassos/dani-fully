<?php

use App\Services\Gifs\GifAttacher;
use Illuminate\Foundation\CloudBootstrapper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;

test('responses carry the static security headers', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('responses carry a nonce-based content security policy', function () {
    $response = $this->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("img-src 'self' data: blob: https:")
        ->and($csp)->toContain("media-src 'self' blob:")
        ->and($csp)->toContain("connect-src 'self' blob:")
        ->and($csp)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]+'/")
        ->and($csp)->toContain("'strict-dynamic'");
});

test('forms may redirect to https services and the cursor mcp oauth loopback', function () {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("form-action 'self' https: http://localhost:8787;");
});

test('a local/public-disk deployment keeps connect-src and media-src tight', function () {
    config(['filesystems.default' => 'public']);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    // No remote storage host, so no origin is appended to either directive.
    expect($csp)->toContain('connect-src \'self\' blob:;')
        ->and($csp)->toContain('media-src \'self\' blob:;');
});

test('a remote s3 disk allowlists its configured endpoint origin for uploads and playback', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3.url' => null,
        'filesystems.disks.s3.endpoint' => 'https://minio.example.com:9000',
    ]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    // The signed-upload PUT / editor GET and <video> playback both target this
    // origin; it must appear in connect-src and media-src, and not leak to https:.
    expect($csp)->toContain('connect-src \'self\' blob: https://minio.example.com:9000')
        ->and($csp)->toContain('media-src \'self\' blob: https://minio.example.com:9000')
        ->and($csp)->not->toContain('connect-src \'self\' blob: https:;');
});

test('an arbitrarily named s3 disk allowlists its own storage origins', function () {
    config([
        'filesystems.default' => 'r2',
        'filesystems.disks.r2' => [
            'driver' => 's3',
            'url' => 'https://media.example.com',
            'endpoint' => 'https://r2-api.example.com',
        ],
    ]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain('connect-src \'self\' blob: https://media.example.com https://r2-api.example.com')
        ->and($csp)->toContain('media-src \'self\' blob: https://media.example.com https://r2-api.example.com');
});

test('a Cloud-injected disk permits its actual virtual-hosted upload origin without sibling buckets', function () {
    $previousCloudConfig = $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] ?? null;
    config(['filesystems.disks.cloud-media.use_path_style_endpoint' => true]);

    try {
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode([[
            'disk' => 'cloud-media',
            'access_key_id' => 'test-key',
            'access_key_secret' => 'test-secret',
            'bucket' => 'shoutrrr-media',
            'url' => 'https://cdn.example.com/media',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'is_default' => true,
        ]], JSON_THROW_ON_ERROR);

        CloudBootstrapper::configureDisks($this->app);
    } finally {
        if ($previousCloudConfig === null) {
            unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG']);
        } else {
            $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = $previousCloudConfig;
        }
    }

    // Signing with dummy credentials is local: this makes no storage request.
    $upload = Storage::disk('cloud-media')->temporaryUploadUrl('tmp/media/test.mp4', now()->addMinutes(5));
    $uploadOrigin = 'https://'.parse_url($upload['url'], PHP_URL_HOST);
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');
    $sources = "'self' blob: https://cdn.example.com https://account.r2.cloudflarestorage.com {$uploadOrigin}";

    expect(config('filesystems.default'))->toBe('cloud-media')
        ->and(config('filesystems.disks.cloud-media.use_path_style_endpoint'))->toBeFalse()
        ->and($uploadOrigin)->toBe('https://shoutrrr-media.account.r2.cloudflarestorage.com')
        ->and($csp)->toContain("connect-src {$sources};")
        ->and($csp)->toContain("media-src {$sources};");
});

test('the storage policy matches the configured S3 addressing mode', function (array $overrides, string $expectedOrigin) {
    $disk = array_replace([
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'auto',
        'bucket' => 'shoutrrr-media',
        'endpoint' => 'https://storage.example.com:8443/api',
        'use_path_style_endpoint' => false,
    ], $overrides);
    config(['filesystems.default' => 'media-test', 'filesystems.disks.media-test' => $disk]);

    $upload = Storage::disk('media-test')->temporaryUploadUrl('tmp/media/test.mp4', now()->addMinutes(5));
    $scheme = parse_url($upload['url'], PHP_URL_SCHEME);
    $host = parse_url($upload['url'], PHP_URL_HOST);
    $port = parse_url($upload['url'], PHP_URL_PORT);
    $uploadOrigin = $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    $endpointOrigin = preg_replace('~/api$~', '', $disk['endpoint']);
    $origins = $expectedOrigin === $endpointOrigin ? $expectedOrigin : $endpointOrigin.' '.$expectedOrigin;
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($uploadOrigin)->toBe($expectedOrigin)
        ->and($csp)->toContain("connect-src 'self' blob: {$origins};")
        ->and($csp)->toContain("media-src 'self' blob: {$origins};");
})->with([
    'virtual-hosted with custom port and path' => [[], 'https://shoutrrr-media.storage.example.com:8443'],
    'explicit path-style' => [['use_path_style_endpoint' => true], 'https://storage.example.com:8443'],
    'bucket-specific endpoint' => [['bucket_endpoint' => true], 'https://storage.example.com:8443'],
    'dotted bucket over HTTPS' => [['bucket' => 'shoutrrr.media'], 'https://storage.example.com:8443'],
    'uppercase bucket' => [['bucket' => 'ShoutrrrMedia'], 'https://storage.example.com:8443'],
    'IPv4 endpoint' => [['endpoint' => 'http://127.0.0.1:9000'], 'http://127.0.0.1:9000'],
    'IPv6 endpoint' => [['endpoint' => 'http://[::1]:9000'], 'http://[::1]:9000'],
]);

test('missing or invalid bucket configuration does not add a storage origin', function (mixed $bucket) {
    config([
        'filesystems.default' => 'media-test',
        'filesystems.disks.media-test' => [
            'driver' => 's3',
            'bucket' => $bucket,
            'endpoint' => 'https://storage.example.com',
        ],
    ]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("connect-src 'self' blob: https://storage.example.com;")
        ->and($csp)->toContain("media-src 'self' blob: https://storage.example.com;");
})->with([
    'missing' => [null],
    'empty' => [''],
    'too short' => ['ab'],
    'leading hyphen' => ['-invalid'],
    'trailing hyphen' => ['invalid-'],
    'underscore' => ['invalid_bucket'],
    'CSP delimiter' => ['invalid; https://other.example.com'],
    'non-string' => [['invalid']],
]);

test('a vanilla s3 disk with no endpoint falls back to https: so uploads still work', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3.url' => null,
        'filesystems.disks.s3.endpoint' => null,
    ]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain('connect-src \'self\' blob: https:')
        ->and($csp)->toContain('media-src \'self\' blob: https:');
});

test('an s3 disk with only a public url still permits its separate presigned upload host', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3.url' => 'https://cdn.example.com',
        'filesystems.disks.s3.endpoint' => null,
    ]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("connect-src 'self' blob: https://cdn.example.com https:")
        ->and($csp)->toContain("media-src 'self' blob: https://cdn.example.com https:");
});

test('a configured frontend Sentry DSN is allowlisted in connect-src', function () {
    config(['sentry-browser.dsn' => 'https://public@o123.ingest.sentry.io/456']);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    // The browser SDK POSTs envelopes to the ingest host; only its origin (not
    // the key/project path) is added to connect-src.
    expect($csp)->toContain("connect-src 'self' blob: https://o123.ingest.sentry.io")
        ->and($csp)->not->toContain('public@');
});

test('connect-src omits Sentry when no frontend DSN is configured', function () {
    config(['sentry-browser.dsn' => null]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("connect-src 'self' blob:;");
});

test('allows the klipy cdn in media-src when gifs are configured', function () {
    config()->set('services.klipy.key', 'test-key');

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain('media-src')
        ->and($csp)->toMatch('/media-src[^;]*https:\/\/\*\.klipy\.com/');
});

test('media-src covers every host gif attach will download from', function () {
    config()->set('services.klipy.key', 'test-key');

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    preg_match('/media-src([^;]*)/', (string) $csp, $matches);
    $mediaSrc = $matches[1] ?? '';

    // A host GifAttacher will fetch from must also be one the browser may
    // render, or clip previews break. CSP wildcards do not match the apex,
    // so both forms are required for each suffix.
    foreach (GifAttacher::ALLOWED_HOST_SUFFIXES as $suffix) {
        expect($mediaSrc)->toContain('https://'.$suffix)
            ->and($mediaSrc)->toContain('https://*.'.$suffix);
    }
});

test('omits the klipy cdn when gifs are not configured', function () {
    config()->set('services.klipy.key', null);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('klipy.com');
});

test('the csp nonce is exposed to vite and differs per request', function () {
    $this->get('/login');
    $first = Vite::cspNonce();

    $this->get('/login');
    $second = Vite::cspNonce();

    expect($first)->not->toBeEmpty()
        ->and($second)->not->toBeEmpty()
        ->and($first)->not->toBe($second);
});
