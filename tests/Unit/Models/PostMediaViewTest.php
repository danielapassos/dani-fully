<?php

declare(strict_types=1);

use App\Models\PostMedia;

test('media views expose source dimensions without changing the stored media', function (?int $width, ?int $height): void {
    $media = PostMedia::factory()->create([
        'mime' => 'image/jpeg',
        'width' => $width,
        'height' => $height,
    ]);
    $originalPath = $media->path;
    $originalUrl = $media->url();

    $view = $media->toView();

    expect($view)->toHaveKeys(['width', 'height', 'size_bytes'])
        ->and($view['width'])->toBe($width)
        ->and($view['height'])->toBe($height)
        ->and($view['size_bytes'])->toBe($media->size_bytes)
        ->and($view['url'])->toBe($originalUrl)
        ->and($media->fresh()->path)->toBe($originalPath)
        ->and($media->isDirty())->toBeFalse();
})->with([
    'portrait' => [1080, 1350],
    'landscape' => [1200, 800],
    'legacy dimensions unavailable' => [null, null],
]);
