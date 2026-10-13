<?php

declare(strict_types=1);

namespace App\Mcp\Web;

use App\Mcp\Tools\AddPostMediaTool;
use App\Mcp\Tools\BeginVideoUploadTool;
use App\Mcp\Tools\CompleteVideoUploadTool;
use App\Mcp\Tools\CreateAccountSetTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\CreateShareLinkTool;
use App\Mcp\Tools\DeleteAccountSetTool;
use App\Mcp\Tools\DeletePostTool;
use App\Mcp\Tools\DeleteShareTool;
use App\Mcp\Tools\GetCalendarTool;
use App\Mcp\Tools\GetPostingScheduleTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListAccountAnalyticsTool;
use App\Mcp\Tools\ListAccountSetsTool;
use App\Mcp\Tools\ListConnectedAccountsTool;
use App\Mcp\Tools\ListPostAnalyticsTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListSharesTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\PublishPostTool;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\RefreshTikTokInboxTool;
use App\Mcp\Tools\RemovePostMediaTool;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\UpdateAccountSetTool;
use App\Mcp\Tools\UpdatePostTool;
use Laravel\Mcp\Server\Tool;

final class WebMcpCatalog
{
    /**
     * Browser exposure and consequence hints are reviewed explicitly. Adding an
     * OAuth MCP tool does not automatically expose it to a browser agent.
     *
     * @var array<class-string<Tool>, array{read_only: bool, consequential: bool}>
     */
    private const array TOOLS = [
        GetPostTool::class => ['read_only' => true, 'consequential' => false],
        ListWorkspacesTool::class => ['read_only' => true, 'consequential' => false],
        ListPostsTool::class => ['read_only' => true, 'consequential' => false],
        GetCalendarTool::class => ['read_only' => true, 'consequential' => false],
        ListConnectedAccountsTool::class => ['read_only' => true, 'consequential' => false],
        ListAccountSetsTool::class => ['read_only' => true, 'consequential' => false],
        ListAccountAnalyticsTool::class => ['read_only' => true, 'consequential' => false],
        ListPostAnalyticsTool::class => ['read_only' => true, 'consequential' => false],
        GetPostingScheduleTool::class => ['read_only' => true, 'consequential' => false],
        CreatePostTool::class => ['read_only' => false, 'consequential' => false],
        UpdatePostTool::class => ['read_only' => false, 'consequential' => false],
        SchedulePostTool::class => ['read_only' => false, 'consequential' => true],
        QueuePostTool::class => ['read_only' => false, 'consequential' => true],
        AddPostMediaTool::class => ['read_only' => false, 'consequential' => false],
        BeginVideoUploadTool::class => ['read_only' => false, 'consequential' => false],
        CompleteVideoUploadTool::class => ['read_only' => false, 'consequential' => false],
        RemovePostMediaTool::class => ['read_only' => false, 'consequential' => true],
        CreateAccountSetTool::class => ['read_only' => false, 'consequential' => false],
        UpdateAccountSetTool::class => ['read_only' => false, 'consequential' => false],
        DeleteAccountSetTool::class => ['read_only' => false, 'consequential' => true],
        CreateShareLinkTool::class => ['read_only' => false, 'consequential' => true],
        ListSharesTool::class => ['read_only' => true, 'consequential' => false],
        DeleteShareTool::class => ['read_only' => false, 'consequential' => true],
        PublishPostTool::class => ['read_only' => false, 'consequential' => true],
        RetryPostTargetTool::class => ['read_only' => false, 'consequential' => true],
        RefreshTikTokInboxTool::class => ['read_only' => false, 'consequential' => false],
        DeletePostTool::class => ['read_only' => false, 'consequential' => true],
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (self::TOOLS as $class => $hints) {
            $tool = app($class);
            $definition = $tool->toArray();
            $schema = $definition['inputSchema'] ?? ['type' => 'object', 'properties' => (object) []];

            if ($hints['consequential']) {
                $schema['properties'] = (array) ($schema['properties'] ?? []);
                $schema['properties']['confirm'] = [
                    'type' => 'boolean',
                    'const' => true,
                    'description' => 'Set true only after the human explicitly approves this action.',
                ];
                $schema['required'] = array_values(array_unique([...($schema['required'] ?? []), 'confirm']));
            }

            $definitions[] = [
                'name' => $definition['name'],
                'description' => $class === ListWorkspacesTool::class
                    ? 'List the selected workspace and your other workspaces. To change it, use the workspace switcher in Shoutrrr, then refresh the browser tools.'
                    : $tool->description(),
                'inputSchema' => $schema,
                'annotations' => [
                    'readOnlyHint' => $hints['read_only'],
                    'consequentialHint' => $hints['consequential'],
                    'untrustedContentHint' => true,
                ],
            ];
        }

        return $definitions;
    }

    public function find(string $name): ?Tool
    {
        foreach (array_keys(self::TOOLS) as $class) {
            $tool = app($class);

            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    public function requiresConfirmation(Tool $tool): bool
    {
        return self::TOOLS[$tool::class]['consequential'];
    }
}
