<?php

declare(strict_types=1);

namespace App\Dto\ConnectedAccount;

use App\Enums\Platform;

final readonly class OAuthConnectionAttempt
{
    public function __construct(
        public string $state,
        public Platform $platform,
        public string $userId,
        public string $workspaceId,
        public string $callbackUri,
        public string $codeHash,
        public ?string $codeVerifier = null,
        public ?string $completedAccountId = null,
    ) {}
}
