<?php

test('openapi spec is publicly reachable without a bearer token', function (): void {
    $response = $this->getJson('/api/v1/openapi.json');

    $response->assertOk();

    $spec = $response->json();

    expect($spec)->toBeArray();
    expect($spec['openapi'])->toBeString()->toStartWith('3.');
});

test('openapi spec documents the real api/v1 paths', function (): void {
    $paths = $this->getJson('/api/v1/openapi.json')->json('paths');

    expect($paths)->toBeArray()->not->toBeEmpty();

    $keys = array_keys($paths);

    expect(collect($keys)->contains(fn (string $path): bool => str_contains($path, 'posts')))->toBeTrue();
    expect(collect($keys)->contains(
        fn (string $path): bool => str_contains($path, 'connected-accounts') || str_contains($path, 'account-sets')
    ))->toBeTrue();
});

test('openapi spec declares a bearer security scheme', function (): void {
    $schemes = $this->getJson('/api/v1/openapi.json')->json('components.securitySchemes');

    expect($schemes)->toBeArray()->not->toBeEmpty();

    $hasBearerScheme = collect($schemes)->contains(
        fn (array $scheme): bool => ($scheme['type'] ?? null) === 'http' && ($scheme['scheme'] ?? null) === 'bearer'
    );

    expect($hasBearerScheme)->toBeTrue();
});

test('openapi documents the signed MP4 upload request and completion metadata', function (): void {
    $spec = $this->getJson('/api/v1/openapi.json')->assertOk()->json();
    $begin = $spec['paths']['/media/video-uploads']['post'];
    $complete = $spec['paths']['/media/video-uploads/{uploadId}/complete']['post'];
    $resolve = static function (array $schema) use ($spec): array {
        if (isset($schema['$ref'])) {
            return $spec['components']['schemas'][basename($schema['$ref'])];
        }

        return $schema;
    };
    $request = $resolve($begin['requestBody']['content']['application/json']['schema']);
    $signedUpload = $resolve($begin['responses']['201']['content']['application/json']['schema']);
    $media = $resolve($complete['responses']['200']['content']['application/json']['schema']);

    expect(array_keys($request['properties']))->toContain('content_type', 'size_bytes', 'width', 'height', 'duration_seconds', 'sha256')
        ->and(array_keys($signedUpload['properties']))->toContain('upload_id', 'url', 'headers', 'expires_at', 'max_size_bytes');

    // Scramble may document initial completion and idempotent replay as anyOf.
    foreach ($media['anyOf'] ?? [$media] as $variant) {
        expect(array_keys($resolve($variant)['properties']))->toContain('id', 'mime', 'width', 'height', 'size_bytes', 'observed_sha256');
    }
});

test('openapi describes TikTok inbox refresh without implying another publication', function (): void {
    $spec = $this->getJson('/api/v1/openapi.json')->assertOk()->json();
    $operation = $spec['paths']['/posts/{id}/targets/{targetId}/tiktok-inbox/refresh']['post'];

    expect($operation['summary'])->toBe('Refresh completion of an existing TikTok inbox upload')
        ->and($operation['description'])->toContain('Never uploads, retries, publishes', 'tracking.verified_at', 'historical evidence')
        ->and($operation['responses'])->toHaveKey('200')->toHaveKey('409')
        ->and(collect($operation['parameters'])->keyBy('name')['targetId']['schema']['format'])->toBe('uuid');
});

test('openapi explains analytics freshness and multi-post measurement scope', function (): void {
    $paths = $this->getJson('/api/v1/openapi.json')->assertOk()->json('paths');

    expect($paths['/analytics/accounts']['get']['description'])->toContain('permission_evidence', 'Missing measurements are null')
        ->and($paths['/analytics/posts']['get']['description'])->toContain('remote_ids', 'metric_scope', 'paid_context', 'not engagement accrued');
});
