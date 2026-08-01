<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Accounting\DocumentLockService;

/**
 * Retro-guard změn historie plátcovství DPH — sdílený správou historie
 * (VatStatusHistoryAction) i legacy checkboxem v PUT /settings/supplier,
 * aby druhá cesta první neobcházela.
 *
 * Kolize = změna s účinností v uzamčeném účetním období (closing/closed/
 * approved), za zámkem k datu (locked_until) nebo v/před obdobím už podaného
 * přiznání (tax_submissions submitted/accepted) — retro změna by tiše měnila
 * podklad, ze kterého výkazy vznikly.
 */
final class VatStatusGuard
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly AccountingSupplierSettingsRepository $accountingSettings,
    ) {}

    /** @return list<array<string,mixed>> */
    public function collisions(int $supplierId, string $effectiveFrom): array
    {
        $collisions = [];

        $lock = $this->locks->forDate($supplierId, $effectiveFrom);
        if ($lock->inClosedPeriod || $lock->inClosingPeriod) {
            $collisions[] = [
                'type'          => 'locked_period',
                'period_status' => $lock->periodStatus,
            ];
        }
        if ($lock->dateLocked) {
            $collisions[] = [
                'type'         => 'date_lock',
                'locked_until' => $this->accountingSettings->getLockedUntil($supplierId),
            ];
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT form_code, period_year, period_month, period_quarter, MAX(submitted_at) AS submitted_at
               FROM tax_submissions
              WHERE supplier_id = ? AND status IN ('submitted', 'accepted')
                AND (CASE
                        WHEN period_month IS NOT NULL
                            THEN LAST_DAY(CONCAT(period_year, '-', LPAD(period_month, 2, '0'), '-01'))
                        WHEN period_quarter IS NOT NULL
                            THEN LAST_DAY(CONCAT(period_year, '-', LPAD(period_quarter * 3, 2, '0'), '-01'))
                        ELSE CONCAT(period_year, '-12-31')
                     END) >= ?
              GROUP BY form_code, period_year, period_month, period_quarter
              ORDER BY period_year, period_quarter, period_month
              LIMIT 50"
        );
        $stmt->execute([$supplierId, $effectiveFrom]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $sub) {
            $collisions[] = [
                'type'           => 'tax_submission',
                'form_code'      => (string) $sub['form_code'],
                'period_year'    => (int) $sub['period_year'],
                'period_month'   => $sub['period_month'] !== null ? (int) $sub['period_month'] : null,
                'period_quarter' => $sub['period_quarter'] !== null ? (int) $sub['period_quarter'] : null,
                'submitted_at'   => $sub['submitted_at'] !== null ? (string) $sub['submitted_at'] : null,
            ];
        }

        return $collisions;
    }
}
