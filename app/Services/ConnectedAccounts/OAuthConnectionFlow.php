<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Dto\ConnectedAccount\OAuthConnectionAttempt;
use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class OAuthConnectionFlow
{
    private const int LIFETIME_SECONDS = 1800;

    private const int MAX_ATTEMPTS = 12;

    public function driver(Platform $platform): AbstractProvider
    {
        $original = Socialite::getFacadeRoot();
        if (! $original instanceof SocialiteManager) {
            throw new LogicException('The social connection provider manager is unavailable.');
        }
        $manager = clone $original;
        $driver = $manager->forgetDrivers()->driver((string) $platform->socialiteDriver());
        abort_unless($driver instanceof AbstractProvider, 404);

        return $driver->redirectUrl(route('accounts.callback', ['platform' => $platform->value]));
    }

    public function redirect(Request $request, Platform $platform, AbstractProvider $driver): RedirectResponse
    {
        [$isolatedRequest, $session] = $this->isolatedRequest($request);
        $response = $driver->setRequest($isolatedRequest)->redirect();
        $state = $session->get('state');
        $verifier = $session->get('code_verifier');
        if (! is_string($state) || strlen($state) !== 40) {
            throw new InvalidStateException;
        }

        $this->mutate($request, function (array &$records) use ($request, $platform, $state, $verifier): void {
            if (count($records) >= self::MAX_ATTEMPTS) {
                foreach ($records as $key => $record) {
                    if (($record['status'] ?? null) !== 'processing') {
                        unset($records[$key]);
                        break;
                    }
                }
            }
            if (count($records) >= self::MAX_ATTEMPTS) {
                throw new InvalidStateException;
            }

            $records[hash('sha256', $state)] = [
                'state' => $state,
                'platform' => $platform->value,
                'user_id' => (string) $request->user()->getAuthIdentifier(),
                'workspace_id' => (string) $request->user()->current_workspace_id,
                'callback_uri' => route('accounts.callback', ['platform' => $platform->value]),
                'verifier' => is_string($verifier) ? Crypt::encryptString($verifier) : null,
                'expires_at' => now()->getTimestamp() + self::LIFETIME_SECONDS,
                'status' => 'pending',
            ];
        });

        return $response;
    }

    public function claim(Request $request, Platform $platform): OAuthConnectionAttempt
    {
        $state = $request->query('state');
        $code = $request->query('code', '');
        if (! is_string($state) || ! preg_match('/\A[A-Za-z0-9]{40}\z/', $state)
            || ! is_string($code) || strlen($code) > 8192 || ($code === '' && ! $request->filled('error'))) {
            throw new InvalidStateException;
        }

        return $this->mutate($request, function (array &$records) use ($request, $platform, $state, $code): OAuthConnectionAttempt {
            $key = hash('sha256', $state);
            $record = $records[$key] ?? [];
            $userId = (string) $request->user()->getAuthIdentifier();
            $workspaceId = (string) $request->user()->current_workspace_id;
            $callbackUri = route('accounts.callback', ['platform' => $platform->value]);
            $codeHash = hash('sha256', $code);

            if (! is_string($record['state'] ?? null) || ! hash_equals($record['state'], $state)
                || ($record['platform'] ?? null) !== $platform->value
                || ($record['user_id'] ?? null) !== $userId
                || ($record['workspace_id'] ?? null) !== $workspaceId
                || ($record['callback_uri'] ?? null) !== $callbackUri) {
                throw new InvalidStateException;
            }

            $completedAccountId = null;
            if (($record['status'] ?? null) === 'completed' && ($record['code_hash'] ?? null) === $codeHash) {
                $accountId = $record['account_id'] ?? null;
                if (is_string($accountId) && ConnectedAccount::withoutGlobalScopes()
                    ->whereKey($accountId)->where('workspace_id', $workspaceId)->where('platform', $platform)
                    ->where('status', ConnectedAccountStatus::Active)->whereNull('disabled_at')->has('secret')->exists()) {
                    $completedAccountId = $accountId;
                }
            }

            if ($completedAccountId === null && ($record['status'] ?? null) !== 'pending') {
                throw new InvalidStateException;
            }

            $verifier = is_string($record['verifier'] ?? null) ? Crypt::decryptString($record['verifier']) : null;
            if ($completedAccountId === null) {
                $records[$key]['status'] = 'processing';
                $records[$key]['code_hash'] = $codeHash;
                unset($records[$key]['verifier']);
            }

            return new OAuthConnectionAttempt($state, $platform, $userId, $workspaceId, $callbackUri, $codeHash, $verifier, $completedAccountId);
        });
    }

    public function user(Request $request, OAuthConnectionAttempt $attempt, AbstractProvider $driver): User
    {
        [$isolatedRequest, $session] = $this->isolatedRequest($request);
        $session->put('state', $attempt->state);
        if ($attempt->codeVerifier !== null) {
            $session->put('code_verifier', $attempt->codeVerifier);
        }

        return $driver->setRequest($isolatedRequest)->user();
    }

    public function complete(Request $request, OAuthConnectionAttempt $attempt, ConnectedAccount $account): void
    {
        if ($account->workspace_id !== $attempt->workspaceId || $account->platform !== $attempt->platform
            || $account->connected_by_user_id !== $attempt->userId) {
            throw new LogicException('The stored connection does not match its authorization attempt.');
        }

        $this->finish($request, $attempt, $account->id);
    }

    public function fail(Request $request, OAuthConnectionAttempt $attempt): void
    {
        $this->finish($request, $attempt, null);
    }

    private function finish(Request $request, OAuthConnectionAttempt $attempt, ?string $accountId): void
    {
        $this->mutate($request, function (array &$records) use ($attempt, $accountId): void {
            $key = hash('sha256', $attempt->state);
            if (($records[$key]['status'] ?? null) !== 'processing' || ($records[$key]['code_hash'] ?? null) !== $attempt->codeHash) {
                return;
            }

            $records[$key]['status'] = $accountId === null ? 'failed' : 'completed';
            $records[$key]['account_id'] = $accountId;
            unset($records[$key]['verifier']);
        });
    }

    /** @return array{Request, Store} */
    private function isolatedRequest(Request $request): array
    {
        $isolated = clone $request;
        $session = new Store('connected-account-oauth', new ArraySessionHandler(30));
        $isolated->setLaravelSession($session);

        return [$isolated, $session];
    }

    /**
     * @template T
     *
     * @param  callable(array<string, array<string, mixed>>&): T  $operation
     * @return T
     */
    private function mutate(Request $request, callable $operation): mixed
    {
        $key = 'connected-account-oauth:'.hash('sha256', $request->session()->getId());

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $operation): mixed {
            $cached = Cache::get($key, []);
            $records = [];
            if (is_array($cached)) {
                foreach ($cached as $state => $record) {
                    if (is_string($state) && is_array($record) && is_int($record['expires_at'] ?? null) && $record['expires_at'] > now()->getTimestamp()) {
                        $records[$state] = $record;
                    }
                }
            }

            try {
                return $operation($records);
            } finally {
                Cache::put($key, $records, self::LIFETIME_SECONDS);
            }
        });
    }
}
