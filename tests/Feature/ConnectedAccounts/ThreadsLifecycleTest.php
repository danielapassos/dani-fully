<?php

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Events\ConnectedAccountConnected;
use App\Models\AccountMetric;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\PostTargetAttempt;
use App\Models\PostTargetMetric;
use App\Models\PostTargetReply;
use App\Models\Workspace;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('services.threads.client_secret', 'threads-test-secret');
    Http::preventStrayRequests();
});

/** @param array<string, mixed> $overrides */
function threadsLifecycleSignature(array $overrides = [], string $secret = 'threads-test-secret'): string
{
    $payload = rtrim(strtr(base64_encode(json_encode(array_replace([
        'algorithm' => 'HMAC-SHA256',
        'user_id' => '123456789',
        'issued_at' => now()->timestamp,
        'expires' => 0,
    ], $overrides), JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=').'.'.$payload;
}

function threadsLifecycleAccount(?Workspace $workspace = null, string $remoteId = '123456789', Platform $platform = Platform::Threads): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => ($workspace ?? Workspace::factory()->create())->id,
        'platform' => $platform,
        'remote_account_id' => $remoteId,
        'capabilities' => ['oauth_scopes' => ['threads_basic', 'threads_content_publish']],
        'token_expires_at' => now()->addDay(),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id]);

    return $account;
}

test('signed deauthorization revokes only the matching Threads credentials across workspaces', function () {
    $first = threadsLifecycleAccount();
    $second = threadsLifecycleAccount();
    $otherUser = threadsLifecycleAccount(remoteId: '987654321');
    $otherPlatform = threadsLifecycleAccount(platform: Platform::Instagram);
    $post = Post::factory()->create(['workspace_id' => $first->workspace_id]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'connected_account_id' => $first->id, 'platform' => Platform::Threads,
    ]);
    Context::add('workspace_id', $otherUser->workspace_id);

    $this->post('/accounts/threads/deauthorize', ['signed_request' => threadsLifecycleSignature()])
        ->assertNoContent()
        ->assertHeaderMissing('Set-Cookie');

    foreach ([$first, $second] as $account) {
        $fresh = ConnectedAccount::withoutGlobalScopes()->findOrFail($account->id);
        expect($fresh->status)->toBe(ConnectedAccountStatus::NeedsAttention)
            ->and($fresh->disabled_at)->toBeNull()
            ->and($fresh->token_expires_at)->toBeNull()
            ->and($fresh->capabilities)->toBeNull()
            ->and($fresh->secret()->exists())->toBeFalse()
            ->and($fresh->canPublish())->toBeFalse();
    }

    foreach ([$otherUser, $otherPlatform] as $account) {
        $fresh = ConnectedAccount::withoutGlobalScopes()->findOrFail($account->id);
        expect($fresh->status)->toBe(ConnectedAccountStatus::Active)
            ->and($fresh->secret()->exists())->toBeTrue()
            ->and($fresh->disabled_at)->toBeNull();
    }

    $this->assertModelExists($post);
    $this->assertModelExists($target);
    $this->post('/accounts/threads/deauthorize', ['signed_request' => threadsLifecycleSignature()])->assertNoContent();
});

