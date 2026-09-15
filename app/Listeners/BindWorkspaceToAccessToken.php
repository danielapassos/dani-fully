<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\McpGrantWorkspace;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

/**
 * When Passport issues an access token (token exchange), find the pending workspace
 * binding the user recorded at consent (keyed by user + client) and stamp it with
 * the new token id, finalizing the token<->workspace binding.
 */
class BindWorkspaceToAccessToken
{
    public function handle(AccessTokenCreated $event): void
    {
        if (request()->routeIs('passport.token') && request()->input('grant_type') === 'refresh_token') {
            $this->bindRefreshedToken($event);

            return;
        }

        // SQLite does not support UPDATE … LIMIT, so we locate the pending row by
        // primary key first and then update by id.
        $pending = McpGrantWorkspace::query()
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->whereNull('access_token_id')
            ->latest()
            ->value('id');

        if ($pending === null) {
            return;
        }

        McpGrantWorkspace::where('id', $pending)
            ->update(['access_token_id' => $event->tokenId]);
    }

    private function bindRefreshedToken(AccessTokenCreated $event): void
    {
        $request = request();
        $previousTokenId = $request->attributes->get(CaptureMcpRefreshToken::REQUEST_ATTRIBUTE);
        $request->attributes->remove(CaptureMcpRefreshToken::REQUEST_ATTRIBUTE);

        if (! is_string($previousTokenId)) {
            return;
        }

        if (! Passport::token()->newQuery()
            ->whereKey($previousTokenId)
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->where('revoked', true)
            ->exists()) {
            return;
        }

        $previousBinding = McpGrantWorkspace::query()
            ->where('access_token_id', $previousTokenId)
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->first();

        if ($previousBinding === null) {
            return;
        }

        McpGrantWorkspace::create([
            'user_id' => $event->userId,
            'client_id' => $event->clientId,
            'workspace_id' => $previousBinding->workspace_id,
            'access_token_id' => $event->tokenId,
        ]);
    }
}
