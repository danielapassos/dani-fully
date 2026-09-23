<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectedAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = ConnectedAccount::query()
            ->with('secret:connected_account_id,session')
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'publishing_ready' => $account->canPublish(),
                'publishing_unavailable_reason' => $account->publishingUnavailableReason(),
                'publishing_recovery_kind' => $account->publishingRecoveryKind(),
                'publishing_provider' => $account->platform === Platform::TikTok ? config('services.tiktok.publishing_provider', 'native') : 'native',
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            ]);

        return response()->json(CursorPage::make($paginator));
    }
}
