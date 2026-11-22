<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Exception;

/**
 * Refus de l'API. Testez `$errorCode`, qui est stable : `getMessage()` est un
 * libellé en anglais, susceptible d'être reformulé.
 */
class ApiException extends FasterMessageException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly string $errorType = '',
        public readonly ?string $param = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
