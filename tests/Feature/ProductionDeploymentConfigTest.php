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

test('ci executes frontend tests and type analysis', function () {
    expect(file_get_contents(base_path('.github/workflows/tests.yml')))
        ->toContain('run: bun run test');

    expect(file_get_contents(base_path('.github/workflows/lint.yml')))
        ->toContain('run: bun run types:check');

    expect(file_get_contents(base_path('.github/workflows/tests.yml')))
        ->toContain('name: Production container build')
        ->toContain('docker/build-push-action@53b7df96c91f9c12dcc8a07bcb9ccacbed38856a')
        ->toContain('name: Smoke test production image')
        ->toContain('QUEUE_WORKER_ENABLED=false')
        ->toContain('SCHEDULER_ENABLED=false')
        ->toContain('http://127.0.0.1:18080/up');
});

test('vite config never loads the full server environment into debug output', function () {
    $config = file_get_contents(base_path('vite.config.ts'));

    expect($config)
        ->toContain("'APP_URL'")
        ->toContain("'VITE_HMR_HOST'")
        ->toContain("'VITE_PORT'")
        ->toContain("'SKIP_WAYFINDER_GENERATE'")
        ->toContain('loadEnv(mode, process.cwd(), CONFIG_ENV_KEYS)')
        ->toContain("['1', 'true'].includes")
        ->toContain("(environment.SKIP_WAYFINDER_GENERATE ?? '').toLowerCase()")
        ->not->toContain("loadEnv(mode, process.cwd(), '')")
        ->not->toContain('...process.env');
});

test('docker builds exclude local credentials while retaining public env templates', function () {
    $patterns = collect(explode("\n", file_get_contents(base_path('.dockerignore'))));

    expect($patterns)
        ->toContain('/.env*')
        ->toContain('!/.env.example')
        ->toContain('!/.env.example.full')
        ->toContain('!/.env.example.prod')
        ->toContain('/auth.json')
        ->toContain('/storage/*.key')
        ->toContain('/storage/app/**')
        ->toContain('/storage/framework/**')
        ->toContain('/storage/logs/**')
        ->toContain('/bootstrap/cache/*.php')
        ->toContain('/public/storage');

    $envWildcard = $patterns->search('/.env*');

    foreach (['!/.env.example', '!/.env.example.full', '!/.env.example.prod'] as $template) {
        expect($envWildcard)->toBeLessThan($patterns->search($template));
    }
});

test('production containers pin and verify the same bun release as ci', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)
        ->toContain('ARG BUN_VERSION=1.4.0')
        ->toContain('FROM --platform=$BUILDPLATFORM oven/bun:${BUN_VERSION} AS assets')
        ->toContain('releases/download/bun-v${BUN_VERSION}/bun-linux-${bun_arch}.zip')
        ->toContain('sha256sum -c -')
        ->not->toContain('oven/bun:latest')
        ->not->toContain('/releases/latest/');
});

test('the container initializes default sqlite and gives provider uploads time to drain', function () {
    expect(file_get_contents(base_path('docker/entrypoint.d/10-init-app.sh')))
        ->toContain('${DB_CONNECTION:-sqlite}');

    expect(file_get_contents(base_path('docker/supervisord.conf')))
        ->toContain('stopwaitsecs=930');

    expect(file_get_contents(base_path('docker-compose.production.yaml')))
        ->toContain('stop_grace_period: 960s');
});

test('production compose binds its proxy port to loopback by default', function () {
    expect(file_get_contents(base_path('docker-compose.production.yaml')))
        ->toContain('${APP_BIND_HOST:-127.0.0.1}:${APP_PORT:-8080}:8080');
});

test('coolify documentation describes the prebuilt image release gate', function () {
    expect(file_get_contents(base_path('README.md')))
        ->toContain('The Compose file does not build the checked-out source.')
        ->toContain('a merge to `main` alone does not create or update `:latest`');
});

test('release images cannot build before the test and lint workflows pass', function () {
    expect(file_get_contents(base_path('.github/workflows/tests.yml')))
        ->toContain("workflow_call:\n");
    expect(file_get_contents(base_path('.github/workflows/lint.yml')))
        ->toContain("workflow_call:\n");
    expect(file_get_contents(base_path('.github/workflows/security.yml')))
        ->toContain("workflow_call:\n");
    expect(file_get_contents(base_path('.github/workflows/release.yml')))
        ->toContain('uses: ./.github/workflows/tests.yml')
        ->toContain('uses: ./.github/workflows/lint.yml')
        ->toContain('uses: ./.github/workflows/security.yml')
        ->toContain('needs: [tests, linter, security]');
});
