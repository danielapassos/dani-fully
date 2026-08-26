<?php

test('production compose points at this fork and remains explicitly overridable', function () {
    $compose = file_get_contents(base_path('docker-compose.production.yaml'));

    expect($compose)
        ->toContain('${SHOUTRRR_IMAGE:-ghcr.io/danielapassos/dani-fully:latest}')
        ->not->toContain('image: "ghcr.io/coollabsio/shoutrrr:latest"');
});

test('the single-server production env template persists a generated passport keypair', function () {
    $environment = file_get_contents(base_path('.env.example.prod'));
    $fullEnvironment = file_get_contents(base_path('.env.example.full'));

    expect($environment)
        ->toContain("PASSPORT_PRIVATE_KEY=\n")
        ->toContain("PASSPORT_PUBLIC_KEY=\n")
        ->toContain('PASSPORT_AUTO_GENERATE_KEYS=true');
    expect($fullEnvironment)->toContain('PASSPORT_AUTO_GENERATE_KEYS=true');

    expect(file_get_contents(base_path('README.md')))
        ->toContain('the persistent `storage` volume')
        ->toContain('PASSPORT_AUTO_GENERATE_KEYS=false');
});

test('the production env template documents every direct provider gate', function () {
    $environment = file_get_contents(base_path('.env.example.prod'));

    foreach ([
        'INSTAGRAM_APP_ID=',
        'INSTAGRAM_APP_SECRET=',
        'INSTAGRAM_DIRECT_MESSAGES_ENABLED=false',
        'TIKTOK_CLIENT_KEY=',
        'TIKTOK_CLIENT_SECRET=',
        'TIKTOK_INBOX_ENABLED=false',
        'YOUTUBE_REDIRECT_URI=',
        'YOUTUBE_PUBLISHING_ENABLED=false',
        'YOUTUBE_PRIVACY_STATUS=private',
        'PUBLIC_MEDIA_URL=',
    ] as $setting) {
        expect($environment)->toContain($setting);
    }
});

test('every database-writing scheduled command is single-server', function () {
    $scheduleLines = collect(explode("\n", file_get_contents(base_path('routes/console.php'))))
        ->filter(fn (string $line): bool => str_contains($line, 'Schedule::command('));

    expect($scheduleLines)->not->toBeEmpty();

    foreach ($scheduleLines as $line) {
        expect($line)->toContain('->onOneServer()');
    }
});

test('the lint workflow is read-only and checks php formatting without rewriting it', function () {
    $workflow = file_get_contents(base_path('.github/workflows/lint.yml'));

    expect($workflow)
        ->toContain("permissions:\n  contents: read")
        ->toContain('run: composer lint:check')
        ->toContain('run: bun install --frozen-lockfile')
        ->not->toContain('contents: write');
});

test('vite config never loads the full server environment into debug output', function () {
    $config = file_get_contents(base_path('vite.config.ts'));

    expect($config)
        ->toContain("'APP_URL'")
        ->toContain("'VITE_HMR_HOST'")
        ->toContain("'VITE_PORT'")
        ->toContain("'SKIP_WAYFINDER_GENERATE'")
        ->toContain('loadEnv(mode, process.cwd(), CONFIG_ENV_KEYS)')
        ->not->toContain("loadEnv(mode, process.cwd(), '')")
        ->not->toContain('...process.env');
});
