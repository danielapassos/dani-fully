<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\InstagramConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function trialConnectorContext(?array $params = ['graduation_strategy' => 'MANUAL'], PostFormat $format = PostFormat::Reels, bool $instagramLogin = true, array $uploadState = []): PublishContext
{
    Storage::fake('public');
    Storage::disk('public')->put('media/trial.mp4', 'video-bytes');
    $target = PostTarget::factory()->create([
        'platform' => Platform::Instagram,
        'format' => $format,
        'content_override' => ['instagram' => ['trial_params' => $params]],
        'media_upload_state' => $uploadState,
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram,
        'remote_account_id' => 'ig-trial',
        'capabilities' => ['instagram_login' => $instagramLogin],
    ]);
    $video = PostMedia::factory()->create([
        'workspace_id' => $target->post->workspace_id,
        'disk' => 'public', 'path' => 'media/trial.mp4', 'mime' => 'video/mp4', 'kind' => 'video',
    ]);

    return new PublishContext($target, ['Try this'], [$video], $account, ['access_token' => 'test-token']);
}

function fakeTrialContainer(string $status = 'FINISHED'): void
{
    Http::fake([
        '*/ig-trial/media' => Http::response(['id' => 'trial-container']),
        '*/trial-container*' => Http::response(['status_code' => $status]),
        '*/ig-trial/media_publish' => Http::response(['id' => 'trial-media']),
    ]);
}

test('trial reel sends the selected strategy with either Instagram login method and Reel format', function (string $strategy, bool $instagramLogin, PostFormat $format) {
    $params = ['graduation_strategy' => $strategy];
    $context = trialConnectorContext($params, $format, $instagramLogin);
    fakeTrialContainer();

    $result = app(InstagramConnector::class)->publish($context);

    expect($result->remoteIds)->toBe(['trial-media'])
        ->and($context->target->fresh()->media_upload_state['container']['metadata']['instagram_trial_params'])->toBe($params);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ig-trial/media')
        && str_starts_with($request->url(), $instagramLogin ? 'https://graph.instagram.com/' : 'https://graph.facebook.com/')
        && $request['media_type'] === 'REELS'
        && $request['trial_params'] === json_encode($params)
        && ! isset($request['share_to_feed']));
})->with(['MANUAL', 'SS_PERFORMANCE'])->with([true, false])->with([PostFormat::Feed, PostFormat::Reels]);

test('a trial reel retains its custom cover', function () {
    $context = trialConnectorContext();
    Storage::disk('public')->put('media/cover.jpg', 'jpeg-bytes');
    $cover = PostMedia::factory()->create([
        'workspace_id' => $context->target->post->workspace_id,
        'disk' => 'public', 'path' => 'media/cover.jpg', 'mime' => 'image/jpeg',
    ]);
    $context->target->update(['content_override' => ['instagram' => [
        'cover_media_id' => $cover->id, 'trial_params' => ['graduation_strategy' => 'MANUAL'],
    ]]]);
    fakeTrialContainer();

    expect(app(InstagramConnector::class)->publish($context)->isSuccessful())->toBeTrue();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ig-trial/media')
        && str_contains((string) $request['cover_url'], 'cover.jpg')
        && $request['trial_params'] === '{"graduation_strategy":"MANUAL"}');
});

test('regular reels omit trial parameters', function () {
    $context = trialConnectorContext(null);
    fakeTrialContainer();

    expect(app(InstagramConnector::class)->publish($context)->isSuccessful())->toBeTrue();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ig-trial/media')
        && ! isset($request['trial_params']));
});

test('trial validation rejects unsupported media or formats before contacting Instagram', function (string $invalid) {
    $context = trialConnectorContext(format: $invalid === 'story' ? PostFormat::Story : PostFormat::Reels);
    if ($invalid === 'image') {
        $context->media[0]->forceFill(['mime' => 'image/jpeg', 'kind' => 'image']);
    }
    if ($invalid === 'carousel') {
        $context = new PublishContext($context->target, $context->segments, [$context->media[0], $context->media[0]], $context->account, $context->credentials);
    }
    if ($invalid === 'strategy') {
        $context->target->forceFill(['content_override' => ['instagram' => ['trial_params' => ['graduation_strategy' => 'AUTO']]]]);
    }
    Http::fake();

    expect(app(InstagramConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
})->with(['story', 'image', 'carousel', 'strategy']);

test('trial reel processing resumes the same container and strategy without another upload', function () {
    $context = trialConnectorContext();
    Http::fake([
        '*/ig-trial/media' => Http::response(['id' => 'trial-container']),
        '*/trial-container*' => Http::sequence()->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'FINISHED']),
        '*/ig-trial/media_publish' => Http::response(['id' => 'trial-media']),
    ]);
    expect(app(InstagramConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::MediaProcessing);

    expect(app(InstagramConnector::class)->publish($context)->remoteIds)->toBe(['trial-media']);
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/ig-trial/media')))->toHaveCount(1);
});

test('Instagram refuses audience changes after container creation', function (?array $original, ?array $changed) {
    $context = trialConnectorContext($original);
    fakeTrialContainer('IN_PROGRESS');
    app(InstagramConnector::class)->publish($context);
    $context->target->forceFill(['content_override' => ['instagram' => ['trial_params' => $changed]]]);
    Http::fake();

    expect(app(InstagramConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
})->with([
    'trial to regular' => [['graduation_strategy' => 'MANUAL'], null],
    'regular to trial' => [null, ['graduation_strategy' => 'MANUAL']],
    'manual to auto' => [['graduation_strategy' => 'MANUAL'], ['graduation_strategy' => 'SS_PERFORMANCE']],
]);

test('a legacy regular container cannot be reused for a requested trial reel', function () {
    $context = trialConnectorContext(uploadState: ['container' => ['remote_ref' => 'legacy-container', 'state' => 'processing']]);
    Http::fake();

    expect(app(InstagramConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

test('trial audience choice is frozen even when the container response is lost', function () {
    $context = trialConnectorContext();
    Http::fake(function () {
        throw new ConnectionException('Lost container response');
    });
    expect(app(InstagramConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Network)
        ->and($context->target->fresh()->media_upload_state['container']['metadata']['instagram_trial_params'])
        ->toBe(['graduation_strategy' => 'MANUAL']);

    $context->target->forceFill(['content_override' => ['instagram' => ['trial_params' => null]]]);
    Http::fake();
    expect(app(InstagramConnector::class)->publish($context)->errorKind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

test('a trial eligibility rejection never falls back to a regular reel', function () {
    $context = trialConnectorContext();
    Http::fake(['*/ig-trial/media' => Http::response(['error' => ['message' => 'Account is not eligible for trial reels']], 400)]);

    $result = app(InstagramConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse()
        ->and($context->target->fresh()->remote_id)->toBeNull();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'media_publish'));
});
