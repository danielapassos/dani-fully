<?php

use App\Models\PostMedia;
use App\Services\Media\PublicMediaUrl;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

function tikTokDeliveryVideo(): PostMedia
{
    $media = PostMedia::factory()->video()->create([
        'disk' => 's3', 'path' => 'private/videos/exact.mp4', 'size_bytes' => 10,
    ]);
    Storage::disk('s3')->put($media->path, '0123456789');

    return $media;
}

beforeEach(function (): void {
    config(['app.url' => 'https://shoutrrr.example.test', 'media.public_url' => 'https://unverified-bucket.example.test']);
    Storage::fake('s3');
    Http::preventStrayRequests();
});

test('signed TikTok media streams the exact private object to a guest without a redirect', function (): void {
    $media = tikTokDeliveryVideo();
    $url = app(PublicMediaUrl::class)->forTikTok($media);

    $response = $this->get($url)->assertOk()
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Content-Length', '10')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeaderMissing('Location')
        ->assertHeaderMissing('Set-Cookie');

    expect($response->streamedContent())->toBe('0123456789')
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    Http::assertNothingSent();
});

test('TikTok media URLs use the canonical origin for six hours without changing the shared URL generator', function (): void {
    $this->freezeTime();
    $media = tikTokDeliveryVideo();
    URL::forceRootUrl('http://untrusted-request.example.test');
    URL::forceScheme('http');
    $original = route('login');
    $url = app(PublicMediaUrl::class)->forTikTok($media);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://shoutrrr.example.test/provider-media/tiktok/'.$media->id.'?')
        ->and((int) $query['expires'])->toBe(now()->addHours(6)->timestamp)
        ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue()
        ->and(route('login'))->toBe($original)
        ->and($url)->not->toContain($media->path, 'unverified-bucket', 'X-Amz-');
});

test('TikTok media URL creation rejects an unsafe canonical application URL', function (string $base): void {
    config(['app.url' => $base]);
    expect(fn () => app(PublicMediaUrl::class)->forTikTok(tikTokDeliveryVideo()))->toThrow(RuntimeException::class);
})->with(['http://shoutrrr.example.test', 'https://user@shoutrrr.example.test', 'https://user:pass@shoutrrr.example.test', 'https://shoutrrr.example.test/?key=secret']);

test('TikTok HEAD returns file metadata without opening the private byte stream', function (): void {
    $media = tikTokDeliveryVideo();
    $url = app(PublicMediaUrl::class)->forTikTok($media);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with($media->path)->andReturnTrue();
    $disk->shouldReceive('size')->once()->with($media->path)->andReturn(10);
    $disk->shouldNotReceive('readStream');
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

    $this->head($url, ['Range' => 'bytes=2-5'])->assertOk()
        ->assertHeader('Content-Length', '10')->assertContent('');
});

test('TikTok media supports exact single-byte ranges', function (string $range, string $contentRange, string $expected): void {
    $url = app(PublicMediaUrl::class)->forTikTok(tikTokDeliveryVideo());
    $response = $this->get($url, ['Range' => $range])->assertStatus(206)
        ->assertHeader('Content-Range', $contentRange)
        ->assertHeader('Content-Length', (string) strlen($expected));

    expect($response->streamedContent())->toBe($expected);
})->with([
    ['bytes=2-5', 'bytes 2-5/10', '2345'],
    ['bytes=7-', 'bytes 7-9/10', '789'],
    ['bytes=-3', 'bytes 7-9/10', '789'],
    ['bytes=8-999', 'bytes 8-9/10', '89'],
    ['bytes=-999', 'bytes 0-9/10', '0123456789'],
]);

test('TikTok media rejects invalid or unsupported ranges without a body', function (string $range): void {
    $url = app(PublicMediaUrl::class)->forTikTok(tikTokDeliveryVideo());
    $this->get($url, ['Range' => $range])->assertStatus(416)
        ->assertHeader('Content-Range', 'bytes */10')
        ->assertHeader('Content-Length', '0')->assertContent('');
})->with(['bytes=10-', 'bytes=5-2', 'bytes=0-1,4-5', 'bytes=-', 'bytes=-0', 'items=0-1']);

test('TikTok media rejects unsigned expired and tampered URLs before exposing bytes', function (string $change): void {
    $media = tikTokDeliveryVideo();
    $url = app(PublicMediaUrl::class)->forTikTok($media);
    if ($change === 'unsigned') {
        $url = strtok($url, '?');
    } elseif ($change === 'expired') {
        $this->travel(7)->hours();
    } elseif ($change === 'media id') {
        $other = PostMedia::factory()->video()->create();
        $url = str_replace($media->id, $other->id, $url);
    } else {
        $url .= '&path=other-private-file.mp4';
    }

    $this->get($url)->assertForbidden()->assertHeaderMissing('Location');
})->with(['unsigned', 'expired', 'media id', 'arbitrary path']);

test('TikTok media rejects a signed URL after its stored source changes', function (): void {
    $media = tikTokDeliveryVideo();
    $url = app(PublicMediaUrl::class)->forTikTok($media);
    $media->forceFill(['path' => 'private/another.mp4'])->save();

    $this->get($url)->assertNotFound();
});

test('TikTok media returns not found when its private object is missing', function (): void {
    $media = tikTokDeliveryVideo();
    $url = app(PublicMediaUrl::class)->forTikTok($media);
    Storage::disk('s3')->delete($media->path);

    $this->get($url)->assertNotFound()->assertHeaderMissing('Location');
});

test('TikTok media refuses changed storage bytes or unsupported media types', function (string $change): void {
    $media = tikTokDeliveryVideo();
    if ($change === 'type') {
        $media->forceFill(['mime' => 'text/html'])->save();
    } else {
        Storage::disk('s3')->put($media->path, 'changed');
    }
    $url = app(PublicMediaUrl::class)->forTikTok($media);

    $this->get($url)->assertStatus($change === 'type' ? 404 : 409);
})->with(['type', 'bytes']);

test('TikTok media reads only the requested private S3 range with streaming enabled', function (bool $validResponse): void {
    $media = tikTokDeliveryVideo();
    $url = app(PublicMediaUrl::class)->forTikTok($media);
    $client = Mockery::mock(S3Client::class);
    $body = Utils::streamFor('2345');
    $client->shouldReceive('getObject')->once()->withArgs(fn (array $args): bool => $args['Bucket'] === 'private-bucket'
        && $args['Key'] === 'prefix/'.$media->path
        && $args['Range'] === 'bytes=2-5'
        && $args['@http']['stream'] === true)->andReturn(new Result([
            'Body' => $body, 'ContentLength' => 4,
            'ContentRange' => $validResponse ? 'bytes 2-5/10' : 'bytes 0-9/10',
        ]));
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('exists')->once()->with($media->path)->andReturnTrue();
    $disk->shouldReceive('size')->once()->with($media->path)->andReturn(10);
    $disk->shouldReceive('getClient')->once()->andReturn($client);
    $disk->shouldReceive('getConfig')->once()->andReturn(['bucket' => 'private-bucket']);
    $disk->shouldReceive('path')->once()->with($media->path)->andReturn('prefix/'.$media->path);
    $disk->shouldNotReceive('readStream');
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

    $response = $this->get($url, ['Range' => 'bytes=2-5'])->assertStatus($validResponse ? 206 : 503);
    if ($validResponse) {
        expect($response->streamedContent())->toBe('2345');
    }
    expect($body->isReadable())->toBeFalse();
})->with([true, false]);
