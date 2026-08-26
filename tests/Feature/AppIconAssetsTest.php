<?php

it('publishes generated app icon assets at expected sizes', function (): void {
    $sizes = [
        'shoutrrr.png' => [512, 512],
        'favicon-16x16.png' => [16, 16],
        'favicon-32x32.png' => [32, 32],
        'favicon-48x48.png' => [48, 48],
        'apple-touch-icon.png' => [180, 180],
        'android-chrome-192x192.png' => [192, 192],
        'android-chrome-512x512.png' => [512, 512],
        'icon-maskable-192.png' => [192, 192],
        'icon-maskable-512.png' => [512, 512],
        'mstile-150x150.png' => [150, 150],
    ];

    foreach ($sizes as $file => [$width, $height]) {
        $path = public_path($file);

        expect($path)->toBeFile();

        $imageSize = getimagesize($path);

        expect([$imageSize[0], $imageSize[1]])->toBe([$width, $height]);
    }

    expect(public_path('favicon.ico'))->toBeFile();
    expect(filesize(public_path('favicon.ico')))->toBeGreaterThan(0);
    expect(public_path('favicon.svg'))->toBeFile();
});

it('uses the current source artwork bounds for the app icon', function (): void {
    $image = imagecreatefrompng(public_path('shoutrrr.png'));
    $left = imagesx($image);
    $top = imagesy($image);
    $right = 0;
    $bottom = 0;

    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $alpha = (imagecolorat($image, $x, $y) & 0x7F000000) >> 24;

            if ($alpha < 127) {
                $left = min($left, $x);
                $top = min($top, $y);
                $right = max($right, $x);
                $bottom = max($bottom, $y);
            }
        }
    }

    expect($left)->toBe(51);
    expect($right)->toBe(460);
    expect($top)->toBe(50);
    expect($bottom)->toBe(462);
});

it('references the generated icons from Laravel HTML entry points', function (): void {
    $views = [
        resource_path('views/app.blade.php'),
        resource_path('views/oauth/authorize.blade.php'),
    ];

    foreach ($views as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->toContain('width=device-width, initial-scale=1')
            ->toContain('/favicon.ico')
            ->toContain('/favicon.svg')
            ->toContain('/apple-touch-icon.png')
            ->toContain('/site.webmanifest');
    }
});

it('publishes a web app manifest using the generated icons', function (): void {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toHaveKey('name', 'shoutrrr')
        ->toHaveKey('id', '/')
        ->toHaveKey('start_url', '/')
        ->toHaveKey('scope', '/')
        ->toHaveKey('display', 'standalone')
        ->toHaveKey('theme_color', '#101010');

    expect(collect($manifest['icons'])->pluck('src')->all())->toEqual([
        '/android-chrome-192x192.png',
        '/android-chrome-512x512.png',
        '/icon-maskable-192.png',
        '/icon-maskable-512.png',
    ]);
});

it('publishes an offline fallback and a navigation-only service worker', function (): void {
    expect(public_path('offline.html'))->toBeFile();
    expect(public_path('sw.js'))->toBeFile();

    $worker = file_get_contents(public_path('sw.js'));

    expect($worker)
        ->toContain("request.method !== 'GET'")
        ->toContain("request.mode !== 'navigate'")
        ->toContain('url.origin !== self.location.origin')
        ->toContain('caches.match(OFFLINE_URL)');
});

it('uses opaque install icons with maskable artwork inside the safe zone', function (): void {
    foreach (['apple-touch-icon.png', 'icon-maskable-192.png', 'icon-maskable-512.png'] as $file) {
        $image = imagecreatefrompng(public_path($file));
        $width = imagesx($image);
        $height = imagesy($image);
        $centerX = ($width - 1) / 2;
        $centerY = ($height - 1) / 2;
        $safeRadius = $width * 0.4;
        $background = imagecolorat($image, 0, 0) & 0x00FFFFFF;
        $isOpaque = true;
        $pixelsOutsideSafeZone = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $alpha = ($pixel & 0x7F000000) >> 24;

                if ($alpha !== 0) {
                    $isOpaque = false;
                }

                if ($file === 'apple-touch-icon.png' || ($pixel & 0x00FFFFFF) === $background) {
                    continue;
                }

                $distance = sqrt((($x - $centerX) ** 2) + (($y - $centerY) ** 2));

                if ($distance > $safeRadius) {
                    $pixelsOutsideSafeZone++;
                }
            }
        }

        expect($isOpaque)->toBeTrue();

        if ($file !== 'apple-touch-icon.png') {
            expect($pixelsOutsideSafeZone)->toBe(0);
        }
    }
});

it('renders the app logo as an inline svg that inherits the current color', function (): void {
    $component = file_get_contents(resource_path('js/components/layout/app-logo-icon.tsx'));

    expect($component)
        ->toContain('<svg')
        ->toContain('stroke="currentColor"')
        ->not->toContain('/shoutrrr.png');
});

it('shows the React app logo without an extra background tile', function (): void {
    $component = file_get_contents(resource_path('js/components/layout/app-logo.tsx'));

    expect($component)
        ->toContain('items-center justify-center')
        ->toContain('group-data-[collapsible=icon]:hidden')
        ->not->toContain('group-data-[collapsible=icon]:mr-1')
        ->not->toContain('bg-sidebar-primary');
});
