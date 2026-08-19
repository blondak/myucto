<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\Payroll\PayrollPostingBatchRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPostingServiceImmutabilityTest extends TestCase
{
    private const YEAR = 2097;
    private const REVISION_ID = 2_097_000_001;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private PayrollPostingBatchRepository $batches;
    private AccountingSupplierSettingsRepository $accountingSettings;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped(
                'cfg.php neexistuje — test vyžaduje DB connection.',
            );
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->batches = $container->get(
                PayrollPostingBatchRepository::class,
            );
            $this->accountingSettings = $container->get(
                AccountingSupplierSettingsRepository::class,
            );
            $periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI nedostupné: ' . $exception->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) (
            $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn()
                ?: 0
        );
        $currencyId = (int) (
            $pdo->query(
                "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
            )->fetchColumn() ?: 0
        );
        $vatRateId = (int) (
            $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn()
                ?: 0
        );
        $countryId = (int) (
            $pdo->query(
                "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
            )->fetchColumn() ?: 0
        );
        if ($this->userId === 0
            || $currencyId === 0
            || $vatRateId === 0
            || $countryId === 0
        ) {
            $this->markTestSkipped(
                'Chybí základní data (user/currency/vat_rate/country) v DB.',
            );
        }

        $pdo->beginTransaction();
        $this->inTransaction = true;
        $statement = $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?,
                     "double_entry")',
        );
        $statement->execute([
            'Payroll PostingService immutability s.r.o.',
            $countryId,
            'payroll-posting-immutability@example.com',
            $currencyId,
            $vatRateId,
        ]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $periods->create(
            $this->supplierId,
            self::YEAR,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || !$this->inTransaction) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->db->close();
    }

    public function testPayrollJournalCannotBeReversedAndRemainsUnchanged(): void
    {
        $entryId = $this->postPayroll();
        $before = $this->journal->find($entryId, $this->supplierId);
        self::assertNotNull($before);

        try {
            $this->posting->reverse(
                $this->supplierId,
                $entryId,
                [
                    'entry_date' => self::YEAR . '-07-31',
                    'posted_by' => $this->userId,
                    'user_id' => $this->userId,
                ],
            );
            self::fail('Mzdový předpis nesmí být stornován.');
        } catch (PostingException $exception) {
            self::assertSame(
                'payroll_reversal_forbidden',
                $exception->errorCode,
            );
        }

        $after = $this->journal->find($entryId, $this->supplierId);
        self::assertSame($before, $after);
        self::assertNull($after['reversed_by']);
        self::assertSame(1, $this->sourceEntryCount());
    }

    public function testRejectsPayrollJournalWithoutPreparedBatch(): void
    {
        try {
            $this->postPayroll(false);
            self::fail(
                'Mzdový deník nesmí vzniknout bez připravené dávky schválené revize.',
            );
        } catch (PostingException $exception) {
            self::assertSame(
                'payroll_posting_context_required',
                $exception->errorCode,
            );
        }
        self::assertSame(0, $this->sourceEntryCount());
    }

    public function testNestedPostingTransactionRollsBackToOwnSavepoint(): void
    {
        $before = (string) $this->db->pdo()->query(
            'SELECT company_name FROM supplier WHERE id = ' . $this->supplierId,
        )->fetchColumn();
        try {
            $this->batches->transaction(function (): void {
                $this->db->pdo()->prepare(
                    'UPDATE supplier SET company_name = ?
                      WHERE id = ?',
                )->execute(['Nesmí zůstat', $this->supplierId]);
                throw new \RuntimeException('synthetic nested failure');
            });
            self::fail('Vnořená účetní transakce měla selhat.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'synthetic nested failure',
                $exception->getMessage(),
            );
        }
        $after = (string) $this->db->pdo()->query(
            'SELECT company_name FROM supplier WHERE id = ' . $this->supplierId,
        )->fetchColumn();
        self::assertSame($before, $after);
    }

    public function testPostingRechecksAdvancedDateLockInsideTransaction(): void
    {
        $lines = [
            [
                'account_code' => '521',
                'side' => 'debit',
                'amount' => '1000.00',
            ],
            [
                'account_code' => '331',
                'side' => 'credit',
                'amount' => '1000.00',
            ],
        ];
        $this->posting->postDocument(
            $this->supplierId,
            'manual',
            2_097_100_001,
            $lines,
            ['entry_date' => self::YEAR . '-05-31'],
        );
        $this->accountingSettings->setLockedUntil(
            $this->supplierId,
            self::YEAR . '-06-30',
        );

        try {
            $this->posting->postDocument(
                $this->supplierId,
                'manual',
                2_097_100_002,
                $lines,
                ['entry_date' => self::YEAR . '-06-30'],
            );
            self::fail('Posunutý účetní zámek musí další zápis odmítnout.');
        } catch (PostingException $exception) {
            self::assertSame('date_locked', $exception->errorCode);
        }
    }

    /**
     * Nákladové středisko musí přežít cestu do databáze a zpět.
     *
     * Opravná dávka počítá rozdíl proti ULOŽENÝM alokacím předchozí dávky. Kdyby
     * se středisko cestou ztratilo, zrušená alokace by se vrátila na řádku bez
     * střediska a analytika 524 by se rozešla — v deníku by zůstal náklad na
     * středisku a jeho storno mimo něj.
     */
    public function testPostingAllocationsKeepTheirCostCentreThroughTheDatabase(): void
    {
        $batchId = $this->preparePayrollContext();
        $this->batches->insertAllocations($this->supplierId, $batchId, [
            [
                'allocation_key' => 'employer-insurance:social:employment:1:debit',
                'account_code' => '524',
                'signed_minor' => 33_800,
                'description' => 'Sociální pojištění hrazené zaměstnavatelem',
                'cost_center' => 'VYROBA',
            ],
            [
                'allocation_key' => 'employer-insurance:social:credit',
                'account_code' => '336',
                'signed_minor' => -33_800,
                'description' => 'Sociální pojištění hrazené zaměstnavatelem',
            ],
        ]);
        $this->batches->markNoChange($this->supplierId, $batchId);

        $statement = $this->db->pdo()->prepare(
            'SELECT run_id FROM payroll_posting_batches WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $batchId]);
        $runId = (int) $statement->fetchColumn();

        $previous = $this->batches->latestEffectiveBefore($this->supplierId, $runId, 2);
        self::assertNotNull($previous);
        self::assertSame([
            [
                'allocation_key' => 'employer-insurance:social:credit',
                'account_code' => '336',
                'signed_minor' => -33_800,
                'description' => 'Sociální pojištění hrazené zaměstnavatelem',
            ],
            [
                'allocation_key' => 'employer-insurance:social:employment:1:debit',
                'account_code' => '524',
                'signed_minor' => 33_800,
                'description' => 'Sociální pojištění hrazené zaměstnavatelem',
                'cost_center' => 'VYROBA',
            ],
        ], $previous['allocations']);
    }

    public function testPayrollJournalCannotBeRepostedAndRemainsUnchanged(): void
    {
        $entryId = $this->postPayroll();
        $before = $this->journal->find($entryId, $this->supplierId);
        self::assertNotNull($before);

        try {
            $this->posting->postDocument(
                $this->supplierId,
                'payroll',
                self::REVISION_ID,
                [
                    [
                        'account_code' => '521',
                        'side' => 'debit',
                        'amount' => '2000.00',
                    ],
                    [
                        'account_code' => '331',
                        'side' => 'credit',
                        'amount' => '2000.00',
                    ],
                ],
                [
                    'entry_date' => self::YEAR . '-06-30',
                    'document_no' => 'MZ-209706-R1-ZMENA',
                    'description' => 'Nepovolený přepis mzdového předpisu',
                    'posted_by' => $this->userId,
                    'user_id' => $this->userId,
                ],
            );
            self::fail('Zaúčtovaný mzdový předpis nesmí být přepsán.');
        } catch (PostingException $exception) {
            self::assertSame(
                'payroll_rewrite_forbidden',
                $exception->errorCode,
            );
        }

        $after = $this->journal->find($entryId, $this->supplierId);
        self::assertSame($before, $after);
        self::assertSame(1, $this->sourceEntryCount());
    }

    private function postPayroll(bool $prepareContext = true): int
    {
        $batchId = $prepareContext ? $this->preparePayrollContext() : null;
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'payroll',
            self::REVISION_ID,
            [
                [
                    'account_code' => '521',
                    'side' => 'debit',
                    'amount' => '1000.00',
                ],
                [
                    'account_code' => '331',
                    'side' => 'credit',
                    'amount' => '1000.00',
                ],
            ],
            [
                'entry_date' => self::YEAR . '-06-30',
                'document_no' => 'MZ-209706-R1',
                'description' => 'Mzdový předpis 06/2097 — revize 1',
                'posted_by' => $this->userId,
                'user_id' => $this->userId,
            ],
        );
        if ($batchId !== null) {
            $statement = $this->db->pdo()->prepare(
                'UPDATE payroll_posting_batches
                    SET status = "posted", journal_entry_id = ?, posted_at = NOW()
                  WHERE supplier_id = ? AND id = ? AND status = "prepared"',
            );
            $statement->execute([$entryId, $this->supplierId, $batchId]);
            self::assertSame(1, $statement->rowCount());
        }

        return $entryId;
    }

    private function preparePayrollContext(): int
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no,
                 created_by, updated_by)
             VALUES (?, ?, ?, "reviewed", 1, ?, ?)',
        );
        $statement->execute([
            $this->supplierId,
            self::YEAR . '-06-01',
            self::YEAR . '-07-15',
            $this->userId,
            $this->userId,
        ]);
        $runId = (int) $pdo->lastInsertId();
        $input = ['period_start' => self::YEAR . '-06-01'];
        $result = [];
        $inputJson = json_encode($input, JSON_THROW_ON_ERROR);
        $resultJson = json_encode($result, JSON_THROW_ON_ERROR);
        $statement = $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (id, supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_by,
                 approved_at)
             VALUES (?, ?, ?, 1, "approved", "payroll-run-input.v2", ?,
                     ?, ?, ?, ?, ?, ?, NOW())',
        );
        $statement->execute([
            self::REVISION_ID,
            $this->supplierId,
            $runId,
            hash('sha256', 'ruleset'),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            random_bytes(32),
            $this->userId,
        ]);
        $statement = $pdo->prepare(
            'INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, entry_date, status,
                 target_hash, delta_hash, created_by)
             VALUES (?, ?, ?, ?, "prepared", ?, ?, ?)',
        );
        $statement->execute([
            $this->supplierId,
            $runId,
            self::REVISION_ID,
            self::YEAR . '-06-30',
            hash('sha256', 'target'),
            hash('sha256', 'delta'),
            $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function sourceEntryCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM journal_entries
              WHERE supplier_id = ?
                AND source_type = "payroll"
                AND source_id = ?',
        );
        $statement->execute([$this->supplierId, self::REVISION_ID]);

        return (int) $statement->fetchColumn();
    }
}
