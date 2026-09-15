<?php

use App\Dto\Post\DraftData;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostRequest;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Posts\DraftService;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\YouTubePostOptions;
use Illuminate\Support\Facades\Validator;

function youtubeDraftChoices(string $privacy = 'public'): array
{
    return [
        'privacy_status' => $privacy, 'category_id' => '22', 'format_intent' => 'video',
        'made_for_kids' => false, 'contains_synthetic_media' => false,
        'has_paid_product_placement' => false, 'notify_subscribers' => false,
    ];
}

function youtubeDraftMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id, 'platform' => Platform::YouTube,
        'capabilities' => ['oauth_scopes' => ['https://www.googleapis.com/auth/youtube.upload']],
    ]);

    return [$user, $workspace, $account];
}

test('draft requests accept incomplete YouTube choices but reject malformed supplied values', function (mixed $youtube, bool $valid) {
    $payload = [
        'segments' => ['Caption'], 'destination' => ['kind' => 'account', 'id' => 'channel'],
        'targets' => [['connected_account_id' => 'channel', 'content_override' => ['youtube' => $youtube]]],
    ];
    foreach ([StorePostRequest::class, UpdatePostRequest::class] as $request) {
        expect(Validator::make($payload, (new $request)->rules())->passes())->toBe($valid);
    }
})->with([
    'nullable legacy' => [null, true],
    'empty choices' => [[], true],
    'partial choices' => [['privacy_status' => 'public'], true],
    'explicit false' => [['made_for_kids' => false], true],
    'string false' => [['made_for_kids' => 'false'], false],
    'numeric boolean' => [['notify_subscribers' => 0], false],
    'null child' => [['contains_synthetic_media' => null], false],
    'invalid privacy' => [['privacy_status' => 'friends'], false],
    'invalid category' => [['category_id' => '22;bad'], false],
    'unknown property' => [['access_token' => 'forbidden'], false],
    'not an object' => ['public', false],
]);

test('YouTube copy overrides validate UTF-8 character and byte limits without truncating declarations', function (array $copy, bool $valid): void {
    $choices = [...youtubeDraftChoices(), ...$copy];
    $payload = ['targets' => [['content_override' => ['youtube' => $choices]]]];
    expect(Validator::make($payload, YouTubePostOptions::draftRules())->passes())->toBe($valid);
    if (! mb_check_encoding($copy, 'UTF-8')) {
        return;
    }

    $target = PostTarget::factory()->create(['content_override' => ['youtube' => $choices]]);
    $resolved = app(YouTubePostOptions::class)->resolve($target);
    expect($resolved !== null)->toBe($valid);
    if ($valid) {
        foreach ($copy as $key => $value) {
            expect($resolved[$key])->toBe($value ?? '');
        }
    }
})->with([
    'independent copy' => [['title' => '27B is the in-flight entertainment', 'description' => "An independent description.\nSecond line."], true],
    'blank description' => [['description' => ''], true],
    'request-normalized blank description' => [['description' => null], true],
    '100 multibyte title characters' => [['title' => str_repeat('é', 100)], true],
    '101 multibyte title characters' => [['title' => str_repeat('é', 101)], false],
    '5000 description bytes' => [['description' => str_repeat('🌸', 1250)], true],
    '5004 description bytes' => [['description' => str_repeat('🌸', 1251)], false],
    'empty title' => [['title' => ''], false],
    'whitespace title' => [['title' => '  '], false],
    'null title' => [['title' => null], false],
    'title angle brackets' => [['title' => 'a < b'], false],
    'description angle brackets' => [['description' => 'a > b'], false],
    'invalid UTF-8 title' => [['title' => "\xFF"], false],
    'invalid UTF-8 description' => [['description' => "\xFF"], false],
]);

