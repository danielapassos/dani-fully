<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

use App\Enums\ErrorKind;

final readonly class PublishResult
{
    /**
     * @param  list<string>  $remoteIds
     */
    public function __construct(
        public array $remoteIds,
        public ?ErrorKind $errorKind = null,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        public ?string $responseExcerpt = null,
        public ?int $retryAfter = null,
        public string $outcome = 'published',
        public ?string $statusMessage = null,
    ) {}

    /**
     * @param  list<string>  $remoteIds
     */
    public static function success(array $remoteIds): self
    {
        return new self(remoteIds: $remoteIds);
    }

    /** @param list<string> $remoteIds Actual post IDs only, never upload-operation references. */
    public static function awaitingAction(array $remoteIds, string $message): self
    {
        return new self(remoteIds: $remoteIds, outcome: 'awaiting_action', statusMessage: $message);
    }

    /** @param list<string> $remoteIds Actual post IDs only, never upload-operation references. */
    public static function completed(array $remoteIds, string $message): self
    {
        return new self(remoteIds: $remoteIds, outcome: 'completed', statusMessage: $message);
    }

    public static function failure(ErrorKind $kind, string $message, ?int $httpStatus = null, ?string $excerpt = null, ?int $retryAfter = null): self
    {
        return new self(
            remoteIds: [],
            errorKind: $kind,
            errorMessage: $message,
            httpStatus: $httpStatus,
            responseExcerpt: $excerpt,
            retryAfter: $retryAfter,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->errorKind === null;
    }
}
