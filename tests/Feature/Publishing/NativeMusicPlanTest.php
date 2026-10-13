<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\InstagramConnector;
use App\Services\Publishing\PhotoMusic;
use App\Services\Publishing\TikTokInboxHandoff;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set(['services.tiktok.publishing_provider' => 'native', 'services.tiktok.inbox_enabled' => true, 'services.tiktok.direct_post_enabled' => false]);
});

test('native video music holds direct publication but permits a TikTok inbox handoff even before selection', function (Platform $platform, bool $direct, array $expected): void {
    config(['services.tiktok.direct_post_enabled' => $direct]);
    $target = PostTarget::factory()->create(['platform' => $platform, 'content_override' => ['music' => ['mode' => 'native', 'required' => true, 'track' => '']]]);
    $video = PostMedia::factory()->video()->create();
    expect(app(PhotoMusic::class)->issues($target, [$video]))->toBe($expected);
    Http::assertNothingSent();
})->with([
    [Platform::Instagram, false, ['music_requires_native_completion']],
    [Platform::TikTok, false, []],
    [Platform::TikTok, true, ['music_requires_native_completion']],
    [Platform::YouTube, false, ['music_requires_supported_media']],
]);

test('music cannot be attached by a plan flag and native plans reject mixed media', function (): void {
    $target = PostTarget::factory()->create(['platform' => Platform::TikTok, 'content_override' => ['music' => ['mode' => 'native', 'track' => 'Song']]]);
    expect(app(PhotoMusic::class)->issues($target, [PostMedia::factory()->video()->create(), PostMedia::factory()->create()]))->toBe(['music_requires_supported_media']);
    $validation = Validator::make(['targets' => [['content_override' => ['music' => ['track' => 'Song', 'audio_attached' => true]]]]], PhotoMusic::draftRules());
    expect($validation->fails())->toBeTrue();
});

test('music plan validation rejects unsafe or unsupported instructions', function (array $plan, string $field): void {
    $validation = Validator::make(['targets' => [['content_override' => ['music' => $plan]]]], PhotoMusic::draftRules());
    expect($validation->fails())->toBeTrue()->and($validation->errors()->has('targets.0.content_override.music.'.$field))->toBeTrue();
})->with([
    [['source_url' => 'javascript:alert(1)'], 'source_url'],
    [['source_url' => 'http://example.test/audio'], 'source_url'],
    [['music_volume' => 101], 'music_volume'],
    [['original_volume' => -1], 'original_volume'],
    [['start_seconds' => -1], 'start_seconds'],
    [['duration_seconds' => 0], 'duration_seconds'],
    [['mode' => 'api'], 'mode'],
    [['availability_checked_at' => '2100-01-01'], 'availability_checked_at'],
]);

test('handoff keeps the exact plan bound to the target without claiming catalog or audio verification', function (): void {
    $plan = ['mode' => 'native', 'required' => true, 'track' => 'Song', 'artist' => 'Artist', 'version' => 'Official remix', 'native_audio_id' => 'audio-42', 'source_url' => 'https://www.instagram.com/reels/audio/42/', 'start_seconds' => 12.5, 'duration_seconds' => 15, 'music_volume' => 0, 'original_volume' => 100, 'sync_notes' => 'Keep narration clear', 'availability_checked_at' => now()->subMinute()->toIso8601String(), 'availability_note' => 'Manual check on this account'];
    $target = PostTarget::factory()->create(['platform' => Platform::TikTok, 'status' => PostTargetStatus::AwaitingAction, 'sections' => ['Approved caption'], 'content_override' => ['music' => $plan]]);
    PostMedia::factory()->video()->create(['post_id' => $target->post_id]);
    expect(Validator::make(['targets' => [['content_override' => ['music' => $plan]]]], PhotoMusic::draftRules())->passes())->toBeTrue();
    $handoff = app(PhotoMusic::class)->handoff($target);
    expect($handoff['plan'])->toBe($plan)
        ->and($handoff['connected_account_id'])->toBe($target->connected_account_id)
        ->and($handoff['format'])->toBe($target->format->value)
        ->and($handoff['audio_attached'])->toBeFalse()
        ->and($handoff['catalog_verified'])->toBeFalse()
        ->and(app(TikTokInboxHandoff::class)->forTarget($target)['instructions'])->toContain('exact version, excerpt, volume and sync');
    $target->forceFill(['status' => PostTargetStatus::Published, 'remote_id' => 'public-id'])->save();
    expect(app(PhotoMusic::class)->handoff($target->fresh()))->toBeNull();
    Http::assertNothingSent();
});

test('explicit existing audio and no added music decisions do not impose native completion', function (string $mode): void {
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'content_override' => ['music' => ['mode' => $mode, 'required' => false]]]);
    expect(app(PhotoMusic::class)->issues($target, [PostMedia::factory()->video()->create()]))->toBe([])
        ->and(app(PhotoMusic::class)->handoff($target))->toBeNull();
    $invalid = Validator::make(['targets' => [['content_override' => ['music' => ['mode' => $mode, 'track' => 'Still required', 'required' => true]]]]], PhotoMusic::draftRules());
    expect($invalid->fails())->toBeTrue();
})->with(['none', 'existing_audio']);

test('Instagram connector refuses required native music before any provider request', function (): void {
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'content_override' => ['music' => ['mode' => 'native', 'required' => true]]]);
    $video = PostMedia::factory()->video()->create(['post_id' => $target->post_id]);
    $context = new PublishContext($target, ['Caption'], [$video], $target->account, ['access_token' => 'test-token']);
    $result = app(InstagramConnector::class)->publish($context);
    expect($result->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});