test('signed data deletion removes scoped platform data and preserves authored posts media and other accounts', function () {
    $workspace = Workspace::factory()->create();
    $first = threadsLifecycleAccount($workspace);
    $second = threadsLifecycleAccount();
    $otherUser = threadsLifecycleAccount($workspace, '987654321');
    $otherPlatform = threadsLifecycleAccount($workspace, platform: Platform::Instagram);
    $workspace->update(['default_connected_account_id' => $first->id]);
    $set = AccountSet::factory()->create(['workspace_id' => $workspace->id]);
    $set->accounts()->attach([$first->id, $otherPlatform->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'base_text' => 'Keep my authored post.']);
    $target = PostTarget::factory()->published()->create([
        'post_id' => $post->id, 'connected_account_id' => $first->id, 'platform' => Platform::Threads,
    ]);
    $otherTarget = PostTarget::factory()->create([
        'post_id' => $post->id, 'connected_account_id' => $otherPlatform->id, 'platform' => Platform::Instagram,
    ]);
    $media = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => $post->id]);
    $placement = PostMediaPlacement::factory()->create(['post_target_id' => $target->id, 'post_media_id' => $media->id]);
    $accountMetric = AccountMetric::factory()->create(['connected_account_id' => $first->id]);
    $metric = PostTargetMetric::factory()->create(['post_target_id' => $target->id]);
    $attempt = PostTargetAttempt::factory()->create(['post_target_id' => $target->id]);
    $reply = PostTargetReply::factory()->create([
        'workspace_id' => $workspace->id, 'post_target_id' => $target->id, 'platform' => Platform::Threads,
    ]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $workspace->id, 'connected_account_id' => $first->id, 'platform' => Platform::Threads,
    ]);
    $message = DirectMessage::factory()->create(['workspace_id' => $workspace->id, 'conversation_id' => $conversation->id]);
    Context::add('workspace_id', $otherUser->workspace_id);

    $response = $this->post('/accounts/threads/data-deletion', ['signed_request' => threadsLifecycleSignature()])
        ->assertSuccessful()->assertJsonStructure(['url', 'confirmation_code'])->assertHeaderMissing('Set-Cookie');

    foreach ([$first, $second, $target, $placement, $accountMetric, $metric, $attempt, $reply, $conversation, $message] as $deleted) {
        $this->assertModelMissing($deleted);
    }
    expect(ConnectedAccountSecret::find($first->id))->toBeNull()
        ->and(ConnectedAccountSecret::find($second->id))->toBeNull()
        ->and($workspace->fresh()->default_connected_account_id)->toBeNull()
        ->and($set->accounts()->pluck('connected_accounts.id')->all())->toBe([$otherPlatform->id]);

    foreach ([$workspace, $set, $post, $media, $otherTarget, $otherPlatform, $otherUser] as $preserved) {
        $this->assertModelExists($preserved);
    }
    expect($post->fresh()->base_text)->toBe('Keep my authored post.')
        ->and($otherPlatform->secret()->exists())->toBeTrue()
        ->and($otherUser->secret()->exists())->toBeTrue();

    $url = $response->json('url');
    $code = $response->json('confirmation_code');
    expect($code)->toMatch('/\A[A-Za-z0-9]{40}\z/')
        ->and($url)->not->toContain($first->remote_account_id, $first->id, $workspace->id);
    $this->get($url)->assertSuccessful()->assertSee('Threads data deletion completed')
        ->assertSee($code)->assertDontSee($first->handle)->assertHeader('Referrer-Policy', 'no-referrer');

    $repeat = $this->post('/accounts/threads/data-deletion', ['signed_request' => threadsLifecycleSignature()])->assertSuccessful();
    expect($repeat->json('confirmation_code'))->not->toBe($code);
});

test('deletion status rejects missing altered and expired signatures', function () {
    $receipt = $this->post('/accounts/threads/data-deletion', ['signed_request' => threadsLifecycleSignature()])->assertSuccessful();
    $url = $receipt->json('url');
    $code = $receipt->json('confirmation_code');

    $this->get('/accounts/threads/data-deletion/'.$code)->assertForbidden();
    $this->get(str_replace($code, Str::random(40), $url))->assertForbidden();
    $this->get($url.'&untrusted=value')->assertForbidden();
    $this->get(str_replace('outcome=completed', 'outcome=newer-authorization-retained', $url))->assertForbidden();
    $this->travel(31)->days();
    $this->get($url)->assertForbidden();
});

