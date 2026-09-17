<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\MetricoolTikTokConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function metricoolTikTokContext(array $options = []): PublishContext
{
    $account = ConnectedAccount::factory()->create(['platform' => Platform::TikTok, 'handle' => 'shesoutdooor']);
    config()->set('services.metricool', [
        'token' => 'metricool-test-secret', 'user_id' => '4626495', 'workspace_id' => $account->workspace_id,
        'accounts' => [$account->id => '6082760'],
    ]);
    $media = PostMedia::factory()->video()->create(['disk' => 'local', 'path' => 'bridge.mp4', 'size_bytes' => 10, 'duration_seconds' => 5]);
    Storage::disk('local')->put($media->path, '0123456789');
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id, 'platform' => Platform::TikTok,
        'content_override' => ['tiktok' => array_replace([
            'privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_comment' => true, 'disable_duet' => true, 'disable_stitch' => true,
            'commercial_content' => false, 'brand_organic_toggle' => false, 'brand_content_toggle' => false,
            'is_aigc' => true, 'music_usage_confirmed' => true, 'branded_content_policy_confirmed' => false,
        ], $options)],
    ]);

    return new PublishContext($target, ['Approved Berlin caption'], [$media], $account, []);
}

function fakeMetricoolTikTok(string $status = 'PUBLISHED', ?Closure $create = null, ?Closure $list = null, ?string $publicUrl = null, ?Closure $creator = null): void
{
    $post = null;
    $creatorReads = 0;
    Http::fake(function (Request $request) use (&$post, &$creatorReads, $status, $create, $list, $publicUrl, $creator) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/admin/simpleProfiles') {
            return Http::response([['id' => 6082760, 'tiktok' => 'shesoutdooor']]);
        }
        if ($path === '/api/v2/scheduler/catalogs/tiktok/creator-info') {
            $creatorReads++;
            $creatorInfo = [
                'creatorUsername' => 'shesoutdooor', 'creatorNickname' => 'Dani',
                'privacyLevelOptions' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'], 'commentDisabled' => false,
                'duetDisabled' => false, 'stitchDisabled' => false, 'maxVideoPostDurationSec' => 600,
            ];

            return Http::response(['data' => $creator ? $creator($creatorInfo, $creatorReads) : $creatorInfo]);
        }
        if ($path === '/api/v2/media/s3/upload-transactions') {
            if ($request->method() === 'PUT') {
                return Http::response(['data' => ['uploadType' => 'SIMPLE', 'fileUrl' => 'https://metricool-upload.s3.eu-west-1.amazonaws.com/video.mp4', 'presignedUrl' => 'https://metricool-upload.s3.eu-west-1.amazonaws.com/video.mp4?signature=private']]);
            }

            return Http::response(['data' => ['fileUrl' => 'https://metricool-download.s3.eu-west-1.amazonaws.com/video.mp4']]);
        }
        if (str_contains($request->url(), 'metricool-upload.s3.')) {
            return Http::response('', 200, ['ETag' => 'test-etag']);
        }
        if ($path === '/api/v2/scheduler/posts' && $request->method() === 'POST') {
            $post = [...$request->data(), 'id' => 123, 'providers' => [['network' => 'tiktok', 'status' => $status, 'publicUrl' => $publicUrl ?? 'https://www.tiktok.com/@shesoutdooor/video/7680000000000000001']]];

            return $create ? $create($request, $post) : Http::response(['data' => $post]);
        }
        if ($path === '/api/v2/scheduler/posts' && $request->method() === 'GET') {
            return $list ? $list($request, $post) : Http::response(['data' => $post ? [$post] : []]);
        }
        if ($path === '/api/v2/scheduler/posts/123') {
            return Http::response(['data' => $post]);
        }
        throw new RuntimeException('Unexpected fake Metricool request.');
    });
}

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
});

test('metricool publishes the exact selected account and explicit controls with no native token', function () {
    $context = metricoolTikTokContext(['disable_comment' => false, 'video_cover_timestamp_ms' => 1000]);
    fakeMetricoolTikTok();
    $result = app(MetricoolTikTokConnector::class)->publish($context);

    expect($result->remoteIds)->toBe(['7680000000000000001'])
        ->and($context->target->fresh()->media_upload_state['_metricool']['post_id'])->toBe(123)
        ->and($context->target->media_upload_state['_tiktok_provider'])->toBe('metricool');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['providers'] === [['network' => 'TIKTOK']]
        && $request['text'] === 'Approved Berlin caption'
        && $request['autoPublish'] === true && $request['draft'] === false
        && $request['tiktokData']['isAigc'] === true
        && $request['tiktokData']['autoAddMusic'] === false
        && $request['tiktokData']['disableComment'] === false
        && $request['tiktokData']['privacyOption'] === 'public_to_everyone'
        && $request['videoCoverMilliseconds'] === 1000
        && str_contains($request->url(), 'blogId=6082760'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tiktokapis.com')
        || (str_contains($request->url(), '.amazonaws.com') && $request->hasHeader('X-Mc-Auth')));
});

test('metricool resumes saved jobs without reuploading or recreating them and preserves original options', function () {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok('PENDING');
    $connector = app(MetricoolTikTokConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);
    $context->target->forceFill(['content_override' => ['tiktok' => ['privacy_level' => 'SELF_ONLY']]])->save();
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($context->target->media_upload_state['_metricool']['post_options']['privacy_level'])->toBe('PUBLIC_TO_EVERYONE');
    expect(Http::recorded(fn (Request $r): bool => $r->method() === 'POST'))->toHaveCount(1);
});

