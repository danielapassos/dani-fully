<?php

use App\Dto\Post\DraftData;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Exceptions\TikTokCreatorInfoException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ConnectedAccounts\TikTok\TikTokCreatorInfo;
use App\Services\ConnectedAccounts\TikTok\TikTokPostOptions;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PublishPrecheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function tikTokControlOptions(array $overrides = []): array
{
    return array_replace([
        'privacy_level' => 'PUBLIC_TO_EVERYONE',
        'disable_comment' => true,
        'disable_duet' => true,
        'disable_stitch' => true,
        'commercial_content' => false,
        'brand_organic_toggle' => false,
        'brand_content_toggle' => false,
        'is_aigc' => false,
        'music_usage_confirmed' => true,
        'branded_content_policy_confirmed' => false,
    ], $overrides);
}

function tikTokControlCreator(array $overrides = []): array
{
    return array_replace([
        'creator_username' => 'creator',
        'creator_nickname' => 'Current creator',
        'creator_avatar_url' => 'https://example.test/avatar.jpg',
        'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
        'comment_disabled' => false,
        'duet_disabled' => true,
        'stitch_disabled' => false,
        'max_video_post_duration_sec' => 60,
    ], $overrides);
}

function tikTokControlMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $user->id,
        'platform' => Platform::TikTok,
        'capabilities' => ['oauth_scopes' => ['video.publish']],
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'test-private-token']);

    return [$user, $workspace, $account];
}

beforeEach(function () {
    config()->set('services.tiktok.direct_post_enabled', true);
    Http::preventStrayRequests();
});

test('members can load current TikTok controls without seeing tokens or unknown provider fields', function () {
    [$user, , $account] = tikTokControlMember();
    Http::fake(['*/creator_info/query/' => Http::response(['data' => [...tikTokControlCreator(), 'access_token' => 'must-not-leak'], 'error' => ['code' => 'ok']])]);

    test()->actingAs($user)->getJson(route('accounts.tiktok.creator-info', $account))
        ->assertSuccessful()
        ->assertJsonPath('creator.creator_nickname', 'Current creator')
        ->assertJsonMissingPath('creator.access_token')
        ->assertHeader('Cache-Control', 'no-store, private');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->body() === '{}' && $request->hasHeader('Authorization', 'Bearer test-private-token'));
});

test('creator info account binding refuses foreign workspace ids without calling TikTok', function () {
    [$user] = tikTokControlMember();
    $foreign = ConnectedAccount::factory()->create(['platform' => Platform::TikTok]);
    Http::fake();
    test()->actingAs($user)->getJson(route('accounts.tiktok.creator-info', $foreign))->assertNotFound();
    Http::assertNothingSent();
});

test('creator info is unavailable to guests and while Direct Post is disabled', function () {
    [$user, , $account] = tikTokControlMember();
    Http::fake();
    test()->getJson(route('accounts.tiktok.creator-info', $account))->assertUnauthorized();
    config()->set('services.tiktok.direct_post_enabled', false);
    test()->actingAs($user)->getJson(route('accounts.tiktok.creator-info', $account))->assertNotFound();
    Http::assertNothingSent();
});

test('creator restrictions and malformed creator responses fail closed with safe messages', function (array $response) {
    [, , $account] = tikTokControlMember();
    Http::fake(['*/creator_info/query/' => Http::response($response)]);
    expect(fn () => app(TikTokCreatorInfo::class)->query($account, 'test-token'))->toThrow(TikTokCreatorInfoException::class);
})->with([
    'post cap' => [['error' => ['code' => 'spam_risk_too_many_posts', 'message' => 'secret=test-token']]],
    'missing interaction flag' => [['data' => tikTokControlCreator(['comment_disabled' => null]), 'error' => ['code' => 'ok']]],
    'missing privacy' => [['data' => tikTokControlCreator(['privacy_level_options' => []]), 'error' => ['code' => 'ok']]],
]);

test('creator failures return actionable validation errors without provider secrets', function () {
    [$user, , $account] = tikTokControlMember();
    Http::fake(['*/creator_info/query/' => Http::response(['error' => ['code' => 'spam_risk_too_many_posts', 'message' => 'secret=test-token']])]);
    test()->actingAs($user)->getJson(route('accounts.tiktok.creator-info', $account))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('creator')
        ->assertDontSee('secret=test-token');
});

