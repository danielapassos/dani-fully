<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\DiscordPublishConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const DISCORD_HOOK = 'https://discord.com/api/webhooks/1/tok';

/**
 * @param  list<string>  $segments
 * @param  list<PostMedia>  $media
 * @param  array<string, mixed>  $targetOverrides
 */
function discordContext(array $segments, array $media = [], array $targetOverrides = [], string $webhookUrl = DISCORD_HOOK): PublishContext
{
    $target = PostTarget::factory()->create(array_merge(['platform' => Platform::Discord->value], $targetOverrides));
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Discord->value,
        'remote_account_id' => '1',
    ]);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['webhook_url' => $webhookUrl],
    );
}

test('discord posts a single text message with wait=true and returns the message id', function () {
    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm1'])]);

    $result = app(DiscordPublishConnector::class)->publish(discordContext(['hello world']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['m1']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'wait=true')
        && $request['content'] === 'hello world');
});

test('discord treats a lost webhook response as an unconfirmed outcome', function () {
    Http::fake(fn () => throw new ConnectionException('connection lost after publish'));

    $result = app(DiscordPublishConnector::class)->publish(discordContext(['possibly live']));

    expect($result->errorKind)->toBe(ErrorKind::Unknown)
        ->and($result->errorKind?->isRetryable())->toBeFalse()
        ->and($result->errorMessage)->toContain('check Discord');
});

test('discord preserves the webhook thread and requires a saved-message receipt', function (string $query, bool $withMedia) {
    $media = [];
    if ($withMedia) {
        Storage::fake('public');
        Storage::disk('public')->put('media/thread.jpg', 'thread-image-bytes');
        $media[] = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/thread.jpg', 'mime' => 'image/jpeg']);
    }

    Http::fake([DISCORD_HOOK.'*' => Http::response(['id' => 'thread-message'])]);

    $result = app(DiscordPublishConnector::class)->publish(
        discordContext(['hello thread'], $media, webhookUrl: DISCORD_HOOK.$query),
    );

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['thread-message']);

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($withMedia): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/webhooks/1/tok'
            && $parameters === ['thread_id' => '123456789', 'wait' => 'true']
            && ($withMedia
                ? str_contains($request->body(), 'thread-image-bytes')
                : $request['content'] === 'hello thread');
    });
})->with([
    'text in existing thread' => ['?thread_id=123456789', false],
    'override disabled receipt' => ['?thread_id=123456789&wait=false', false],
    'attachment in existing thread' => ['?thread_id=123456789&wait=false', true],
]);

test('discord posts each segment as its own sequential message and accumulates remote_ids', function () {
    Http::fake([DISCORD_HOOK.'?wait=true' => Http::sequence()
        ->push(['id' => 'm1'])
        ->push(['id' => 'm2'])]);

    $context = discordContext(['first', 'second']);
    $result = app(DiscordPublishConnector::class)->publish($context);

    expect($result->remoteIds)->toBe(['m1', 'm2'])
        ->and($context->target->fresh()->remote_ids)->toBe(['m1', 'm2'])
        ->and($context->target->fresh()->remote_id)->toBe('m1');
});

test('discord resumes a partial thread from persisted remote_ids', function () {
    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm2'])]);

    $context = discordContext(['first', 'second'], [], [
        'remote_id' => 'm1',
        'remote_ids' => ['m1'],
    ]);
    $result = app(DiscordPublishConnector::class)->publish($context);

    expect($result->remoteIds)->toBe(['m1', 'm2']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['content'] === 'second');
});

test('discord attaches media to the first segment as multipart with payload_json', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm1'])]);

    $result = app(DiscordPublishConnector::class)->publish(discordContext(['look'], [$media]));

    expect($result->remoteIds)->toBe(['m1']);
    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($request->url(), 'wait=true')
            && str_contains($body, 'name="payload_json"')
            && str_contains($body, 'name="files[0]"');
    });
});

