<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Posting;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Report\PayrollDimensionCostReportService;
use MyInvoice\Service\Payroll\Settings\PayrollDimensionService;
use MyInvoice\Service\Payroll\Settings\PayrollEmploymentDimensionService;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mzdy na firemní dimenze celým tokem (PLAN-DIMENZE-UCTOVANI F3):
 * mzdové středisko navázané na firemní hodnotu → rozpad vztahu 70 / 30 →
 * mzdový běh → schválení → zaúčtování → `journal_entry_line_dimensions`
 * → report nákladů na zaměstnance po dimenzi.
 *
 * Celý test běží v transakci fixture a na konci se vrací, takže mzdové
 * tabulky (immutable) nezůstanou zaneřáděné.
 */
#[Group('integration')]
final class PayrollCompanyDimensionPostingFlowTest extends TestCase
{
    use EnforcementRunFixtureTrait;

    private int $typeId;
    private int $valueA;
    private int $valueB;

    protected function setUp(): void
    {
        $this->bootEnforcementRun();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->service(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->service(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2026, '2026-01-01', '2026-12-31');

        $pdo->prepare(
            "INSERT INTO dimension_types (supplier_id, code, name, kind)
             VALUES (?, 'STR', 'Středisko', 'cost_center')"
        )->execute([$this->supplierId]);
        $this->typeId = (int) $pdo->lastInsertId();
        $this->valueA = $this->companyValue('STR-A', 'Středisko A');
        $this->valueB = $this->companyValue('STR-B', 'Středisko B');
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testSeventyThirtySplitReachesTheJournalAndTheCostReport(): void
    {
        $dimensionA = $this->payrollDimension('STR-A', $this->valueA);
        $dimensionB = $this->payrollDimension('STR-B', $this->valueB);
        $this->service(PayrollEmploymentDimensionService::class)->split(
            $this->supplierId,
            $this->employmentId,
            [
                'dimension_type' => 'cost_center',
                'valid_from' => '2026-01-01',
                'shares' => [
                    ['dimension_id' => $dimensionA, 'share_percent' => 70],
                    ['dimension_id' => $dimensionB, 'share_percent' => '30.00'],
                ],
            ],
            $this->actorId,
        );

        $run = $this->calculateEnforcementRun();
        $this->approveEnforcementRun($run);
        [$input, $result] = $this->revisionSnapshots($run['revision_id']);
        $posted = $this->service(PayrollApprovedRevisionPostingService::class)->postManually(
            $this->supplierId,
            $run['revision_id'],
            $input,
            $result,
            $this->actorId,
        );
        self::assertNotNull($posted);

        $lines = $this->journalLines($run['revision_id']);
        self::assertSame(
            [$this->valueA => 2_450_000, $this->valueB => 1_050_000],
            $this->byValue($lines, '521', 'debit'),
            'Hrubá mzda 35 000 Kč se rozpadne 70 / 30 a řádky nesou firemní dimenzi.',
        );
        self::assertSame(
            ['STR-A' => 2_450_000, 'STR-B' => 1_050_000],
            $this->byCostCentre($lines, '521', 'debit'),
            'Textový cost_center zůstává naplněný kvůli zpětné kompatibilitě.',
        );

        $insurance = $this->byValue($lines, '524', 'debit');
        self::assertSame([$this->valueA, $this->valueB], array_keys($insurance));
        $insuranceTotal = array_sum($insurance);
        self::assertGreaterThan(0, $insuranceTotal);
        // Sociální i zdravotní se dělí zvlášť, každé na haléř — proti 70 %
        // celku se proto může lišit nejvýš o haléř za každé z nich.
        self::assertEqualsWithDelta(
            $insuranceTotal * 0.7,
            $insurance[$this->valueA],
            2,
            'Pojistné zaměstnavatele se dělí stejným podílem 70 %.',
        );
        foreach ($lines as $line) {
            if (str_starts_with($line['account_code'], '3')) {
                self::assertSame([], $line['dimensions'], 'Závazky se na střediska nedělí.');
            }
        }

        $report = $this->service(PayrollDimensionCostReportService::class)
            ->report($this->supplierId, 2026);
        $byValue = [];
        foreach ($report['rows'] as $row) {
            self::assertSame($this->employmentId, $row['employment_id']);
            self::assertCount(1, $row['dimensions']);
            $byValue[$row['dimensions'][0]['value_id']] = $row;
        }
        self::assertSame(2_450_000, $byValue[$this->valueA]['wages_minor']);
        self::assertSame(1_050_000, $byValue[$this->valueB]['wages_minor']);
        self::assertSame($insurance[$this->valueA], $byValue[$this->valueA]['insurance_minor']);
        self::assertSame(
            array_sum(array_map(
                static fn (array $line): int => $line['amount_minor'],
                array_filter(
                    $lines,
                    static fn (array $line): bool => str_starts_with($line['account_code'], '5')
                        && $line['side'] === 'debit',
                ),
            )),
            $report['totals']['total_minor'],
            'Report nákladů sedí na nákladové řádky deníku.',
        );
    }

    public function testSplitThatDoesNotSumToHundredPercentIsRejected(): void
    {
        $dimensionA = $this->payrollDimension('STR-A', $this->valueA);
        $dimensionB = $this->payrollDimension('STR-B', $this->valueB);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('musí dát přesně 100 %');
        $this->service(PayrollEmploymentDimensionService::class)->split(
            $this->supplierId,
            $this->employmentId,
            [
                'dimension_type' => 'cost_center',
                'valid_from' => '2026-01-01',
                'shares' => [
                    ['dimension_id' => $dimensionA, 'share_percent' => 70],
                    ['dimension_id' => $dimensionB, 'share_percent' => 20],
                ],
            ],
            $this->actorId,
        );
    }

    public function testCompanyValueOfAnotherFirmCannotBeLinked(): void
    {
        $pdo = $this->db->pdo();
        $foreignSupplier = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier WHERE id <> ' . $this->supplierId
        )->fetchColumn();
        self::assertGreaterThan(0, $foreignSupplier);
        $pdo->prepare(
            "INSERT INTO dimension_types (supplier_id, code, name, kind)
             VALUES (?, 'CIZI-F3', 'Cizí', 'cost_center')"
        )->execute([$foreignSupplier]);
        $foreignType = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO dimension_values (type_id, supplier_id, code, name)
             VALUES (?, ?, 'CIZI', 'Cizí hodnota')"
        )->execute([$foreignType, $foreignSupplier]);
        $foreignValue = (int) $pdo->lastInsertId();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nepatří firmě ani její skupině');
        $this->payrollDimension('STR-X', $foreignValue);
    }

