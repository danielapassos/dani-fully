<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\ThreadsLifecycleRequest;
use App\Services\ConnectedAccounts\Threads\ThreadsLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ThreadsLifecycleController extends Controller
{
    public function deauthorize(ThreadsLifecycleRequest $request, ThreadsLifecycle $lifecycle): Response
    {
        $lifecycle->deauthorize($request->remoteAccountId(), $request->issuedAt());

        return response()->noContent();
    }

    public function deleteData(ThreadsLifecycleRequest $request, ThreadsLifecycle $lifecycle): JsonResponse
    {
        return response()->json($lifecycle->deleteData($request->remoteAccountId(), $request->issuedAt()));
    }

    public function deletionStatus(Request $request, string $confirmationCode): Response
    {
        $outcome = $request->query('outcome');
        abort_unless(in_array($outcome, ['completed', 'newer-authorization-retained'], true), 404);

        return response()->view('accounts.threads-deletion-status', compact('confirmationCode', 'outcome'))
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'")
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
