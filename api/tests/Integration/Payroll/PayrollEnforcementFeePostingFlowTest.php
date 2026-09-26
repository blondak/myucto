<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Paušální náhrada plátce mzdy (§ 270 odst. 2 o. s. ř.) celým tokem:
 * exekuce → mzdový běh → schválení → zaúčtování → platební závazek.
 *
 * Zaměstnanci se srazí celá částka včetně paušálu, oprávněnému se posílá jen
 * sražené MÍNUS paušál. Dokud se paušál z 379.200 nepřeváděl na výnos,
 * zůstával na závazku exekučních srážek každý měsíc zůstatek, který žádná
 * platba nevyrovnala.
 */
#[Group('integration')]
final class PayrollEnforcementFeePostingFlowTest extends TestCase
{
    use EnforcementRunFixtureTrait;

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
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testFlatFeeLeavesTheEnforcementLiabilityEqualToWhatIsPaidOut(): void
    {
        $caseId = $this->seedRunCase('remit');
        $this->seedRunParty($caseId, 'beneficiary', 'Syntetický oprávněný', null);
        $this->seedRunClaim($caseId);
        $this->seedRunMonthEvidence();

        $run = $this->calculateEnforcementRun();
        $withheld = (int) $run['enforcement']['total_withheld_minor_units'];
        $fee = (int) $run['enforcement']['employer_flat_fee_minor_units'];
        self::assertGreaterThan(0, $fee, 'Fixture musí paušál plátce mzdy uplatnit.');
        self::assertGreaterThan($fee, $withheld);
        $this->approveEnforcementRun($run);

        [$input, $result] = $this->revisionSnapshots($run['revision_id']);
        self::assertSame(
            '648',
            $input['employer']['accounting_accounts']['enforcement_fee_revenue_credit'] ?? null,
            'Snapshot běhu nezmrazil výnosový účet paušálu.',
        );

        $posted = $this->service(PayrollApprovedRevisionPostingService::class)->postManually(
            $this->supplierId,
            $run['revision_id'],
            $input,
            $result,
            $this->actorId,
        );
        self::assertNotNull($posted);

        $balances = $this->journalBalances($run['revision_id']);
        // Závazek exekučních srážek = to, co jde oprávněnému; paušál je výnos.
        self::assertSame(-($withheld - $fee), $balances['379.200'] ?? 0);
        self::assertSame(-$fee, $balances['648'] ?? 0);

        // Reconciliace mzdy × deník: kategorie exekucí bez rozdílu.
        $reconciliation = $this->service(PayrollPostingReconciliationService::class)
            ->forPeriod($this->supplierId, '2026-06');
        $byKey = array_column($reconciliation['categories'], null, 'key');
        self::assertSame($withheld - $fee, $byKey['enforcement']['payroll_minor']);
        self::assertSame($withheld - $fee, $byKey['enforcement']['journal_minor']);

        // Platební strana: materializace posílá oprávněnému právě pohyby
        // `withheld` exekučního ledgeru (paušál je samostatný pohyb, který
        // neodchází) — zůstatek 379.200 se jimi musí vyrovnat na nulu.
        $ledger = $this->enforcementLedger();
        $paidOut = array_sum(array_column(array_filter(
            $ledger,
            static fn (array $row): bool => $row['entry_kind'] === 'withheld',
        ), 'amount_minor_units'));
        $feeLedger = array_sum(array_column(array_filter(
            $ledger,
            static fn (array $row): bool => $row['entry_kind'] === 'employer_fee',
        ), 'amount_minor_units'));
        self::assertSame($fee, $feeLedger);
        self::assertSame(
            $withheld - $fee,
            $paidOut,
            'Platba oprávněnému se liší od zůstatku 379.200.',
        );
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

    /** @return array<string,int> účet => MD minus D v haléřích */
    private function journalBalances(int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT account.account_code,
                    CAST(ROUND(SUM(CASE WHEN line.side = 'debit'
                                        THEN line.amount ELSE -line.amount END) * 100) AS SIGNED) AS balance
               FROM journal_entry_lines line
               JOIN journal_entries entry
                 ON entry.supplier_id = line.supplier_id AND entry.id = line.entry_id
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id AND account.id = line.account_id
              WHERE line.supplier_id = ?
                AND entry.source_type = 'payroll'
                AND entry.source_id = ?
              GROUP BY account.account_code"
        );
        $stmt->execute([$this->supplierId, $revisionId]);
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['account_code']] = (int) $row['balance'];
        }

        return $result;
    }
}
