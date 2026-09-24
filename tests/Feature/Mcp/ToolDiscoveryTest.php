<?php

use App\Models\User;
use Laravel\Passport\Passport;

test('default tool discovery includes upload completion and TikTok reconciliation in its first response', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use', 'read']);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ])->assertOk()->assertJsonMissingPath('result.nextCursor');

    expect(collect($response->json('result.tools'))->pluck('name')->all())->toContain(
        'begin_video_upload',
        'complete_video_upload',
        'list_account_analytics',
        'list_post_analytics',
        'refresh_tiktok_inbox',
    );
});

test('clients can still explicitly paginate the tool inventory', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use', 'read']);

    $first = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['per_page' => 10],
    ])->assertOk()->assertJsonCount(10, 'result.tools');
    $cursor = $first->json('result.nextCursor');
    expect($cursor)->toBeString()->not->toBeEmpty();

    $second = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => ['per_page' => 10, 'cursor' => $cursor],
    ])->assertOk()->assertJsonCount(10, 'result.tools');

    $firstNames = collect($first->json('result.tools'))->pluck('name')->all();
    $secondNames = collect($second->json('result.tools'))->pluck('name')->all();
    expect(array_intersect($firstNames, $secondNames))->toBe([])
        ->and($secondNames)->toContain('complete_video_upload');
});