test('old callbacks cannot revoke or delete a newer Threads authorization', function (string $action, string $connectionMethod) {
    $this->freezeTime();
    $account = threadsLifecycleAccount();
    $owner = $account->connectedBy()->firstOrFail();
    $signedRequest = threadsLifecycleSignature();
    $this->travel(10)->seconds();
    Event::fake([ConnectedAccountConnected::class]);
    $data = new ConnectedAccountData(
        platform: Platform::Threads,
        remoteAccountId: $account->remote_account_id,
        handle: '@new-authorization',
        displayName: 'New authorization',
        avatarUrl: null,
        authMethod: 'oauth',
        accessToken: 'new-authorization-token',
    );
    $connections = app(AccountConnectionService::class);
    $fresh = $connectionMethod === 'store'
        ? $connections->store($data, $owner, $account->workspace_id)
        : $connections->reconnect($account, $data, $owner);

    $response = $this->post('/accounts/threads/'.$action, ['signed_request' => $signedRequest])->assertSuccessful();

    $this->assertModelExists($account);
    expect($fresh->fresh()->authorized_at->getTimestamp())->toBe(now()->getTimestamp())
        ->and($fresh->fresh()->status)->toBe(ConnectedAccountStatus::Active)
        ->and($fresh->secret()->firstOrFail()->access_token)->toBe('new-authorization-token');
    if ($action === 'data-deletion') {
        $this->get($response->json('url'))->assertSuccessful()
            ->assertSee('A newer Threads authorization was preserved.')
            ->assertDontSee('Threads data deletion completed');
    }
})->with(['deauthorize', 'data-deletion'])->with(['store', 'reconnect']);

test('deauthorization preserves manual disable state and reconnect restores otherwise enabled accounts', function (bool $disabled) {
    $this->freezeTime();
    $account = threadsLifecycleAccount();
    $account->update(['authorized_at' => now(), 'disabled_at' => $disabled ? now() : null]);
    $owner = $account->connectedBy()->firstOrFail();
    $authorizedAt = $account->authorized_at;

    $this->post('/accounts/threads/deauthorize', ['signed_request' => threadsLifecycleSignature()])->assertNoContent();
    $revoked = $account->fresh();
    expect($revoked->authorized_at->equalTo($authorizedAt))->toBeTrue()
        ->and($revoked->isDisabled())->toBe($disabled)
        ->and($revoked->publishingRecoveryKind())->toBe($disabled ? 'enable_account' : 'reconnect');
    $this->travel(10)->seconds();
    Event::fake([ConnectedAccountConnected::class]);
    app(AccountConnectionService::class)->reconnect($revoked, new ConnectedAccountData(
        platform: Platform::Threads,
        remoteAccountId: $account->remote_account_id,
        handle: '@restored',
        displayName: null,
        avatarUrl: null,
        authMethod: 'oauth',
        accessToken: 'restored-token',
    ), $owner);

    expect($account->fresh()->isDisabled())->toBe($disabled)
        ->and($account->fresh()->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->secret()->firstOrFail()->access_token)->toBe('restored-token');
})->with([false, true]);

test('a token refresh does not count as a new authorization or defeat a valid deletion request', function () {
    $this->freezeTime();
    $account = threadsLifecycleAccount();
    $account->update(['authorized_at' => now()]);
    $grantTime = $account->authorized_at;
    $this->travel(1)->day();
    $signedRequest = threadsLifecycleSignature();
    $this->travel(10)->seconds();
    Http::fake(['graph.threads.net/refresh_access_token*' => Http::response([
        'access_token' => 'rotated-token', 'expires_in' => 5184000,
    ])]);

    app(TokenManager::class)->fresh($account, force: true);

    expect($account->fresh()->authorized_at->equalTo($grantTime))->toBeTrue()
        ->and($account->fresh()->last_refreshed_at->getTimestamp())->toBe(now()->getTimestamp());
    $this->post('/accounts/threads/data-deletion', ['signed_request' => $signedRequest])->assertSuccessful();
    $this->assertModelMissing($account);
});

test('a completed callback cannot be replayed against a subsequently connected account', function (string $action) {
    $this->freezeTime();
    $account = threadsLifecycleAccount();
    $owner = $account->connectedBy()->firstOrFail();
    $signedRequest = threadsLifecycleSignature();
    $this->post('/accounts/threads/'.$action, ['signed_request' => $signedRequest])->assertSuccessful();
    $this->travel(10)->seconds();
    Event::fake([ConnectedAccountConnected::class]);

    $newGrant = app(AccountConnectionService::class)->store(new ConnectedAccountData(
        platform: Platform::Threads,
        remoteAccountId: $account->remote_account_id,
        handle: '@new-grant-after-callback',
        displayName: null,
        avatarUrl: null,
        authMethod: 'oauth',
        accessToken: 'new-grant-after-callback-token',
    ), $owner, $account->workspace_id);
    $this->post('/accounts/threads/'.$action, ['signed_request' => $signedRequest])->assertSuccessful();

    expect($newGrant->fresh()->status)->toBe(ConnectedAccountStatus::Active)
        ->and($newGrant->fresh()->disabled_at)->toBeNull()
        ->and($newGrant->secret()->firstOrFail()->access_token)->toBe('new-grant-after-callback-token');
})->with(['deauthorize', 'data-deletion']);

