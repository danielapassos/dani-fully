<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Registrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Describe Shoutrrr's optional permissions without granting them. Passport's
 * authorization request and workspace consent still determine every token's scopes.
 */
class McpOAuthScopes
{
    private const array SUPPORTED = [Registrar::OAUTH_SCOPE, 'read', 'write'];

    public function handle(Request $request, Closure $next): Response
    {
        $registration = $request->isMethod('POST') && $request->is('oauth/register');
        $scope = Registrar::OAUTH_SCOPE;

        if ($registration) {
            $requested = $request->input('scope', Registrar::OAUTH_SCOPE);
            $scopes = is_string($requested) ? explode(' ', $requested) : [];

            if ($scopes === [] || strlen((string) $requested) > 255 || array_diff($scopes, self::SUPPORTED) !== []) {
                return response()->json([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'scope must be a space-separated list of supported scopes: mcp:use, read, write.',
                ], 400);
            }

            $scope = implode(' ', array_unique($scopes));
        }

        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }

        /** @var array<string, mixed> $data */
        $data = $response->getData(true);

        if ($registration && $response->getStatusCode() === 201) {
            // Registration acknowledges requested client metadata, not user consent.
            // Never add write implicitly or mutate any existing client/token grant.
            $response->setData([...$data, 'scope' => $scope]);
        } elseif ($request->isMethod('GET') && $request->routeIs(
            'mcp.oauth.authorization-server',
            'mcp.oauth.authorization-server.nested',
            'mcp.oauth.protected-resource',
            'mcp.oauth.protected-resource.nested',
        )) {
            $response->setData([...$data, 'scopes_supported' => self::SUPPORTED]);
        }

        return $response;
    }
}
