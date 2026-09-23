<?php

declare(strict_types=1);

namespace App\Services\Publishing\TikTokAccounts;

use App\Enums\ErrorKind;
use App\Exceptions\TikTokCreatorInfoException;

class TikTokAccountsRequestException extends TikTokCreatorInfoException
{
    public function __construct(
        string $message,
        ErrorKind $kind,
        ?int $httpStatus = null,
        public readonly bool $definiteRejection = false,
        public readonly ?int $providerCode = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $kind, $httpStatus);
    }
}
