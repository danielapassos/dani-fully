<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Posts\ShareService;
use App\Support\PublicPostView;
use Inertia\Inertia;
use Inertia\Response;

class PublicShareController extends Controller
{
    public function show(string $token, ShareService $shares): Response
    {
        $share = $shares->resolveActive($token);

        // Token proves authorization; bypass the workspace global scope for the
        // cross-workspace read. withoutGlobalScopes() removes the 'workspace'
        // named closure registered by HasWorkspaceScope.
        $post = $share
            ?->post()
            ->withoutGlobalScopes()
            ->with(['targets.account', 'targets.placements', 'media'])
            ->first();

        return Inertia::render('share/show', [
            'post' => $post !== null ? PublicPostView::make($post) : null,
        ]);
    }
}
