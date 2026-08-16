<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Zaúčtování nelze provést (uzavřené období, chybějící účet v osnově, nepostovatelný
 * doklad…). Nese strojový kód + HTTP status, aby ho navazující Action přeložil na
 * Json::error bez ztráty kontextu (stejný vzor jako Document\DocumentException).
 *
 * `$context` je volitelná strojová příloha chyby (např. `['row' => 3]` = který řádek
 * rozvahy validaci shodil). Action ji předává do `Json::error(..., $extra)`, aby
 * rozhraní umělo ukázat na konkrétní místo, ne jen zopakovat větu.
 */
final class PostingException extends \RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
