<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Cash;

/**
 * Pokladní operaci nelze provést (neplatná pokladna, nesouhlasící DPH rekapitulace,
 * úhrada cizoměnové faktury, storno storna…). Nese strojový kód pro i18n + HTTP
 * status, aby ji navazující Action přeložila na Json::error bez ztráty kontextu
 * (stejný vzor jako Closing\ClosingException / Reports\ReportException).
 */
final class CashException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