test('discord attaches only media resolved for this target', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/excluded.jpg', 'excluded-bytes');
    Storage::disk('public')->put('media/selected.jpg', 'selected-bytes');

    $excluded = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/excluded.jpg', 'mime' => 'image/jpeg']);
    $selected = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/selected.jpg', 'mime' => 'image/jpeg']);
    $base = discordContext(['look'], [$excluded, $selected]);
    $context = new PublishContext(
        target: $base->target,
        segments: $base->segments,
        media: $base->media,
        account: $base->account,
        credentials: $base->credentials,
        mediaBySection: [0 => [$selected]],
    );

    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm1'])]);

    expect(app(DiscordPublishConnector::class)->publish($context)->isSuccessful())->toBeTrue();
    Http::assertSent(fn ($request): bool => str_contains($request->body(), 'selected-bytes')
        && ! str_contains($request->body(), 'excluded-bytes'));
});

test('discord keeps section-two media on the second message', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/second.jpg', 'second-section-bytes');
    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/second.jpg',
        'mime' => 'image/jpeg',
    ]);
    $base = discordContext(['first', 'second'], [$media]);
    $context = new PublishContext(
        target: $base->target,
        segments: $base->segments,
        media: $base->media,
        account: $base->account,
        credentials: $base->credentials,
        mediaBySection: [0 => [], 1 => [$media]],
    );

    Http::fake([DISCORD_HOOK.'?wait=true' => Http::sequence()
        ->push(['id' => 'm1'])
        ->push(['id' => 'm2'])]);

    expect(app(DiscordPublishConnector::class)->publish($context)->remoteIds)
        ->toBe(['m1', 'm2']);

    $requests = Http::recorded()->map(fn (array $record): string => $record[0]->body())->values();
    expect($requests)->toHaveCount(2)
        ->and($requests[0])->not->toContain('second-section-bytes')
        ->and($requests[1])->toContain('second-section-bytes');
});

test('discord posts a caption-less media message', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm1'])]);

    expect(app(DiscordPublishConnector::class)->publish(discordContext([''], [$media]))->isSuccessful())->toBeTrue();
});

test('discord rejects a message with neither text nor media', function () {
    $result = app(DiscordPublishConnector::class)->publish(discordContext(['']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);
});

test('discord fails fast with no webhook url and makes no http calls', function () {
    Http::fake();
    $context = discordContext(['hi']);
    $context = new PublishContext(
        target: $context->target,
        segments: ['hi'],
        media: [],
        account: $context->account,
        credentials: [],
    );

    $result = app(DiscordPublishConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::AuthExpired);
    Http::assertNothingSent();
});

test('discord maps 429 to RateLimited honouring retry-after', function () {
    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['message' => 'slow'], 429, ['Retry-After' => '3'])]);

    $result = app(DiscordPublishConnector::class)->publish(discordContext(['hi']));

    expect($result->errorKind)->toBe(ErrorKind::RateLimited)
        ->and($result->retryAfter)->toBe(3);
});

test('discord delete removes each message best-effort', function () {
    Http::fake([
        DISCORD_HOOK.'/messages/m1' => Http::response([], 404),
        DISCORD_HOOK.'/messages/m2' => Http::response([], 204),
    ]);

    $target = PostTarget::factory()->create([
        'platform' => Platform::Discord->value,
        'remote_id' => 'm1',
        'remote_ids' => ['m1', 'm2'],
    ]);

    app(DiscordPublishConnector::class)->delete($target, ['webhook_url' => DISCORD_HOOK]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/m1') && $request->method() === 'DELETE');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/m2') && $request->method() === 'DELETE');
});

test('discord deletes messages in the existing webhook thread', function () {
    Http::fake([
        DISCORD_HOOK.'/messages/m1?thread_id=123456789' => Http::response([], 204),
        DISCORD_HOOK.'/messages/m2?thread_id=123456789' => Http::response([], 404),
    ]);

    $target = PostTarget::factory()->create([
        'platform' => Platform::Discord->value,
        'remote_id' => 'm1',
        'remote_ids' => ['m1', 'm2'],
    ]);

    app(DiscordPublishConnector::class)->delete($target, [
        'webhook_url' => DISCORD_HOOK.'?thread_id=123456789&wait=false',
    ]);

    Http::assertSentCount(2);
    foreach (['m1', 'm2'] as $id) {
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === DISCORD_HOOK.'/messages/'.$id.'?thread_id=123456789');
    }
});
