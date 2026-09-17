<?php

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Exceptions\TikTokCreatorInfoException;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Services\Publishing\Metricool\MetricoolClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    config()->set('services.metricool.token', 'metricool-secret');
});

test('metricool rejects unmapped or foreign workspace accounts without network calls', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok, 'handle' => 'shesoutdooor']);
    config()->set('services.metricool.user_id', '4626495');
    config()->set('services.metricool.workspace_id', 'other-workspace');
    config()->set('services.metricool.accounts', [$account->id => '6082760']);
    try {
        app(MetricoolClient::class)->binding($account);
        test()->fail('Expected mismatched workspace to fail.');
    } catch (TikTokCreatorInfoException $exception) {
        expect($exception->errorKind)->toBe(ErrorKind::Unsupported);
    }
    Http::assertNothingSent();
});

test('metricool rejects a mapped brand connected to a different creator', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok, 'handle' => 'shesoutdooor']);
    config()->set('services.metricool.user_id', '4626495');
    config()->set('services.metricool.workspace_id', $account->workspace_id);
    config()->set('services.metricool.accounts', [$account->id => '6082760']);
    Http::fake(['*/admin/simpleProfiles*' => Http::response([['id' => 6082760, 'tiktok' => 'mommygorl']])]);
    expect(fn () => app(MetricoolClient::class)->binding($account))->toThrow(TikTokCreatorInfoException::class, 'does not match');
    Http::assertSentCount(1);
});

test('metricool errors never expose credentials and never request a native oauth refresh', function (int $status, ErrorKind $kind) {
    Http::fake(['app.metricool.com/*' => Http::response(['error' => 'secret bearer credential signed-url'], $status)]);
    try {
        app(MetricoolClient::class)->request(['user_id' => '4626495', 'blog_id' => '6082760'], 'GET', '/admin/simpleProfiles');
        test()->fail('Expected remote failure.');
    } catch (TikTokCreatorInfoException $exception) {
        expect($exception->errorKind)->toBe($kind)->and($exception->getMessage())->not->toContain('secret bearer');
    }
})->with([[401, ErrorKind::Unsupported], [403, ErrorKind::Unsupported], [429, ErrorKind::RateLimited], [503, ErrorKind::ServerError]]);

test('metricool multipart upload checks hashes and completes the exact remote parts without leaking auth', function () {
    $bytes = str_repeat('x', 8 * 1024 * 1024).'tail';
    $media = PostMedia::factory()->video()->create(['disk' => 'local', 'path' => 'multi.mp4', 'size_bytes' => strlen($bytes)]);
    Storage::disk('local')->put($media->path, $bytes);
    Http::fake(function (Request $request) use ($bytes) {
        if ($request->method() === 'PUT' && str_contains($request->url(), 'app.metricool.com')) {
            expect($request['parts'])->toHaveCount(2)
                ->and($request['parts'][0]['hash'])->toBe(base64_encode(hash('sha256', substr($bytes, 0, 8 * 1024 * 1024), true)))
                ->and($request['parts'][1]['startByte'])->toBe(8 * 1024 * 1024)
                ->and($request['parts'][1]['endByte'])->toBe(strlen($bytes));

            return Http::response(['data' => ['uploadType' => 'MULTIPART', 'uploadId' => 'upload-1', 'key' => 'video.mp4', 'parts' => [
                ['partNumber' => 1, 'presignedUrl' => 'https://metricool-upload.s3.eu-west-1.amazonaws.com/part1?secret=1'],
                ['partNumber' => 2, 'presignedUrl' => 'https://metricool-upload.s3.eu-west-1.amazonaws.com/part2?secret=2'],
            ]]]);
        }
        if (str_contains($request->url(), 'metricool-upload.s3')) {
            expect($request->hasHeader('X-Mc-Auth'))->toBeFalse();

            return Http::response('', 200, ['ETag' => 'etag'.(str_contains($request->url(), 'part1') ? '1' : '2')]);
        }
        expect($request->method())->toBe('PATCH')
            ->and($request['multipart']['parts'])->toBe([['partNumber' => 1, 'etag' => 'etag1'], ['partNumber' => 2, 'etag' => 'etag2']]);

        return Http::response(['data' => ['convertedFileUrl' => 'https://metricool-download.s3.eu-west-1.amazonaws.com/final.mp4']]);
    });
    expect(app(MetricoolClient::class)->uploadVideo(['user_id' => '4626495', 'blog_id' => '6082760'], $media))
        ->toBe('https://metricool-download.s3.eu-west-1.amazonaws.com/final.mp4');
    Http::assertSentCount(4);
});

test('metricool refuses untrusted upload hosts before sending video bytes', function () {
    $media = PostMedia::factory()->video()->create(['disk' => 'local', 'path' => 'small.mp4', 'size_bytes' => 4]);
    Storage::disk('local')->put($media->path, 'test');
    Http::fake(['app.metricool.com/*' => Http::response(['data' => ['uploadType' => 'SIMPLE', 'presignedUrl' => 'https://attacker.test/upload']])]);
    expect(fn () => app(MetricoolClient::class)->uploadVideo(['user_id' => '4626495', 'blog_id' => '6082760'], $media))
        ->toThrow(TikTokCreatorInfoException::class, 'untrusted');
    Http::assertSentCount(1);
});
