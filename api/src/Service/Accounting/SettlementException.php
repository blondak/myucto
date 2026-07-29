<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Zápočet faktury proti účtu nelze provést (cizí měna, částka nad zbytek dokladu,
 * doklad není v uhraditelném stavu, protiúčet neexistuje…). Nese strojový kód +
 * HTTP status pro Json::error (stejný vzor jako {@see OffsetException}).
 */
final class SettlementException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