test('TikTok options enforce consent privacy interaction duration and commercial rules', function (array $changes, ?int $duration, string $issue) {
    expect(app(TikTokPostOptions::class)->issues(tikTokControlOptions($changes), tikTokControlCreator(), $duration))->toContain($issue);
})->with([
    'no privacy' => [['privacy_level' => null], 12, 'tiktok_privacy_required'],
    'unsupported current privacy' => [['privacy_level' => 'FOLLOWER_OF_CREATOR'], 12, 'tiktok_privacy_required'],
    'music consent' => [['music_usage_confirmed' => false], 12, 'tiktok_music_consent_required'],
    'disabled duet' => [['disable_duet' => false], 12, 'tiktok_interaction_unavailable'],
    'unknown duration' => [[], null, 'tiktok_duration_unknown'],
    'long duration' => [[], 61, 'tiktok_duration_exceeded'],
    'no commercial choice' => [['commercial_content' => true], 12, 'tiktok_commercial_disclosure_required'],
    'private branded' => [['commercial_content' => true, 'brand_content_toggle' => true, 'privacy_level' => 'SELF_ONLY'], 12, 'tiktok_branded_content_private'],
    'branded consent' => [['commercial_content' => true, 'brand_content_toggle' => true], 12, 'tiktok_branded_consent_required'],
    'noninteger cover' => [['video_cover_timestamp_ms' => '100'], 12, 'tiktok_cover_invalid'],
    'cover past end' => [['video_cover_timestamp_ms' => 12000], 12, 'tiktok_cover_invalid'],
]);

test('a fully specified TikTok post passes current creator restrictions', function () {
    expect(app(TikTokPostOptions::class)->issues(tikTokControlOptions(['is_aigc' => true, 'video_cover_timestamp_ms' => 100]), tikTokControlCreator(), 12))->toBe([]);
});

test('TikTok settings persist without replacing inherited caption with an empty override', function () {
    [$user, , $account] = tikTokControlMember();
    $response = test()->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['Caption inherited from the base'],
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['tiktok' => tikTokControlOptions()]]],
    ])->assertCreated();
    $post = Post::findOrFail($response->json('post.id'));
    $target = $post->targets()->firstOrFail();
    expect($target->content_override)->toBe(['tiktok' => tikTokControlOptions()])
        ->and($target->sections)->toBe(['Caption inherited from the base']);

    $data = DraftData::fromArray(['segments' => ['changed'], 'targets' => [['connected_account_id' => $account->id, 'content_override' => ['tiktok' => tikTokControlOptions(), 'unknown' => 'discard']]]]);
    expect($data->overrideFor($account->id))->toBe(['tiktok' => tikTokControlOptions()]);
});

test('server precheck blocks unconfigured direct posts while retaining legacy inbox sessions', function () {
    [, $workspace, $account] = tikTokControlMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    $video = PostMedia::factory()->video()->for($post)->create(['duration_seconds' => 12]);
    $target = PostTarget::factory()->for($post)->create(['connected_account_id' => $account->id, 'platform' => Platform::TikTok, 'sections' => ['caption']]);
    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));
    expect($blocked[0]['issues'])->toContain('tiktok_privacy_required', 'tiktok_music_consent_required');

    $target->forceFill(['media_upload_state' => [$video->id => ['remote_ref' => 'existing-inbox-transfer']]])->save();
    expect(app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media'])))->toBe([]);
});

test('duplicated drafts require fresh TikTok publishing choices and consent', function () {
    [, $workspace, $account] = tikTokControlMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'content_override' => ['segments' => ['Keep this caption'], 'tiktok' => tikTokControlOptions()],
    ]);

    $copy = app(PostDuplicator::class)->duplicate($post);
    expect($copy->targets->sole()->content_override)->toBe(['segments' => ['Keep this caption']]);
});

test('draft requests reject unknown TikTok options and invalid cover values', function (array $changes, string $field) {
    [$user, , $account] = tikTokControlMember();
    test()->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['Caption'],
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [['connected_account_id' => $account->id, 'content_override' => ['tiktok' => tikTokControlOptions($changes)]]],
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown option' => [['unexpected' => true], 'targets.0.content_override.tiktok'],
    'nonnumeric cover' => [['video_cover_timestamp_ms' => 'invalid'], 'targets.0.content_override.tiktok.video_cover_timestamp_ms'],
    'negative cover' => [['video_cover_timestamp_ms' => -1], 'targets.0.content_override.tiktok.video_cover_timestamp_ms'],
]);
