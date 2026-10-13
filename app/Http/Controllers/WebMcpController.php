<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WebMcpToolRequest;
use App\Mcp\Web\WebMcpCatalog;
use App\Mcp\Web\WebMcpRequest;
use App\Mcp\Web\WebMcpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use LogicException;
use Throwable;

class WebMcpController extends Controller
{
    public function index(WebMcpToolRequest $request, WebMcpCatalog $catalog): JsonResponse
    {
        $bound = $this->bind($request);

        return WebMcpResponse::json([
            'user_id' => $bound->user()->id,
            'workspace_id' => $bound->workspaceId(),
            'tools' => $catalog->definitions(),
        ]);
    }

    public function execute(WebMcpToolRequest $request, string $tool, WebMcpCatalog $catalog): JsonResponse
    {
        $bound = $this->bind($request);
        $handler = $catalog->find($tool);

        if ($handler === null) {
            return WebMcpResponse::error('This browser tool is not available.', 404);
        }

        if ($catalog->requiresConfirmation($handler) && $bound->get('confirm') !== true) {
            return WebMcpResponse::error('Confirm this action with the human, then call again with confirm set to true.', 422);
        }

        try {
            $callback = [$handler, 'handle'];
            if (! is_callable($callback)) {
                throw new LogicException('The registered browser tool has no callable handler.');
            }

            /** @var Response $result */
            $result = app()->call($callback, ['request' => $bound]);

            return WebMcpResponse::tool($result);
        } catch (ValidationException $exception) {
            return WebMcpResponse::error(implode(' ', $exception->validator->errors()->all()), 422);
        } catch (Throwable $exception) {
            report($exception);

            return WebMcpResponse::error('The tool could not finish. Check the current post or upload status before retrying an action.', 500);
        }
    }

    private function bind(WebMcpToolRequest $request): WebMcpRequest
    {
        $validated = $request->validated();

        return WebMcpRequest::fromSession(
            $request,
            $validated['user_id'],
            $validated['workspace_id'],
            $validated['arguments'] ?? [],
        );
    }
}
