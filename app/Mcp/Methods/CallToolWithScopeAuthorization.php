<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Mcp\Tools\GetCalendarTool;
use App\Mcp\Tools\GetPostingScheduleTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListAccountSetsTool;
use App\Mcp\Tools\ListConnectedAccountsTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListSharesTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Models\User;
use Generator;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Laravel\Passport\AccessToken;
use Override;

class CallToolWithScopeAuthorization extends CallTool
{
    /** New tools require write access until explicitly reviewed as read-only. */
    private const array READ_ONLY_TOOLS = [
        GetPostTool::class,
        ListWorkspacesTool::class,
        ListPostsTool::class,
        GetCalendarTool::class,
        ListConnectedAccountsTool::class,
        ListAccountSetsTool::class,
        GetPostingScheduleTool::class,
        ListSharesTool::class,
    ];

    #[Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $tool = $context->tools()->first(fn (Tool $tool): bool => $tool->name() === $request->get('name'));

        if ($tool !== null && ! in_array($tool::class, self::READ_ONLY_TOOLS, true)) {
            $user = Auth::user();

            if (! $user instanceof User || ! $user->currentAccessToken() instanceof AccessToken || ! $user->tokenCan('write')) {
                return $this->toJsonRpcResponse(
                    $request,
                    Response::error('This MCP connection does not have write access. Reconnect and grant write access before changing workspace data.'),
                    $this->serializable($tool),
                );
            }
        }

        return parent::handle($request, $context);
    }
}
