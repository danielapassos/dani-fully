<?php

declare(strict_types=1);

namespace App\Mcp\Web;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Override;

/**
 * A session-bound request, constructed only after checking the browser's actor
 * and workspace against fresh database state. Tool arguments cannot bind it.
 */
final class WebMcpRequest extends Request
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    private function __construct(
        private readonly User $actor,
        private readonly string $workspaceId,
        array $arguments,
    ) {
        parent::__construct($arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function fromSession(HttpRequest $request, string $userId, string $workspaceId, array $arguments = []): self
    {
        $sessionUser = $request->user('web');
        $user = $sessionUser instanceof User ? User::query()->find($sessionUser->id) : null;

        if ($user === null) {
            throw new HttpResponseException(WebMcpResponse::error('Sign in before using browser tools.', 401));
        }

        if (! $user->hasVerifiedEmail()) {
            throw new HttpResponseException(WebMcpResponse::error('Verify your email before using browser tools.', 403));
        }

        if ($user->id !== $userId) {
            throw new HttpResponseException(WebMcpResponse::error('The signed-in user changed. Reload the page before using browser tools.', 409));
        }

        if (! $user->isMemberOfWorkspace($workspaceId)) {
            throw new HttpResponseException(WebMcpResponse::error('You no longer have access to this workspace.', 403));
        }

        if ($user->current_workspace_id !== $workspaceId) {
            throw new HttpResponseException(WebMcpResponse::error('The selected workspace changed. Reload the page before using browser tools.', 409));
        }

        return new self($user, $workspaceId, $arguments);
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    #[Override]
    public function user(?string $guard = null): User
    {
        return $this->actor;
    }
}
