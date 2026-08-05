<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

/**
 * Výsledek {@see VatRateResolver} — nalezená (nebo nenalezená) sazba DPH.
 *
 * `$ratePercent` je vždy procento Z DATABÁZE, ne vstupní hodnota z dokladu. Rozdíl je
 * v rámci tolerance zanedbatelný, ale autoritativní je to, co se snapshotuje na řádek
 * a z čeho pak počítají výkazy.
 */
final readonly class VatRateMatch
{
    public function __construct(
        public ?int $id,
        public ?string $code,
        public ?float $ratePercent,
        public string $country,
        public VatRateResolution $status,
        public string $message,
    ) {}

    /** `false` = volající MUSÍ řádek odmítnout; tichý fallback na jinou sazbu je zakázaný. */
    public function found(): bool
    {
        return $this->id !== null;
    }

    /** Sazba je použitelná, ale uživatel to má po importu zkontrolovat. */
    public function isWarning(): bool
    {
        return $this->status === VatRateResolution::MatchedOutsideValidity;
    }
}