    /** Stávající přiřazení bez podílu se po migraci chová jako 100 %. */
    public function testPlainAssignmentStaysAHundredPercent(): void
    {
        $dimensionA = $this->payrollDimension('STR-A', $this->valueA);
        $assignment = $this->service(PayrollEmploymentDimensionService::class)->create(
            $this->supplierId,
            $this->employmentId,
            ['dimension_id' => $dimensionA, 'valid_from' => '2026-01-01'],
            $this->actorId,
        );

        self::assertSame(100, $assignment['share_percent']);
    }

    private function companyValue(string $code, string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, ?)'
        )->execute([$this->typeId, $this->supplierId, $code, $name]);

        return (int) $pdo->lastInsertId();
    }

    private function payrollDimension(string $code, int $valueId): int
    {
        $dimension = $this->service(PayrollDimensionService::class)->save(
            $this->supplierId,
            null,
            [
                'dimension_type' => 'cost_center',
                'code' => $code,
                'name' => $code,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
                'default_account_code' => null,
                'dimension_value_id' => $valueId,
            ],
            0,
            $this->actorId,
        );
        self::assertSame($valueId, $dimension['dimension_value_id']);

        return (int) $dimension['id'];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function revisionSnapshots(int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input_snapshot_json, result_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $revisionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            json_decode((string) $row['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) $row['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return list<array{account_code:string,side:string,amount_minor:int,cost_center:?string,dimensions:array<int,int>}>
     */
    private function journalLines(int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT line.id, account.account_code, line.side,
                    CAST(ROUND(line.amount * 100) AS SIGNED) AS amount_minor,
                    line.cost_center
               FROM journal_entry_lines line
               JOIN journal_entries entry
                 ON entry.supplier_id = line.supplier_id AND entry.id = line.entry_id
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id AND account.id = line.account_id
              WHERE line.supplier_id = ?
                AND entry.source_type = 'payroll'
                AND entry.source_id = ?
              ORDER BY line.id"
        );
        $stmt->execute([$this->supplierId, $revisionId]);
        $dimensions = $this->db->pdo()->prepare(
            'SELECT dimension_type_id, dimension_value_id
               FROM journal_entry_line_dimensions
              WHERE supplier_id = ? AND line_id = ?'
        );
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $dimensions->execute([$this->supplierId, (int) $row['id']]);
            $lineDimensions = [];
            foreach ($dimensions->fetchAll(\PDO::FETCH_ASSOC) as $dimension) {
                $lineDimensions[(int) $dimension['dimension_type_id']] = (int) $dimension['dimension_value_id'];
            }
            $result[] = [
                'account_code' => (string) $row['account_code'],
                'side' => (string) $row['side'],
                'amount_minor' => (int) $row['amount_minor'],
                'cost_center' => $row['cost_center'] === null ? null : (string) $row['cost_center'],
                'dimensions' => $lineDimensions,
            ];
        }
        self::assertNotSame([], $result, 'Mzdový předpis se nezaúčtoval.');

        return $result;
    }

    /**
     * @param list<array{account_code:string,side:string,amount_minor:int,cost_center:?string,dimensions:array<int,int>}> $lines
     * @return array<int,int> hodnota firemní dimenze → částka
     */
    private function byValue(array $lines, string $accountPrefix, string $side): array
    {
        $result = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line['account_code'], $accountPrefix) || $line['side'] !== $side) {
                continue;
            }
            $value = $line['dimensions'][$this->typeId] ?? 0;
            $result[$value] = ($result[$value] ?? 0) + $line['amount_minor'];
        }
        ksort($result);

        return $result;
    }

    /**
     * @param list<array{account_code:string,side:string,amount_minor:int,cost_center:?string,dimensions:array<int,int>}> $lines
     * @return array<string,int>
     */
    private function byCostCentre(array $lines, string $accountPrefix, string $side): array
    {
        $result = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line['account_code'], $accountPrefix) || $line['side'] !== $side) {
                continue;
            }
            $key = $line['cost_center'] ?? '';
            $result[$key] = ($result[$key] ?? 0) + $line['amount_minor'];
        }
        ksort($result);

        return $result;
    }
}
