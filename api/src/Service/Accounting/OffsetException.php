<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Zápočet nelze sestavit/potvrdit (nevyvážené strany, doklad není otevřený, cizí
 * měna, částka nad zbytek dokladu…). Nese strojový kód + HTTP status pro Json::error
 * (stejný vzor jako {@see PostingException}).
 */
final class OffsetException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
