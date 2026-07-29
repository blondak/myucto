<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

/**
 * Uzávěrkovou operaci nelze provést (nevyvážený závěrkový zápis, chybějící účet
 * v osnově, nedostupný kurz ČNB, porušení gatingu kroků…). Nese strojový kód +
 * HTTP status, aby ji navazující Action přeložila na Json::error bez ztráty
 * kontextu (stejný vzor jako Reports\ReportException a Assets\AssetException).
 */
final class ClosingException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
