<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uzávěrkový precheck „nezaúčtované doklady" (§3.4) nesmí hlásit trvalé
 * false-positives (audit 2026-07, A5): proforma a interní storno nemají vlastní
 * účetní předpis (proforma se účtuje až vyúčtovacím dokladem, cancellation je
 * jen protizápis), zálohová přijatá faktura (document_kind='advance') se
 * neúčtuje, dokud nedojde k vyúčtování. Skutečně nezaúčtovaná běžná faktura se
 * naopak hlásit MUSÍ.
 *
 * EP-10a: za zaúčtovaný smí precheck brát jen AKTIVNÍ zápis (posted_at IS NOT
 * NULL AND reversed_by IS NULL) — draft (posted_at NULL) ani reverzovaný zápis
 * warning nesmí potlačit.
 *
 * Izolovaný supplier + jedna transakce s rollbackem v tearDown (vzor
 * ClosingWorkflowTest). Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class PrecheckUnpostedFalsePositiveTest extends TestCase
{
    private const YEAR = 2097;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';
    private const TAX_DATE = self::YEAR . '-06-15';

    private Connection $db;
    private ClosingRepository $closing;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->closing = $container->get(ClosingRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId        = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $this->userId === 0 || $this->czId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/user/country/vat_rate) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->createSupplier('A5 precheck test s.r.o.', 'a5-precheck@example.com', $vatRateId);
        $this->periodId   = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
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

    public function testUnpostedInvoicesExcludesProformaAndCancellation(): void
    {
        $clientId = $this->client();
        $realId       = $this->issuedInvoice('VS-2097-001', 'invoice', $clientId);
        $this->issuedInvoice('VS-2097-002', 'proforma', $clientId);
        $this->issuedInvoice('VS-2097-003', 'cancellation', $clientId);

        $ids = array_map(static fn (array $r): int => $r['id'], $this->closing->unpostedInvoices($this->supplierId, self::STARTS_ON, self::ENDS_ON));

        self::assertSame([$realId], $ids, 'Precheck hlásí jen skutečně nezaúčtovanou běžnou fakturu, ne proformu ani storno.');
    }

    public function testUnpostedPurchasesExcludesAdvance(): void
    {
        $vendorId = $this->client();
        $realId   = $this->purchaseInvoice('DODAV-2097-001', 'invoice', $vendorId);
        $this->purchaseInvoice('DODAV-2097-002', 'advance', $vendorId);

        $ids = array_map(static fn (array $r): int => $r['id'], $this->closing->unpostedPurchases($this->supplierId, self::STARTS_ON, self::ENDS_ON));

        self::assertSame([$realId], $ids, 'Precheck hlásí jen běžnou přijatou fakturu, ne zálohovou (document_kind=advance).');
    }

    public function testUnpostedInvoicesReportsDraftAndReversedButNotLive(): void
    {
        $clientId   = $this->client();
        $draftId    = $this->issuedInvoice('VS-2097-011', 'invoice', $clientId);
        $reversedId = $this->issuedInvoice('VS-2097-012', 'invoice', $clientId);
        $liveId     = $this->issuedInvoice('VS-2097-013', 'invoice', $clientId);

        $this->journalEntry('invoice', $draftId, null, null);
        $stornoId = $this->journalEntry('manual', null, self::TAX_DATE . ' 10:00:00', null);
        $this->journalEntry('invoice', $reversedId, self::TAX_DATE . ' 10:00:00', $stornoId);
        $this->journalEntry('invoice', $liveId, self::TAX_DATE . ' 10:00:00', null);

        $ids = array_map(static fn (array $r): int => $r['id'], $this->closing->unpostedInvoices($this->supplierId, self::STARTS_ON, self::ENDS_ON));

        self::assertContains($draftId, $ids, 'Doklad jen s draft zápisem (posted_at NULL) je nezaúčtovaný.');
        self::assertContains($reversedId, $ids, 'Doklad jen s reverzovaným zápisem (reversed_by NOT NULL) je nezaúčtovaný.');
        self::assertNotContains($liveId, $ids, 'Doklad se živým posted zápisem se nehlásí.');
    }

    public function testUnpostedPurchasesReportsDraftAndReversedButNotLive(): void
    {
        $vendorId   = $this->client();
        $draftId    = $this->purchaseInvoice('DODAV-2097-011', 'invoice', $vendorId);
        $reversedId = $this->purchaseInvoice('DODAV-2097-012', 'invoice', $vendorId);
        $liveId     = $this->purchaseInvoice('DODAV-2097-013', 'invoice', $vendorId);

        $this->journalEntry('purchase_invoice', $draftId, null, null);
        $stornoId = $this->journalEntry('manual', null, self::TAX_DATE . ' 10:00:00', null);
        $this->journalEntry('purchase_invoice', $reversedId, self::TAX_DATE . ' 10:00:00', $stornoId);
        $this->journalEntry('purchase_invoice', $liveId, self::TAX_DATE . ' 10:00:00', null);

        $ids = array_map(static fn (array $r): int => $r['id'], $this->closing->unpostedPurchases($this->supplierId, self::STARTS_ON, self::ENDS_ON));

        self::assertContains($draftId, $ids, 'Přijatý doklad jen s draft zápisem (posted_at NULL) je nezaúčtovaný.');
        self::assertContains($reversedId, $ids, 'Přijatý doklad jen s reverzovaným zápisem (reversed_by NOT NULL) je nezaúčtovaný.');
        self::assertNotContains($liveId, $ids, 'Přijatý doklad se živým posted zápisem se nehlásí.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function journalEntry(string $sourceType, ?int $sourceId, ?string $postedAt, ?int $reversedBy): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, source_type, source_id, posted_at, posted_by, reversed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $this->periodId, self::TAX_DATE,
            $sourceType, $sourceId, $postedAt, $postedAt === null ? null : $this->userId, $reversedBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function issuedInvoice(string $varsymbol, string $type, int $clientId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1000, 210, 1210, "issued", "1", ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $type, $clientId,
            self::TAX_DATE, self::TAX_DATE, self::TAX_DATE, $this->currencyId, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseInvoice(string $number, string $kind, int $vendorId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", 1000, 210, 1210, "received", "1", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $kind,
            self::TAX_DATE, self::TAX_DATE, self::TAX_DATE, self::TAX_DATE, $this->currencyId, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createSupplier(string $name, string $email, int $vatRateId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $this->czId, $email, $this->currencyId, $vatRateId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function client(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Protistrana s.r.o.", "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
