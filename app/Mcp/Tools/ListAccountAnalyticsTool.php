<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Metrics\StoredAnalytics;
use App\Support\InstanceSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read saved follower and post-count metrics for connected accounts in the bound workspace. Includes capture time, latest attempt status, polling and freshness. Missing is null, not zero. Does not poll providers or publish. Follow next_cursor for remaining accounts.')]
#[Name('list_account_analytics')]
#[IsReadOnly]
class ListAccountAnalyticsTool extends WorkspaceTool
{
    public function handle(Request $request, StoredAnalytics $analytics, InstanceSettings $settings): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if (($denial = $this->authorize($request, 'viewAny', Post::class)) !== null) {
            return $denial;
        }

        if (! $settings->metricsEnabled()) {
            return Response::error('Analytics collection is disabled for this instance. An administrator can enable Metrics in instance settings.');
        }

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:1000'],
            'connected_account_id' => ['nullable', 'uuid'],
        ]);

        return Response::text(json_encode($analytics->accounts(
            (int) ($validated['per_page'] ?? 25),
            $validated['cursor'] ?? null,
            $validated['connected_account_id'] ?? null,
        ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connected_account_id' => $schema->string()->description('Optional exact connected account UUID. Use list_connected_accounts to find it.'),
            'per_page' => $schema->integer()->description('Accounts per page (1-100, default 25).'),
            'cursor' => $schema->string()->description('Opaque next_cursor or prev_cursor returned by this tool.'),
        ];
    }
}