test('metricool reconciles a lost create response by exact uuid and never repeats the post', function () {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok(create: fn () => throw new ConnectionException('signed=secret'));
    $connector = app(MetricoolTikTokConnector::class);
    $first = $connector->publish($context);
    expect($first->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($first->errorMessage)->not->toContain('secret');
    expect($connector->publish($context)->remoteIds)->toBe(['7680000000000000001']);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains($r->url(), 'filter='));
});

test('an unresolved create never gets resubmitted after an empty lookup', function () {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok(create: fn () => Http::response(['error' => 'private token=secret'], 503), list: fn () => Http::response(['data' => []]));
    $connector = app(MetricoolTikTokConnector::class);
    for ($attempt = 0; $attempt < 4; $attempt++) {
        expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);
    }
    expect($connector->publish($context)->outcome)->toBe('awaiting_action');
    expect(Http::recorded(fn (Request $r): bool => $r->method() === 'POST'))->toHaveCount(1);
});

test('metricool does not call processing or confirmation a public post', function (string $status, string $outcome, ?ErrorKind $kind) {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok($status);
    $result = app(MetricoolTikTokConnector::class)->publish($context);
    expect($result->outcome)->toBe($outcome)->and($result->errorKind)->toBe($kind)->and($result->remoteIds)->toBe([]);
})->with([
    ['PENDING', 'published', ErrorKind::MediaProcessing],
    ['PUBLISHING', 'published', ErrorKind::MediaProcessing],
    ['AWAITING_CONFIRMATION', 'awaiting_action', null],
    ['DRAFT', 'awaiting_action', null],
    ['ERROR', 'published', ErrorKind::Validation],
]);

test('metricool refuses a completed url belonging to another account or unsafe host', function (string $url) {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok(publicUrl: $url);
    $result = app(MetricoolTikTokConnector::class)->publish($context);
    expect($result->outcome)->toBe('awaiting_action')->and($result->remoteIds)->toBe([]);
})->with([
    'https://www.tiktok.com/@mommygorl/video/7680000000000000001',
    'https://www.tiktok.com.attacker.test/@shesoutdooor/video/7680000000000000001',
    'https://user@www.tiktok.com/@shesoutdooor/video/7680000000000000001',
    '',
]);

test('metricool blocks unreviewed options before uploading or creating a post', function () {
    $context = metricoolTikTokContext(['music_usage_confirmed' => false]);
    fakeMetricoolTikTok();
    expect(app(MetricoolTikTokConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
});

test('metricool reports restricted visibility as completed not public', function () {
    $context = metricoolTikTokContext(['privacy_level' => 'SELF_ONLY']);
    fakeMetricoolTikTok();
    $result = app(MetricoolTikTokConnector::class)->publish($context);
    expect($result->outcome)->toBe('completed')->and($result->remoteIds)->toBe([]);
});

test('metricool polls the original accepted job after configuration mapping changes', function () {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok('PENDING');
    $connector = app(MetricoolTikTokConnector::class);
    $connector->publish($context);
    config()->set('services.metricool.accounts', []);
    config()->set('services.metricool.workspace_id', 'changed');
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/posts/123?') && str_contains($r->url(), 'blogId=6082760'));
    expect(Http::recorded(fn (Request $r): bool => $r->method() === 'POST'))->toHaveCount(1);
});

test('metricool never claims that a remote TikTok video was deleted', function () {
    $context = metricoolTikTokContext();
    expect(fn () => app(MetricoolTikTokConnector::class)->delete($context->target, []))
        ->toThrow(RuntimeException::class, 'cannot confirm remote deletion');
    Http::assertNothingSent();
});

test('metricool keeps ambiguous timeout and conflict responses pinned without retrying create', function (int $status) {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok(create: fn () => Http::response([], $status), list: fn () => Http::response(['data' => []]));
    $connector = app(MetricoolTikTokConnector::class);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);
    expect($connector->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);
    expect(Http::recorded(fn (Request $r): bool => $r->method() === 'POST'))->toHaveCount(1);
})->with([408, 409]);

test('metricool rechecks the creator after upload and blocks a changed destination or permissions', function (array $change) {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok(creator: fn (array $creator, int $reads): array => $reads === 1 ? $creator : array_replace($creator, $change));
    $result = app(MetricoolTikTokConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && str_contains($r->url(), '/upload-transactions'));
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    expect($context->target->fresh()->media_upload_state['_metricool']['create_outcome_unknown'] ?? false)->toBeFalse();
})->with([
    'brand reconnected' => [['creatorUsername' => 'mommygorl']],
    'privacy restricted' => [['privacyLevelOptions' => ['SELF_ONLY']]],
    'duration limit lowered' => [['maxVideoPostDurationSec' => 4]],
]);

test('metricool does not submit a post deleted or cancelled while its video uploads', function (string $change) {
    $context = metricoolTikTokContext();
    fakeMetricoolTikTok(creator: function (array $creator, int $reads) use ($context, $change): array {
        if ($reads === 2) {
            $target = $context->target->fresh();
            match ($change) {
                'post_deleted' => $target->post->forceFill(['status' => PostStatus::Deleted, 'deleted_at' => now()])->save(),
                'post_missing' => $target->post->delete(),
                'target_missing' => $target->delete(),
                default => $target->forceFill(['status' => PostTargetStatus::from($change)])->save(),
            };
        }

        return $creator;
    });
    $result = app(MetricoolTikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Unsupported);
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
})->with(['deleted', 'deleting', 'skipped', 'post_deleted', 'post_missing', 'target_missing']);
