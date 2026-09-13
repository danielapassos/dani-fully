<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\Threads;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ThreadsLifecycle
{
    public function __construct(private readonly PreserveThreadsDrafts $drafts) {}

    public function deauthorize(string $remoteAccountId, int $issuedAt): void
    {
        DB::transaction(function () use ($remoteAccountId, $issuedAt): void {
            foreach ($this->accounts($remoteAccountId)->lockForUpdate()->get() as $account) {
                if ($this->authorizedAfter($account, $issuedAt)) {
                    continue;
                }

                $account->secret()->delete();
                $account->forceFill([
                    'status' => ConnectedAccountStatus::NeedsAttention,
                    'token_expires_at' => null,
                    'capabilities' => null,
                    'refresh_failed_at' => now(),
                    'refresh_failure_reason' => 'Threads access was revoked. Reconnect this account to continue.',
                ])->save();
            }
        });
    }

    /** @return array{url: string, confirmation_code: string} */
    public function deleteData(string $remoteAccountId, int $issuedAt): array
    {
        $newerAuthorizationRetained = DB::transaction(function () use ($remoteAccountId, $issuedAt): bool {
            $newerAuthorizationRetained = false;
            foreach ($this->accounts($remoteAccountId)->lockForUpdate()->get() as $account) {
                if ($this->authorizedAfter($account, $issuedAt)) {
                    $newerAuthorizationRetained = true;

                    continue;
                }

                $this->drafts->preserve($account);
                $account->secret()->delete();
                $account->delete();
            }

            return $newerAuthorizationRetained;
        });

        $confirmationCode = Str::random(40);

        return [
            'url' => URL::temporarySignedRoute(
                'accounts.threads.deletion-status',
                now()->addDays(30),
                [
                    'confirmationCode' => $confirmationCode,
                    'outcome' => $newerAuthorizationRetained ? 'newer-authorization-retained' : 'completed',
                ],
            ),
            'confirmation_code' => $confirmationCode,
        ];
    }

    private function authorizedAfter(ConnectedAccount $account, int $issuedAt): bool
    {
        return ($account->authorized_at ?? $account->created_at)->getTimestamp() > $issuedAt;
    }

    /** @return Builder<ConnectedAccount> */
    private function accounts(string $remoteAccountId): Builder
    {
        return ConnectedAccount::withoutGlobalScope('workspace')
            ->where('platform', Platform::Threads)
            ->where('remote_account_id', $remoteAccountId);
    }
}
