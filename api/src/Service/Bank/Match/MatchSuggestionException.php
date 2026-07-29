<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

final class MatchSuggestionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
