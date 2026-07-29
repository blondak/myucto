<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Service\Accounting\FiscalCalendar;

/**
 * Vstupní DTO výpočetního jádra odpisů (Epic F3, spec §2.1) — čistá data karty
 * majetku bez DB závislostí. Strategie z něj počítají kompletní průběh daňových
 * i účetních odpisů; minulost kotví `confirmedEntries`, budoucnost se dopočítává.
 *
 * `calendar` mapuje datum ↔ label období — pro kalendářní poplatníky (default)
 * je to `year(date)`, pro hospodářský rok posun dle §21a (Epic DP v2).
 */
final class DepreciationContext
{
    public function __construct(
        public readonly float $inputPrice,               // VC §29 (CZK)
        public readonly ?int $taxGroup,                  // 1..6 | null
        public readonly string $firstYearIncrease,       // none|p10|p15|p20
        public readonly bool $isFirstOwner,
        public readonly bool $isM1Vehicle,
        public readonly bool $m1LimitException,
        public readonly ?string $putIntoUseDate,         // Y-m-d | null
        public readonly ?string $disposalDate,           // Y-m-d | null
        public readonly ?int $accUsefulLifeMonths,
        public readonly float $accResidualValue,
        public readonly int $openingTaxYears,
        public readonly float $openingTaxAmount,
        public readonly int $openingAccMonths,
        public readonly float $openingAccAmount,
        /** @var list<array{completed_on:string, amount:float}> TZ vzestupně dle data */
        public readonly array $improvements = [],
        /** @var list<array{fiscal_year:int, kind:string, amount:float, full_amount:float, is_paused:bool, is_half:bool}> potvrzené řádky vzestupně */
        public readonly array $confirmedEntries = [],
        public readonly FiscalCalendar $calendar = new FiscalCalendar(),
    ) {}
}
