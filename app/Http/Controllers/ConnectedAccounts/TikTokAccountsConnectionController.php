<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Exceptions\TokenRefreshException;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\TikTok\TikTokAccountsConnectionFlow;
use App\Services\Publishing\TikTokAccountsTokenManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TikTokAccountsConnectionController extends Controller
{
    public function __construct(private TikTokAccountsConnectionFlow $flow, private TikTokAccountsTokenManager $tokens) {}

    public function redirect(Request $request, ConnectedAccount $account): RedirectResponse
    {
        try {
            return redirect()->away($this->flow->redirect($request, $account));
        } catch (TokenRefreshException $exception) {
            return redirect()->route('accounts.index')->with('error', $exception->getMessage());
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $this->flow->complete($request);
            Inertia::clearHistory();

            return redirect()->route('accounts.index')->with('success', 'TikTok Accounts publishing authorization connected.');
        } catch (TokenRefreshException $exception) {
            return redirect()->route('accounts.index')->with('error', $exception->getMessage());
        }
    }

    public function destroy(Request $request, ConnectedAccount $account): RedirectResponse
    {
        abort_unless($request->user()->can('update', $account), 403);
        try {
            $this->tokens->revoke($account);
            Inertia::clearHistory();

            return redirect()->route('accounts.index')->with('success', 'TikTok Accounts publishing authorization disconnected.');
        } catch (TokenRefreshException $exception) {
            return redirect()->route('accounts.index')->with('error', $exception->getMessage());
        }
    }
}
