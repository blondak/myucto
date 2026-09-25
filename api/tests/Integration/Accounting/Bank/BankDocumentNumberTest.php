<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\Bank\SupplierBankAccountAction;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankDocumentNumber;
use MyInvoice\Service\Accounting\Bank\BankDocumentNumberBackfill;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Číslo dokladu bankovního zápisu = dokladová řada účtu + měsíc zápisu (BCR-08).
 * Nesmí záviset na výpisu, přes který pohyb přišel, a platí pro každou cestu,
 * která bankovní zápis zakládá.
 */
#[Group('integration')]
final class BankDocumentNumberTest extends BankPostingTestCase
{
    private const SERIES = 'BTST';

    private int $accountId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountId = $this->ownAccount(self::ACCOUNT, self::BANK_CODE, self::SERIES);
    }

    public function testPostingUsesAccountSeriesAndMonth(): void
    {
        $tx = $this->transaction($this->statement(), -250.00, ['posted_at' => self::YEAR . '-08-14']);
        $entryId = $this->postManual($tx);

        self::assertSame(self::SERIES . '-08', $this->documentNo($entryId));
    }

    /**
     * Denní výpis z bankovního API, pozdější měsíční výpis a nový import po smazání:
     * pokaždé jiný výpis i jiné id pohybu, číslo dokladu stejné.
     */
    public function testDailyAndMonthlyStatementGiveSameNumber(): void
    {
        $daily = $this->transaction($this->statement(), -99.00, ['posted_at' => self::YEAR . '-03-05']);
        $monthly = $this->transaction($this->statement(), -99.00, ['posted_at' => self::YEAR . '-03-05']);

        $first = $this->documentNo($this->postManual($daily));
        $this->service->unpost($this->supplierId, $daily, $this->meta());
        $second = $this->documentNo($this->postManual($monthly));

        self::assertSame(self::SERIES . '-03', $first);
        self::assertSame($first, $second);
    }

    public function testCallerDocumentNumberIsIgnoredForBankSource(): void
    {
        $tx = $this->transaction($this->statement(), -10.00, ['posted_at' => self::YEAR . '-11-30']);
        $entryId = $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            ['account_code' => '568', 'side' => 'debit', 'amount' => 10.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 10.00],
        ], ['entry_date' => self::YEAR . '-11-30', 'document_no' => 'CALLER-NO', 'user_id' => $this->userId]);

        self::assertSame(self::SERIES . '-11', $this->documentNo($entryId));
    }

    public function testReversalCarriesSeriesNumber(): void
    {
        $tx = $this->transaction($this->statement(), -40.00, ['posted_at' => self::YEAR . '-06-15']);
        $this->postManual($tx);
        $reversalId = $this->service->unpost($this->supplierId, $tx, $this->meta());

        self::assertSame('STORNO ' . self::SERIES . '-06', $this->documentNo($reversalId));
    }

    public function testStatementOfUnknownAccountKeepsBankReference(): void
    {
        $statement = $this->statement('1000000005', '0100');
        $tx = $this->transaction($statement, -5.00);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET bank_ref = ? WHERE id = ?')->execute(['SYNTH-REF-1', $tx]);

        self::assertSame('SYNTH-REF-1', (new BankDocumentNumber($this->db))->forTransaction($this->supplierId, $tx, self::YEAR . '-06-15'));
    }

    public function testMissingSeriesIsAssignedFromKindAndCurrency(): void
    {
        $numbers = new BankDocumentNumber($this->db);
        self::assertSame('BCR', BankDocumentNumber::defaultBase('current', 'CZK'));
        self::assertSame('BCE', BankDocumentNumber::defaultBase('current', 'EUR'));
        self::assertSame('BCS', BankDocumentNumber::defaultBase('savings', 'CZK'));

        $taken = $numbers->nextFreeSeries($this->supplierId, 'BCE');
        $id = $this->ownAccount('1000000013', '0100', null, 'EUR');
        $row = $this->accountRow($id);
        $assigned = $numbers->ensureSeries($this->supplierId, $row);

        self::assertSame($taken, $assigned);
        self::assertSame($assigned, $this->accountRow($id)['document_series']);
        self::assertSame($assigned, $numbers->ensureSeries($this->supplierId, $this->accountRow($id)), 'Přidělená řada se už nemění.');
    }

    public function testBackfillRenumbersOpenPeriodAndIsIdempotent(): void
    {
        $tx = $this->transaction($this->statement(), -70.00, ['posted_at' => self::YEAR . '-09-01']);
        $entryId = $this->legacyEntry($tx, 'SYNTH-OLD-1', self::YEAR . '-09-01');
        $backfill = new BankDocumentNumberBackfill($this->db);

        $dry = $backfill->run($this->supplierId, self::YEAR . '-01-01', false);
        self::assertContains($entryId, array_column($dry['changes'], 'entry_id'));
        self::assertSame('SYNTH-OLD-1', $this->documentNo($entryId), 'Dry-run nic nezapisuje.');

        $first = $backfill->run($this->supplierId, self::YEAR . '-01-01', true);
        self::assertSame(self::SERIES . '-09', $this->documentNo($entryId));
        self::assertGreaterThanOrEqual(1, $first['changed']);

        $second = $backfill->run($this->supplierId, self::YEAR . '-01-01', true);
        self::assertSame(0, $second['changed'], 'Druhý běh nemá co měnit.');
    }

    public function testBackfillLeavesClosedPeriodUntouched(): void
    {
        $tx = $this->transaction($this->statement(), -71.00, ['posted_at' => self::YEAR . '-09-02']);
        $entryId = $this->legacyEntry($tx, 'SYNTH-OLD-2', self::YEAR . '-09-02');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        (new BankDocumentNumberBackfill($this->db))->run($this->supplierId, self::YEAR . '-01-01', true);

        self::assertSame('SYNTH-OLD-2', $this->documentNo($entryId));
    }

    /** Stornovaný zápis je odpojený od pohybu (source_id NULL); číslo i storno se přesto přečíslují. */
    public function testBackfillRenumbersDetachedOriginalAndItsReversal(): void
    {
        $tx = $this->transaction($this->statement(), -72.00, ['posted_at' => self::YEAR . '-10-10']);
        $originalId = $this->legacyEntry($tx, 'BANK-' . $tx, self::YEAR . '-10-10');
        $reversalId = $this->posting->reverse($this->supplierId, $originalId, [
            'entry_date' => self::YEAR . '-10-10', 'user_id' => $this->userId,
        ]);
        $this->db->pdo()->prepare('UPDATE journal_entries SET source_id = NULL WHERE id = ?')->execute([$originalId]);
        self::assertSame('STORNO BANK-' . $tx, $this->documentNo($reversalId));

        (new BankDocumentNumberBackfill($this->db))->run($this->supplierId, self::YEAR . '-01-01', true);

        self::assertSame(self::SERIES . '-10', $this->documentNo($originalId));
        self::assertSame('STORNO ' . self::SERIES . '-10', $this->documentNo($reversalId));
    }

    /**
     * Převod z jiného programu zapíše bankovní zápis s číslem dokladu zdroje a naváže ho
     * na převzatý pohyb. To číslo je vazba na původní doklad: nepřečísluje ho backfill
     * ani přeúčtování pohybu.
     */
    public function testTakenOverEntryKeepsSourceDocumentNumber(): void
    {
        $tx = $this->transaction($this->statement(), -73.00, ['posted_at' => self::YEAR . '-10-11']);
        $entryId = $this->legacyEntry($tx, 'SYNTH-BV-0042', self::YEAR . '-10-11');
        $this->db->pdo()->prepare(
            "INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'journal_entry', ?, ?)"
        )->execute([$this->supplierId, 'synth-' . $entryId, $entryId]);

        $result = (new BankDocumentNumberBackfill($this->db))->run($this->supplierId, self::YEAR . '-01-01', true);
        self::assertNotContains($entryId, array_column($result['changes'], 'entry_id'));
        self::assertSame('SYNTH-BV-0042', $this->documentNo($entryId));

        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            ['account_code' => '568', 'side' => 'debit', 'amount' => 73.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 73.00],
        ], ['entry_date' => self::YEAR . '-10-11', 'user_id' => $this->userId]);
        self::assertSame('SYNTH-BV-0042', $this->documentNo($entryId), 'Přeúčtování převzatého zápisu číslo zdroje nemění.');
    }

    public function testDeactivatedAccountKeepsItsSeries(): void
    {
        $tx = $this->transaction($this->statement(), -12.00, ['posted_at' => self::YEAR . '-02-03']);
        $this->db->pdo()->prepare('UPDATE supplier_bank_accounts SET is_active = 0 WHERE id = ?')->execute([$this->accountId]);

        self::assertSame(self::SERIES . '-02', (new BankDocumentNumber($this->db))->forTransaction($this->supplierId, $tx, self::YEAR . '-02-03'));
    }

    public function testChangingSeriesInSettingsRenumbersEntries(): void
    {
        $tx = $this->transaction($this->statement(), -80.00, ['posted_at' => self::YEAR . '-04-20']);
        $entryId = $this->postManual($tx);
        $action = $this->container->get(SupplierBankAccountAction::class);

        $res = $this->callAction($action, 'update', 'PATCH', 'accountant', ['document_series' => 'bnew'], ['id' => (string) $this->accountId]);

        self::assertSame(200, $res['status']);
        self::assertSame('BNEW', $res['body']['document_series']);
        self::assertGreaterThanOrEqual(1, $res['body']['renumbered_entries']);
        self::assertSame('BNEW-04', $this->documentNo($entryId));
    }

    public function testSeriesMustBeUniqueAndWellFormed(): void
    {
        $other = $this->ownAccount('1000000013', '0100', 'BOTHER');
        $action = $this->container->get(SupplierBankAccountAction::class);

        $clash = $this->callAction($action, 'update', 'PATCH', 'accountant', ['document_series' => self::SERIES], ['id' => (string) $other]);
        $invalid = $this->callAction($action, 'update', 'PATCH', 'accountant', ['document_series' => 'B-1'], ['id' => (string) $other]);
        $empty = $this->callAction($action, 'update', 'PATCH', 'accountant', ['document_series' => ''], ['id' => (string) $other]);

        self::assertSame(422, $clash['status']);
        self::assertSame(422, $invalid['status']);
        self::assertSame(422, $empty['status']);
        self::assertSame('BOTHER', $this->accountRow($other)['document_series']);
    }

    public function testJournalSearchFindsEntryByBankReference(): void
    {
        $tx = $this->transaction($this->statement(), -33.00, ['posted_at' => self::YEAR . '-05-05']);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET bank_ref = ? WHERE id = ?')->execute(['SYNTH-SEARCH-77', $tx]);
        $entryId = $this->postManual($tx);

        $found = $this->container->get(JournalEntryRepository::class)
            ->paginate($this->supplierId, ['document_no' => 'SYNTH-SEARCH-77'], 50, 0);

        self::assertSame([$entryId], array_column($found['items'], 'id'));
        self::assertSame('SYNTH-SEARCH-77', $found['items'][0]['source_bank_ref']);
        self::assertSame(self::SERIES . '-05', $found['items'][0]['document_no']);
    }

    private function postManual(int $tx): int
    {
        $res = $this->service->postManual($this->supplierId, $tx, [
            'debit_account_code' => '568', 'credit_account_code' => '221',
        ], $this->meta());
        return (int) $res['entry_id'];
    }

    private function legacyEntry(int $tx, string $documentNo, string $date): int
    {
        $entryId = $this->postManual($tx);
        $this->db->pdo()->prepare('UPDATE journal_entries SET document_no = ? WHERE id = ?')->execute([$documentNo, $entryId]);
        self::assertSame($date, (string) $this->journal->find($entryId, $this->supplierId)['entry_date']);
        return $entryId;
    }

    private function documentNo(int $entryId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT document_no FROM journal_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        $no = $stmt->fetchColumn();
        return $no === false || $no === null ? null : (string) $no;
    }

    private function ownAccount(string $account, string $bankCode, ?string $series, string $currency = 'CZK'): int
    {
        $canonical = (string) AccountNumberNormalizer::canonical($account);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, "Testovací účet", ?, ?, ?, ?, ?, "current", "manual", 1)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), is_active = 1'
        )->execute([$this->supplierId, $account, $bankCode, $bankCode, $currency, $canonical]);
        $id = (int) $pdo->lastInsertId();
        if ($series !== null) {
            $pdo->prepare('UPDATE supplier_bank_accounts SET document_series = NULL WHERE supplier_id = ? AND document_series = ?')
                ->execute([$this->supplierId, $series]);
        }
        $pdo->prepare('UPDATE supplier_bank_accounts SET document_series = ? WHERE id = ?')->execute([$series, $id]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function accountRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier_bank_accounts WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