test('a blank YouTube description remains an explicit override after HTTP normalization', function (): void {
    [$user, , $channel] = youtubeDraftMember();
    $response = $this->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['Shared caption must not replace the blank description'],
        'destination' => ['kind' => 'account', 'id' => $channel->id],
        'targets' => [[
            'connected_account_id' => $channel->id,
            'content_override' => ['youtube' => [...youtubeDraftChoices(), 'title' => 'Exact title', 'description' => '']],
        ]],
    ])->assertCreated();
    $target = Post::findOrFail($response->json('post.id'))->targets()->sole();

    expect(array_key_exists('description', $target->content_override['youtube']))->toBeTrue()
        ->and(app(YouTubePostOptions::class)->resolve($target)['description'])->toBe('');
});

test('YouTube choices stay with their selected channel and inherit the shared caption', function () {
    [$user, $workspace, $channel] = youtubeDraftMember();
    $other = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::YouTube]);
    $response = $this->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['Inherited caption'],
        'destination' => ['kind' => 'accounts', 'ids' => [$channel->id, $other->id]],
        'targets' => [
            ['connected_account_id' => $channel->id, 'content_override' => ['youtube' => youtubeDraftChoices('public')]],
            ['connected_account_id' => $other->id, 'content_override' => ['youtube' => youtubeDraftChoices('private')]],
        ],
    ])->assertCreated();
    $post = Post::findOrFail($response->json('post.id'));
    $targets = $post->targets()->get()->keyBy('connected_account_id');
    expect($targets[$channel->id]->content_override)->toBe(['youtube' => youtubeDraftChoices('public')])
        ->and($targets[$other->id]->content_override)->toBe(['youtube' => youtubeDraftChoices('private')])
        ->and($targets[$channel->id]->sections)->toBe(['Inherited caption']);

    $updated = $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Edited caption'],
        'destination' => ['kind' => 'account', 'id' => $channel->id],
        'targets' => [['connected_account_id' => $channel->id, 'content_override' => ['segments' => ['Channel caption'], 'youtube' => null]]],
    ])->assertSuccessful();
    expect($updated->json('post.targets.0.content_override.youtube'))->toBe(youtubeDraftChoices('public'));
    expect($post->targets()->count())->toBe(1);
});

test('an explicit partial YouTube object replaces prior choices without silently filling missing declarations', function () {
    [$user, $workspace, $channel] = youtubeDraftMember();
    $service = app(DraftService::class);
    $post = $service->createDraft($workspace->id, $user, ['kind' => 'account', 'id' => $channel->id], ['Caption']);
    $post->targets()->firstOrFail()->forceFill(['content_override' => ['youtube' => youtubeDraftChoices()]])->save();
    $updated = $service->updateDraft($post->fresh(), DraftData::fromArray([
        'segments' => ['Caption'], 'destination' => ['kind' => 'account', 'id' => $channel->id],
        'targets' => [['connected_account_id' => $channel->id, 'content_override' => ['youtube' => ['privacy_status' => 'private']]]],
    ]));

    expect($updated->targets->first()->content_override)->toBe(['youtube' => ['privacy_status' => 'private']])
        ->and(app(YouTubePostOptions::class)->resolve($updated->targets->first()))->toBeNull();
});

test('precheck requires complete YouTube declarations while preserving legacy configured defaults', function () {
    config()->set('services.youtube.publishing_enabled', true);
    foreach (youtubeDraftChoices('private') as $key => $value) {
        config()->set('services.youtube.'.$key, $value);
    }
    [, $workspace, $channel] = youtubeDraftMember();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    PostMedia::factory()->video()->for($post)->create();
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $channel->id, 'platform' => Platform::YouTube, 'sections' => ['Caption'],
    ]);
    expect(app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media'])))->toBe([]);

    $target->forceFill(['content_override' => ['youtube' => ['privacy_status' => 'public']]])->save();
    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));
    expect($blocked[0]['issues'])->toContain('youtube_options_required');

    $target->forceFill(['content_override' => ['youtube' => youtubeDraftChoices('public')]])->save();
    expect(app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media'])))->toBe([]);
});
