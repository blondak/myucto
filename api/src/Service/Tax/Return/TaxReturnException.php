<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Doménová výjimka přiznání k dani z příjmů (Epic DP, issue #18) — nese strojový
 * kód a HTTP status pro mapování na Json::error v Action vrstvě.
 */
final class TaxReturnException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
