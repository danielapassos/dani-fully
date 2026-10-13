<?php

declare(strict_types=1);

namespace App\Mcp\Web;

use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Response;

final class WebMcpResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public static function tool(Response $response, int $status = 200): JsonResponse
    {
        return self::json([
            'content' => [['type' => 'text', 'text' => (string) $response->content()]],
            'isError' => $response->isError(),
        ], $status);
    }

    public static function error(string $message, int $status): JsonResponse
    {
        return self::tool(Response::error($message), $status);
    }
}
