<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatCrossCheckService;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class VatRedStornoCreditNoteConsistencyTest extends TestCase
{
    private const YEAR = 2049;
    private const MONTH = 6;
    private const DATE = self::YEAR . '-06-15';

    private Connection $db;
    private PostingService $posting;
    private VatLedgerService $ledger;
    private DphPriznaniBuilder $dph;
    private KontrolniHlaseniBuilder $kh;
    private VatCrossCheckService $crossCheck;
    private ClosingRepository $closing;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->ledger = $container->get(VatLedgerService::class);
        $this->dph = $container->get(DphPriznaniBuilder::class);
        $this->kh = $container->get(KontrolniHlaseniBuilder::class);
        $this->crossCheck = $container->get(VatCrossCheckService::class);
        $this->closing = $container->get(ClosingRepository::class);
        $periods = $container->get(AccountingPeriodRepository::class);
        $seeder = $container->get(ChartOfAccountsSeeder::class);

        $pdo = $this->db->pdo();
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        self::assertGreaterThan(0, $this->currencyId, 'Testovací DB musí obsahovat měnu CZK.');
        self::assertGreaterThan(0, $this->vatRateId, 'Testovací DB musí obsahovat sazbu DPH 21 %.');
        self::assertGreaterThan(0, $this->userId, 'Testovací DB musí obsahovat uživatele.');
        self::assertGreaterThan(0, $this->czId, 'Testovací DB musí obsahovat Česko.');

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, ic, dic, email,
                 default_currency_id, default_vat_rate_id, is_vat_payer, is_identified,
                 vat_period, taxpayer_type, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "12345679", "CZ12345679", ?,
                     ?, ?, 1, 0, "monthly", "po", "double_entry")'
        );
        $stmt->execute([
            'DPH red-storno test s.r.o.',
            $this->czId,
            'vat-red-storno-' . bin2hex(random_bytes(4)) . '@example.com',
            $this->currencyId,
            $this->vatRateId,
        ]);
        $this->supplierId = (int) $pdo->lastInsertId();
        self::assertGreaterThan(0, $this->supplierId);

        $pdo->prepare(
            'INSERT INTO supplier_vat_status_history
                (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, ?, 1, 0)'
        )->execute([$this->supplierId, self::YEAR . '-01-01']);

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create(
            $this->supplierId,
            self::YEAR,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPurchaseCreditNoteRedStornoIsConsistentAcrossVatJournalAndProfitAndLoss(): void
    {
        $vendorId = $this->vendor();
        $purchaseId = $this->creditNote($vendorId);

        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00, 'is_red_storno' => true],
                ['account_code' => '343', 'side' => 'debit', 'amount' => 21.00, 'is_red_storno' => true],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 121.00, 'is_red_storno' => true],
            ],
            [
                'entry_date' => self::DATE,
                'document_date' => self::DATE,
                'document_no' => 'ODD-2049-001',
                'description' => 'Přijatý opravný daňový doklad',
                'posted_by' => $this->userId,
            ],
        );

        $journalLines = $this->journalLines($entryId);
        self::assertSame([321, 343, 518], array_keys($journalLines));
        $this->assertRedLine($journalLines['518'], 'debit', 100.00, -100.00);
        $this->assertRedLine($journalLines['343'], 'debit', 21.00, -21.00);
        $this->assertRedLine($journalLines['321'], 'credit', 121.00, -121.00);

        $account343Net = $journalLines['343']['side'] === 'credit'
            ? $journalLines['343']['signed_amount']
            : -$journalLines['343']['signed_amount'];
        self::assertEqualsWithDelta(21.00, $account343Net, 0.001, 'Obrat 343 je D minus MD, tedy +21 Kč.');

        $plByAccount = [];
        foreach ($this->closing->plBalances(
            $this->supplierId,
            $this->periodId,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
        ) as $row) {
            $plByAccount[$row['account_code']] = $row['bal'];
        }
        self::assertSame([518], array_keys($plByAccount));
        self::assertEqualsWithDelta(-100.00, $plByAccount['518'], 0.001, 'Dobropis snižuje náklad na 518 o 100 Kč.');

        $ledgerRows = array_values(array_filter(
            $this->ledger->rows($this->supplierId, self::YEAR . '-06-01', self::YEAR . '-06-30'),
            static fn (array $row): bool => $row['source'] === 'purchase'
                && $row['invoice_id'] === $purchaseId,
        ));
        self::assertCount(1, $ledgerRows);
        self::assertSame('credit_note', $ledgerRows[0]['document_kind']);
        self::assertSame('40', $ledgerRows[0]['code']);
        self::assertEqualsWithDelta(-100.00, $ledgerRows[0]['base_czk'], 0.001);
        self::assertEqualsWithDelta(-21.00, $ledgerRows[0]['vat_czk'], 0.001);

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(-100.00, $dph['summary']['lines']['40']['base'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(-21.00, $dph['summary']['lines']['40']['vat'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(21.00, $dph['summary']['tax_due'], 0.001, 'Snížení odpočtu zvyšuje daňovou povinnost o 21 Kč.');

        $kh = $this->kh->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertSame(1, $kh['summary']['b3_count_aggregated']);
        $khXml = new \SimpleXMLElement($kh['xml']);
        self::assertSame('-100.00', (string) $khXml->DPHKH1->VetaB3['zakl_dane1']);
        self::assertSame('-21.00', (string) $khXml->DPHKH1->VetaB3['dan1']);

        $sections = array_values(array_filter(
            $this->kh->invoiceSections($this->supplierId, self::YEAR, self::MONTH),
            static fn (array $row): bool => $row['source'] === 'purchase'
                && $row['invoice_id'] === $purchaseId,
        ));
        self::assertCount(1, $sections);
        self::assertSame('B.3', $sections[0]['section']);
        self::assertEqualsWithDelta(-100.00, $sections[0]['base_total'], 0.001);

        self::assertSame(
            [],
            $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly'),
            'Dokladová evidence DPH, KH a podepsaný obrat účtu 343 musí být ve shodě.',
        );
    }

    private function vendor(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický dodavatel s.r.o.", "Testovací 2", "Brno", "60200", ?,
                     "CZ87654321", "vendor@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function creditNote(int $vendorId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, "ODD-2049-001", "credit_note", ?, ?, ?, ?, ?, 0, "{}",
                     -100.00, -21.00, -121.00, "received", "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId,
            $vendorId,
            self::DATE,
            self::DATE,
            self::DATE,
            self::DATE,
            $this->currencyId,
            $this->userId,
        ]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, vat_classification_code,
                 total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Vrácená služba", 1, "ks", -100.00, ?, 21.00, "40",
                     -100.00, -21.00, -121.00, 0)'
        )->execute([$purchaseId, $this->vatRateId]);

        return $purchaseId;
    }

    /** @return array<string,array{side:string,amount:float,is_red_storno:bool,signed_amount:float}> */
    private function journalLines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount, l.is_red_storno, l.signed_amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?
              ORDER BY a.account_code'
        );
        $stmt->execute([$entryId, $this->supplierId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['account_code']] = [
                'side' => (string) $row['side'],
                'amount' => (float) $row['amount'],
                'is_red_storno' => (bool) $row['is_red_storno'],
                'signed_amount' => (float) $row['signed_amount'],
            ];
        }
        return $result;
    }

    /** @param array{side:string,amount:float,is_red_storno:bool,signed_amount:float} $line */
    private function assertRedLine(array $line, string $side, float $amount, float $signedAmount): void
    {
        self::assertSame($side, $line['side']);
        self::assertEqualsWithDelta($amount, $line['amount'], 0.001);
        self::assertTrue($line['is_red_storno']);
        self::assertEqualsWithDelta($signedAmount, $line['signed_amount'], 0.001);
    }
}
