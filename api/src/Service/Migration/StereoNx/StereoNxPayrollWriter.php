<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollMigrationReconciliationRepository;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use PDO;

/** Převzaté měsíční úhrny; nepočítá mzdu, nezakládá běh ani účetní zápis. */
final class StereoNxPayrollWriter
{
    private const SOURCE = 'stereo_nx';
    private const KIND = 'payroll_month';

    public function __construct(
        private readonly Connection $db,
        private readonly StereoNxImportMap $map,
        private readonly PayrollMigrationReferenceTotalsWriter $referenceTotals,
        private readonly PayrollHistoricalPeriodService $historical,
        private readonly PayrollMigrationReconciliationRepository $reconciliation,
    ) {}

    /** Volající vlastní transakci včetně zápisu osob a vztahů.
     * @param array<string,mixed> $plan */
    public function write(array $plan, int $supplierId, ?int $userId = null): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new StereoNxException('transaction_required', 'Převod mezd musí proběhnout v transakci.');
        }
        $ico = $plan['source_ico'] ?? null;
        $company = $plan['source_company_index'] ?? null;
        $records = $plan['records'] ?? null;
        if ($supplierId <= 0 || !is_string($ico) || preg_match('/^[0-9]{8}$/D', $ico) !== 1
            || !is_int($company) || $company < 0 || !is_array($records)) {
            throw new StereoNxException('payroll_plan_invalid', 'Plán převodu mezd nemá platnou identitu.');
        }
        $counts = ['historical_payroll_created' => 0, 'historical_payroll_existing' => 0,
            'historical_payroll_skipped' => (int) ($plan['counts']['historical_payroll_skipped'] ?? 0)];
        $warnings = [];
        if ($records === []) return ['counts' => $counts, 'warnings' => []];
        $enabled = $pdo->prepare('SELECT payroll_enabled FROM supplier WHERE id = ? FOR UPDATE');
        $enabled->execute([$supplierId]);
        if ((int) $enabled->fetchColumn() !== 1) {
            $counts['historical_payroll_skipped'] += count($records);
            self::warning($warnings, 'payroll_module_disabled', 'Firma nemá zapnutý modul Mzdy; historické mzdy nebyly převzaty.');
            return ['counts' => $counts, 'warnings' => array_values($warnings)];
        }
        $start = $this->historical->startPeriod($supplierId);
        foreach ($records as $record) {
            if (!is_array($record)) throw new StereoNxException('payroll_plan_invalid', 'Neplatný plán mzdového měsíce.');
            $hash = $record['source_hash'] ?? null;
            $content = $record;
            unset($content['source_hash']);
            if (!is_string($hash) || !hash_equals(StereoNxImportMap::fingerprint($content), $hash)) {
                throw new StereoNxException('payroll_source_hash_invalid', 'Otisk měsíční mzdy neodpovídá obsahu záznamu.');
            }
            $period = $record['period'] ?? null;
            $employeeKey = $record['employee_key'] ?? null;
            $sourceKey = $record['source_key'] ?? null;
            if (!is_string($period) || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1
                || !is_string($employeeKey) || !is_string($sourceKey)) {
                throw new StereoNxException('payroll_plan_invalid', 'Neplatné období nebo klíč mzdového měsíce.');
            }
            if ($start === null || !PayrollHistoricalPeriodService::precedesStart($start, $period)) {
                $counts['historical_payroll_skipped']++;
                self::warning($warnings, $start === null ? 'payroll_start_missing' : 'payroll_period_not_historical',
                    $start === null
                        ? 'Nastavte začátek vedení mezd v MyÚčtu a převod zopakujte; bez něj nelze určit historické měsíce.'
                        : 'Mzdy od začátku vedení mezd v MyÚčtu nebyly převzaty jako historie. Datum začátku převod nemění.');
                continue;
            }
            $employee = $this->map->get($supplierId, $ico, $company, 'payroll_employee', $employeeKey);
            $employment = $this->map->get($supplierId, $ico, $company, 'payroll_employment', $employeeKey);
            if ($employee === null || $employment === null) {
                $counts['historical_payroll_skipped']++;
                self::warning($warnings, 'payroll_employee_missing',
                    'Historická mzda nebyla převzata, protože se nepřevedla odpovídající osoba a pracovní vztah.');
                continue;
            }
            $employeeId = $employee['target_id'];
            $employmentId = $employment['target_id'];
            $this->assertPair($supplierId, $employeeId, $employmentId);
            // Vztah má stejné externí ID ve všech měsících; délka zdrojového klíče
            // není omezena menším VARCHAR(64) společné evidence převzatých mezd.
            $reference = hash('sha256', json_encode([$ico, $company, $employeeKey], JSON_THROW_ON_ERROR));
            $mapped = $this->map->get($supplierId, $ico, $company, self::KIND, $sourceKey);
            if ($mapped !== null) {
                if ($mapped['source_hash'] !== $hash) {
                    throw new StereoNxException('payroll_source_changed', 'Dříve převedená mzda se ve zdroji změnila.');
                }
                $this->assertMappedMonth($supplierId, $mapped['target_id'], $employeeId, $employmentId, $period, $reference);
                $counts['historical_payroll_existing']++;
                continue;
            }
            $this->assertNoCollision($supplierId, $employeeId, $employmentId, $period, $reference);
            if (!is_array($record['facts'] ?? null) || !is_array($record['amounts'] ?? null)) {
                throw new StereoNxException('payroll_plan_invalid', 'Chybí ověřené údaje mzdového měsíce.');
            }
            $facts = new PayrollMigrationTakeoverFacts(...$record['facts']);
            $total = PayrollMigrationReferenceTotals::fromAmounts(
                $period, $reference, $reference, $employeeId, $employmentId, $record['amounts'], $facts,
            );
            $this->referenceTotals->store($supplierId, self::SOURCE, [$total], 'Stereo NX: ' . $ico . '/' . $company);
            $id = $pdo->prepare('SELECT id FROM payroll_migration_reference_totals
                WHERE supplier_id = ? AND source = ? AND period_start = ? AND external_relationship_ref = ?');
            $id->execute([$supplierId, self::SOURCE, $period . '-01', $reference]);
            $targetId = (int) $id->fetchColumn();
            if ($targetId <= 0) throw new StereoNxException('payroll_target_missing', 'Převzatá mzda nebyla po zápisu nalezena.');
            $this->map->put($supplierId, $ico, $company, self::KIND, $sourceKey, $hash, $targetId);
            $counts['historical_payroll_created']++;
        }
        return ['counts' => $counts, 'warnings' => array_values($warnings)];
    }

    private function assertPair(int $supplierId, int $employeeId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employments e
            JOIN payroll_employees p ON p.id = e.employee_id AND p.supplier_id = e.supplier_id
            WHERE e.supplier_id = ? AND e.id = ? AND p.id = ?');
        $stmt->execute([$supplierId, $employmentId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new StereoNxException('payroll_target_mismatch', 'Pracovní vztah nepatří zaměstnanci nebo cílové firmě.');
        }
    }

    private function assertNoCollision(int $supplierId, int $employeeId, int $employmentId, string $period, string $reference): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_migration_reference_totals
            WHERE supplier_id = ? AND period_start = ?
              AND (employment_id = ? OR (source = ? AND external_relationship_ref = ?)) LIMIT 1');
        $stmt->execute([$supplierId, $period . '-01', $employmentId, self::SOURCE, $reference]);
        if ($stmt->fetchColumn() !== false
            || in_array($period, $this->reconciliation->calculatedPeriods($supplierId, (int) substr($period, 0, 4), $employeeId), true)) {
            throw new StereoNxException('payroll_period_collision', 'Období už obsahuje jinou převzatou nebo vypočtenou mzdu.');
        }
    }

    private function assertMappedMonth(int $supplierId, int $targetId, int $employeeId, int $employmentId, string $period, string $reference): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT employee_id, employment_id, period_start, external_relationship_ref,
                external_person_ref FROM payroll_migration_reference_totals WHERE supplier_id = ? AND id = ? AND source = ?');
        $stmt->execute([$supplierId, $targetId, self::SOURCE]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['employee_id'] !== $employeeId || (int) $row['employment_id'] !== $employmentId
            || $row['period_start'] !== $period . '-01' || $row['external_relationship_ref'] !== $reference
            || $row['external_person_ref'] !== $reference) {
            throw new StereoNxException('payroll_target_changed', 'Dříve převedená mzda byla změněna nebo odstraněna.');
        }
    }

    private static function warning(array &$warnings, string $code, string $message): void
    {
        $warnings[$code] = ['level' => 'warning', 'code' => $code, 'message' => $message];
    }
}
