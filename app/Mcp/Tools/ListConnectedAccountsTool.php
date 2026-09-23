<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Platform;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List every connected social account in the bound workspace with publishing readiness, exact blocker, and recovery action. A connected login is not proof that publishing is approved; operator_configuration requires app setup, not another user sign-in.')]
class ListConnectedAccountsTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $accounts = ConnectedAccount::query()
            ->with('secret:connected_account_id,session')
            ->latest()
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
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

        return Response::text(json_encode(['accounts' => $accounts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
