<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Report;

use MyInvoice\Repository\Payroll\PayrollMigrationReconciliationRepository;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;

/**
 * Kontrolní sestava „naše přepočtená mzda vs. mzda převzatá z původního systému".
 *
 * Skládá jen data: převzatou stranu z `payroll_migration_reference_totals`, naši
 * z výsledků mzdových běhů. Porovnání dělá {@see PayrollMigrationReconciliationBuilder},
 * který je čistou funkcí a jde testovat bez databáze.
 */
final class PayrollMigrationReconciliationService
{
    private readonly PayrollMigrationReconciliationBuilder $builder;

    public function __construct(
        private readonly PayrollMigrationReconciliationRepository $repository,
        ?PayrollMigrationReconciliationBuilder $builder = null,
    ) {
        $this->builder = $builder ?? new PayrollMigrationReconciliationBuilder();
    }

    /** @return array<string,mixed> */
    public function report(int $supplierId, int $year, ?string $source = null): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }
        if ($source !== null && !in_array($source, PayrollMigrationReferenceTotalsWriter::SOURCES, true)) {
            throw new \InvalidArgumentException('Neznámý zdroj převzatých mezd.');
        }

        $calculated = $this->repository->calculatedTotals($supplierId, $year);
        $report = $this->builder->build(
            $year,
            $this->repository->referenceTotals($supplierId, $year, $source),
            $calculated,
            $this->repository->calculatedEmployerSocial($supplierId, $year),
        );

        $statuses = $this->revisionStatuses($calculated);
        $report['months'] = array_map(
            static fn (array $month): array => [
                ...$month,
                'calculated_revision_status' => $statuses[$month['period']] ?? null,
            ],
            $report['months'],
        );
        $report['sources'] = $this->repository->referenceSources($supplierId, $year);
        $report['source'] = $source;

        return $report;
    }

    /**
     * Stav revize, ze které se naše strana čte. Sestava schválně nepracuje jen se
     * schválenými revizemi — celý její smysl je podívat se na přepočet dřív, než ho
     * účetní schválí —, takže na obrazovce musí být vidět, že jde o rozpracovaný stav.
     * Dva různé stavy v jednom měsíci (víc provozoven) hlásí `mixed`.
     *
     * @param list<array<string,mixed>> $calculated
     * @return array<string,string>
     */
    private function revisionStatuses(array $calculated): array
    {
        $statuses = [];
        foreach ($calculated as $row) {
            $period = substr((string) ($row['period'] ?? ''), 0, 7);
            $status = (string) ($row['revision_status'] ?? '');
            if ($period === '' || $status === '') {
                continue;
            }
            $statuses[$period] = ($statuses[$period] ?? $status) === $status ? $status : 'mixed';
        }

        return $statuses;
    }
}
