<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

/**
 * Eshopovou operaci nelze provést (cyklus v kategoriích, cizí entita, neplatná
 * hodnota atributu, chybí nákupní cena…). Nese strojový kód pro i18n + HTTP
 * status a volitelný strukturovaný detail. Stejný vzor jako Stock\StockException —
 * navazující Action ji přeloží na Json::error bez ztráty kontextu.
 */
final class EshopException extends \RuntimeException
{
    /**
     * @param array<int,array<string,mixed>>|array<string,mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