test('recovery drafts stay in their source workspaces regardless of ambient workspace context', function () {
    $first = threadsLifecycleAccount();
    $second = threadsLifecycleAccount();
    $sources = [];
    foreach ([$first, $second] as $index => $account) {
        $source = Post::factory()->create([
            'workspace_id' => $account->workspace_id, 'segments' => ['Shared text.'], 'base_text' => 'Shared text.',
        ]);
        PostTarget::factory()->create([
            'post_id' => $source->id, 'connected_account_id' => $account->id,
            'platform' => Platform::Threads, 'content_override' => ['segments' => ['Unique variation '.$index]],
        ]);
        $sources[] = $source;
    }
    Context::add('workspace_id', Workspace::factory()->create()->id);

    $this->post('/accounts/threads/data-deletion', ['signed_request' => threadsLifecycleSignature()])->assertSuccessful();

    foreach ($sources as $index => $source) {
        $draft = Post::withoutGlobalScopes()->where('base_text', 'Unique variation '.$index)->sole();
        expect($draft->workspace_id)->toBe($source->workspace_id)
            ->and($draft->author_id)->toBe($source->author_id);
    }
});

test('deletion recovers distinct authored text without scheduling publishing or moving source attachments', function (bool $hasOverride) {
    Storage::fake('public');
    $account = threadsLifecycleAccount();
    $source = Post::factory()->create([
        'workspace_id' => $account->workspace_id,
        'segments' => ['The shared base text.'],
        'base_text' => 'The shared base text.',
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addHour(),
    ]);
    $segments = ['My first Threads section.', 'My distinct second Threads section.'];
    $target = PostTarget::factory()->create([
        'post_id' => $source->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::Threads,
        'content_override' => $hasOverride ? ['segments' => $segments] : null,
        'sections' => $hasOverride ? ['Rendered Threads text.'] : $segments,
        'segment_breaks' => ['second-section'],
        'section_sources' => [0, 1],
    ]);
    $media = PostMedia::factory()->create(['workspace_id' => $account->workspace_id, 'post_id' => $source->id]);
    Storage::disk('public')->put($media->path, 'unchanged original attachment');
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id, 'post_media_id' => $media->id, 'segment_ref' => 'second-section',
    ]);
    $original = $source->fresh()->getAttributes();
    Bus::fake();

    $this->post('/accounts/threads/data-deletion', ['signed_request' => threadsLifecycleSignature()])->assertSuccessful();

    $draft = Post::withoutGlobalScopes()->whereKeyNot($source->id)->sole();
    expect($draft->segments)->toBe($segments)
        ->and($draft->base_text)->toBe(implode("\n", $segments))
        ->and($draft->status)->toBe(PostStatus::Draft)
        ->and($draft->workspace_id)->toBe($source->workspace_id)
        ->and($draft->author_id)->toBe($source->author_id)
        ->and($draft->account_set_id)->toBeNull()
        ->and($draft->scheduled_at)->toBeNull()
        ->and($draft->auto_repost)->toBeFalse()
        ->and($draft->targets()->exists())->toBeFalse()
        ->and($draft->media()->exists())->toBeFalse()
        ->and($source->fresh()->getAttributes())->toBe($original)
        ->and($media->fresh()->post_id)->toBe($source->id)
        ->and(Storage::disk('public')->get($media->path))->toBe('unchanged original attachment');
    Bus::assertNothingDispatched();

    $this->post('/accounts/threads/data-deletion', ['signed_request' => threadsLifecycleSignature()])->assertSuccessful();
    expect(Post::withoutGlobalScopes()->count())->toBe(2);
})->with([true, false]);

