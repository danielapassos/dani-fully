<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        Context::forget('workspace_id');

        if ($user !== null) {
            $workspaceId = $user->current_workspace_id;

            if (! $user->isMemberOfWorkspace($workspaceId)) {
                $workspaceId = null;
                $user->current_workspace_id = null;
                $user->setRelation('currentWorkspace', null);
            }

            Context::add('workspace_id', $workspaceId);
        }

        return $next($request);
    }
}
