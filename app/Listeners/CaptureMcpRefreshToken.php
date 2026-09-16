<?php

declare(strict_types=1);

namespace App\Listeners;

use Laravel\Passport\Events\AccessTokenRevoked;

class CaptureMcpRefreshToken
{
    public const string REQUEST_ATTRIBUTE = 'mcp_refresh_source_token_id';

    /**
     * Passport revokes the old access token only after validating the refresh
     * token and its scopes. Keep that trusted lineage on this request, not on
     * a listener instance that Octane could reuse for another connection.
     */
    public function handle(AccessTokenRevoked $event): void
    {
        $request = request();

        if (! $request->routeIs('passport.token') || $request->input('grant_type') !== 'refresh_token') {
            return;
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $event->tokenId);
    }
}
