<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Posting;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Payroll\PayrollPostingBatchRepository;
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

    /**
     * Záporná nákladová alokace (záporná složka, oprava) náklad SNIŽUJE.
     * Report, který by bral jen kladné alokace, by ji zahodil a náklad
     * nadhodnotil proti deníku. Běžný mzdový běh zápornou složku blokuje,
     * proto se dávka skládá přímo z alokací — přesně v tvaru, jaký staví
     * účetní můstek (MD alokace se zápornou částkou na 521).
     */
    public function testNegativeCostAllocationLowersTheReportedCost(): void
    {
        $run = $this->calculateEnforcementRun();
        $this->approveEnforcementRun($run);
        $batches = $this->service(PayrollPostingBatchRepository::class);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, previous_batch_id,
                 entry_date, status, target_hash, delta_hash, created_by)
             VALUES (?, ?, ?, NULL, "2026-06-30", "prepared", ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $run['run_id'],
            $run['revision_id'],
            hash('sha256', 'target-f3'),
            hash('sha256', 'delta-f3'),
            $this->actorId,
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $employment = $this->employmentId;
        $batches->insertAllocations($this->supplierId, $batchId, [
            $this->allocation("gross:employment:{$employment}:input:1:debit", '521', 3_500_000, $this->valueA),
            $this->allocation("gross:employment:{$employment}:input:1:credit", '331', -3_500_000),
            $this->allocation("gross:employment:{$employment}:input:2:debit", '521', -200_000, $this->valueA),
            $this->allocation("gross:employment:{$employment}:input:2:credit", '331', 200_000),
        ]);
        $batches->markNoChange($this->supplierId, $batchId);

        $report = $this->service(PayrollDimensionCostReportService::class)
            ->report($this->supplierId, 2026);

        self::assertSame(3_300_000, $report['totals']['wages_minor']);
        self::assertSame(3_300_000, $report['totals']['total_minor']);
    }

    /** @return array<string,mixed> */
    private function allocation(string $key, string $account, int $signedMinor, ?int $valueId = null): array
    {
        $allocation = [
            'allocation_key' => $key,
            'account_code' => $account,
            'signed_minor' => $signedMinor,
            'description' => 'Syntetická alokace',
        ];
        if ($valueId !== null) {
            $allocation['dimensions'] = [$this->typeId => $valueId];
        }

        return $allocation;
    }

    /** Dimenzi z rozpadu nejde deaktivovat — snapshot by nesl jen 70 %. */
    public function testDeactivatingADimensionOfASplitIsRejected(): void
    {
        [$dimensionA] = $this->splitSeventyThirty();
        $current = $this->service(\MyInvoice\Repository\Payroll\PayrollDimensionRepository::class)
            ->find($this->supplierId, $dimensionA);
        self::assertNotNull($current);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('částí procentního rozpadu');
        $this->service(PayrollDimensionService::class)->save(
            $this->supplierId,
            $dimensionA,
            [
                'dimension_type' => 'cost_center',
                'code' => 'STR-A',
                'name' => 'STR-A',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => false,
                'default_account_code' => null,
                'dimension_value_id' => $this->valueA,
            ],
            $current['row_version'],
            $this->actorId,
        );
    }

    /**
     * Druhá pojistka: kdyby se rozpad přesto rozpadl (přímý zásah do dat),
     * zastaví ho už zmrazení vstupů, ne až zaúčtování schváleného běhu.
     */
    public function testIncompleteSplitIsStoppedWhenTheSnapshotIsFrozen(): void
    {
        [$dimensionA] = $this->splitSeventyThirty();
        $this->db->pdo()->prepare(
            'UPDATE payroll_dimensions SET is_active = 0, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $dimensionA]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Procentní rozpad mzdových dimenzí');
        $this->calculateEnforcementRun();
    }

    /** 100 % od 1. 1. jde nahradit rozpadem od téhož dne a chybný rozpad opravit. */
    public function testSplitFromTheSameDayReplacesTheAssignment(): void
    {
        $dimensionA = $this->payrollDimension('STR-A', $this->valueA);
        $dimensionB = $this->payrollDimension('STR-B', $this->valueB);
        $splits = $this->service(PayrollEmploymentDimensionService::class);
        $splits->create(
            $this->supplierId,
            $this->employmentId,
            ['dimension_id' => $dimensionA, 'valid_from' => '2026-01-01'],
            $this->actorId,
        );

        $splits->split($this->supplierId, $this->employmentId, $this->splitInput($dimensionA, $dimensionB, 60, 40), $this->actorId);
        $splits->split($this->supplierId, $this->employmentId, $this->splitInput($dimensionA, $dimensionB, 70, 30), $this->actorId);

        $rows = $this->service(\MyInvoice\Repository\Payroll\PayrollEmploymentDimensionRepository::class)
            ->listForEmployment($this->supplierId, $this->employmentId);
        $shares = [];
        foreach ($rows as $row) {
            $shares[$row['dimension_id']] = $row['share_percent'];
        }
        ksort($shares);
        self::assertSame([$dimensionA => 70, $dimensionB => 30], $shares);
    }

    /** Měsíc schválený podle dosavadního přiřazení se rozpadem přepsat nesmí. */
    public function testSplitOverAnApprovedMonthIsRejected(): void
    {
        [$dimensionA, $dimensionB] = $this->splitSeventyThirty();
        $run = $this->calculateEnforcementRun();
        $this->approveEnforcementRun($run);

        $this->expectException(\MyInvoice\Repository\Payroll\PayrollEmploymentDimensionOverlapException::class);
        $this->expectExceptionMessage('schválené mzdové revizi');
        $this->service(PayrollEmploymentDimensionService::class)->split(
            $this->supplierId,
            $this->employmentId,
            $this->splitInput($dimensionA, $dimensionB, 50, 50),
            $this->actorId,
        );
    }

    /** Hodnota, která firmě mezitím přestala patřit, se do deníku nezapíše. */
    public function testFrozenValueThatNoLongerBelongsToTheFirmStopsThePosting(): void
    {
        $this->splitSeventyThirty();
        $run = $this->calculateEnforcementRun();
        $this->approveEnforcementRun($run);
        $pdo = $this->db->pdo();
        $foreignSupplier = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier WHERE id <> ' . $this->supplierId
        )->fetchColumn();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'CIZI-F3B', 'Cizí', 'cost_center')")
            ->execute([$foreignSupplier]);
        $foreignType = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE dimension_values SET type_id = ?, supplier_id = ? WHERE id = ?')
            ->execute([$foreignType, $foreignSupplier, $this->valueB]);

        [$input, $result] = $this->revisionSnapshots($run['revision_id']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('už nemůže použít');
        $this->service(PayrollApprovedRevisionPostingService::class)->postManually(
            $this->supplierId,
            $run['revision_id'],
            $input,
            $result,
            $this->actorId,
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

    /** @return array{int,int} mzdové dimenze STR-A a STR-B */
    private function splitSeventyThirty(): array
    {
        $dimensionA = $this->payrollDimension('STR-A', $this->valueA);
        $dimensionB = $this->payrollDimension('STR-B', $this->valueB);
        $this->service(PayrollEmploymentDimensionService::class)->split(
            $this->supplierId,
            $this->employmentId,
            $this->splitInput($dimensionA, $dimensionB, 70, 30),
            $this->actorId,
        );

        return [$dimensionA, $dimensionB];
    }

    /** @return array<string,mixed> */
    private function splitInput(int $dimensionA, int $dimensionB, int $shareA, int $shareB): array
    {
        return [
            'dimension_type' => 'cost_center',
            'valid_from' => '2026-01-01',
            'shares' => [
                ['dimension_id' => $dimensionA, 'share_percent' => $shareA],
                ['dimension_id' => $dimensionB, 'share_percent' => $shareB],
            ],
        ];
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
