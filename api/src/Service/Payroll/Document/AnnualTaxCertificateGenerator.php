<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Vystavení ročního potvrzení o zdanitelných příjmech jedné osobě.
 *
 * Jediná implementace je {@see AnnualTaxCertificateService}. Rozhraní existuje
 * kvůli frontě ročních dokumentů: ta rozhoduje o položce podle výsledku
 * generování, a ten se v testu nad skutečnou databází nedá vyrobit, protože
 * generování si transakci otevírá samo a roční revize nejdou smazat.
 */
interface AnnualTaxCertificateGenerator
{
    /**
     * @param null|callable(array<string,mixed>):void $beforeCommit
     * @return array<string,mixed>
     * @throws AnnualTaxCertificateNoIncomeException osoba nemá příjem tohoto druhu
     */
    public function generate(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
        ?int $actorUserId,
        ?callable $beforeCommit = null,
        ?int $supersedesDocumentId = null,
        ?string $correctionReason = null,
    ): array;
}
