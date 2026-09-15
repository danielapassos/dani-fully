<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorKind;
use RuntimeException;

class TikTokCreatorInfoException extends RuntimeException
{
    public function __construct(string $message, public readonly ErrorKind $errorKind = ErrorKind::Validation, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
