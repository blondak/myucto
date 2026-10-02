<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use DateTimeImmutable;

/**
 * Provedení ročního zúčtování jedné osoby.
 *
 * Jediná implementace je {@see AnnualTaxSettlementService}. Rozhraní existuje
 * kvůli hromadné frontě: ta potřebuje rozhodnout o položce podle výsledku
 * `settle()`, a ten se v testu nad skutečnou databází vyrobit nedá, protože
 * `settle()` si transakci otevírá sám a roční revize nejdou smazat.
 */
interface AnnualSettlementPerformer
{
    /**
     * @return array{
     *   result:AnnualSettlementResult,
     *   outcome:?array<string,mixed>,
     *   document:?array<string,mixed>,
     *   created:bool
     * }
     */
    public function settle(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        ?int $actorUserId,
        ?DateTimeImmutable $today = null,
    ): array;
}
