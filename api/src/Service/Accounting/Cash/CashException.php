<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Cash;

/**
 * Pokladní operaci nelze provést (neplatná pokladna, nesouhlasící DPH rekapitulace,
 * úhrada cizoměnové faktury, storno storna…). Nese strojový kód pro i18n + HTTP
 * status, aby ji navazující Action přeložila na Json::error bez ztráty kontextu
 * (stejný vzor jako Closing\ClosingException / Reports\ReportException).
 *
 * `$extra` nese strojová data hlášky (částky, id) do `error.extra`. Bez nich se
 * konkrétní číslo z české serverové zprávy do lokalizovaného textu nedostane —
 * FE totiž u všech kódů kromě `validation` upřednostní překlad, takže by hláška
 * uživateli zamlčela, kolik má vlastně zadat.
 */
final class CashException extends \RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