test('data deletion does not recreate a post the author already deleted', function () {
    $account = threadsLifecycleAccount();
    $source = Post::factory()->create([
        'workspace_id' => $account->workspace_id, 'status' => PostStatus::Deleted, 'deleted_at' => now(),
    ]);
    PostTarget::factory()->create([
        'post_id' => $source->id, 'connected_account_id' => $account->id, 'platform' => Platform::Threads,
        'content_override' => ['segments' => ['Previously removed text.']],
    ]);

    $this->post('/accounts/threads/data-deletion', ['signed_request' => threadsLifecycleSignature()])->assertSuccessful();

    expect(Post::withoutGlobalScopes()->count())->toBe(1)
        ->and($source->fresh()->status)->toBe(PostStatus::Deleted);
});

test('a failed deletion transaction returns no completed receipt and rolls back credentials', function () {
    $account = threadsLifecycleAccount();
    Event::listen('eloquent.deleting: '.ConnectedAccount::class, function (): never {
        throw new RuntimeException('Simulated deletion failure.');
    });

    $response = $this->postJson('/accounts/threads/data-deletion', ['signed_request' => threadsLifecycleSignature()]);

    $response->assertServerError()->assertJsonMissingPath('confirmation_code')->assertJsonMissingPath('url');
    $this->assertModelExists($account);
    expect($account->secret()->exists())->toBeTrue();
});

test('malformed and forged callbacks never revoke an account', function (string $kind, int $status) {
    $account = threadsLifecycleAccount();
    $value = match ($kind) {
        'missing' => null,
        'array' => ['invalid'],
        'malformed' => 'not-a-signed-request',
        'invalid-base64' => '***.e30',
        'oversized' => str_repeat('a', 16385),
        'forged' => threadsLifecycleSignature(secret: 'different-app-secret'),
        'algorithm' => threadsLifecycleSignature(['algorithm' => 'none']),
        'user' => threadsLifecycleSignature(['user_id' => '']),
        'numeric-user' => threadsLifecycleSignature(['user_id' => 123456789]),
        'issued-at' => threadsLifecycleSignature(['issued_at' => null]),
        'future' => threadsLifecycleSignature(['issued_at' => now()->addHour()->timestamp]),
        'expired' => threadsLifecycleSignature(['expires' => now()->subMinute()->timestamp]),
        'invalid-expiry' => threadsLifecycleSignature(['expires' => '0']),
        'null-expiry' => threadsLifecycleSignature(['expires' => null]),
    };

    foreach (['deauthorize', 'data-deletion'] as $action) {
        $this->post('/accounts/threads/'.$action, ['signed_request' => $value])
            ->assertStatus($status)->assertExactJson(['error' => 'Invalid signed request.']);
    }
    $this->assertModelExists($account);
    expect($account->secret()->exists())->toBeTrue()
        ->and($account->fresh()->status)->toBe(ConnectedAccountStatus::Active);
})->with([
    ['missing', 400], ['array', 400], ['malformed', 400], ['invalid-base64', 400], ['oversized', 400],
    ['forged', 403], ['algorithm', 400], ['user', 400], ['numeric-user', 400], ['issued-at', 400],
    ['future', 400], ['expired', 403], ['invalid-expiry', 400], ['null-expiry', 400],
]);

test('callbacks fail closed when the dedicated Threads secret is unavailable', function (?string $secret) {
    $account = threadsLifecycleAccount();
    config()->set('services.threads.client_secret', $secret);
    config()->set('services.facebook.client_secret', 'threads-test-secret');

    foreach (['deauthorize', 'data-deletion'] as $action) {
        $this->post('/accounts/threads/'.$action, ['signed_request' => threadsLifecycleSignature()])
            ->assertServiceUnavailable()->assertExactJson(['error' => 'Threads callbacks are not configured.']);
    }
    expect($account->secret()->exists())->toBeTrue();
})->with([null, '', '  ']);

test('callbacks are throttled and have no web session auth or workspace middleware', function () {
    foreach (['deauthorize', 'data-deletion'] as $action) {
        $route = Route::getRoutes()->getByName('accounts.threads.'.$action);
        expect($route->gatherMiddleware())->toBe(['throttle:60,1']);
    }

    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->post('/accounts/threads/deauthorize')->assertBadRequest();
    }
    $this->post('/accounts/threads/deauthorize')->assertTooManyRequests();
});
