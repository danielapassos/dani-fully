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

#[Description('Read saved analytics for published workspace posts, including synced posts. Returns exact account/post IDs, saved caption, lifetime engagement counters, measurement time and freshness. The days filter selects publication dates, not period gains. Missing or unsupported is null; paid context is unknown. TikTok inbox delivery alone is not publication. Does not poll providers. Follow next_cursor for remaining posts.')]
#[Name('list_post_analytics')]
#[IsReadOnly]
class ListPostAnalyticsTool extends WorkspaceTool
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
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'connected_account_id' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:1000'],
        ]);

        return Response::text(json_encode($analytics->posts(
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['days'] ?? 90),
            $validated['connected_account_id'] ?? null,
            $validated['cursor'] ?? null,
        ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connected_account_id' => $schema->string()->description('Optional exact connected account UUID. This filters account identity, not caption text.'),
            'days' => $schema->integer()->description('Publication window in days (1-365, default 90). Counters are lifetime totals as of captured_at.'),
            'per_page' => $schema->integer()->description('Post targets per page (1-100, default 25).'),
            'cursor' => $schema->string()->description('Opaque next_cursor or prev_cursor returned by this tool.'),
        ];
    }
}
