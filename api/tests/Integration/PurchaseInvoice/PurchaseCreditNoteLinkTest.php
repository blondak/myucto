<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vazba dobropisu (credit_note) na opravovanou přijatou fakturu (migrace 1096).
 *
 * Pokrývá:
 *   - find(): linked_parent brief + has_parent_candidates jen u dobropisu bez vazby
 *   - CreatePurchaseInvoiceAction::sanitizeParentLink: druh/self/tenant/druh-rodiče guard
 *   - updateDraft(): persistuje i vyčistí vazbu podle přítomnosti klíče
 *
 * Izolováno v roce 2098 pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseCreditNoteLinkTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container   = Bootstrap::buildApp()->getContainer();
            $this->db    = $container->get(Connection::class);
            $this->repo  = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        // Migrace 1096 musí být aplikovaná (jinak nemá smysl nic testovat).
        $hasCol = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'purchase_invoices'
                AND column_name = 'parent_purchase_invoice_id'"
        )->fetchColumn();
        if ((int) $hasCol === 0) {
            $this->markTestSkipped('Migrace 1096 (parent_purchase_invoice_id) není aplikovaná.');
        }

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        // FK parent_purchase_invoice_id je ON DELETE SET NULL → pořadí mazání nevadí.
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testFindExposesCandidatesAndLinkedParent(): void
    {
        $vendor = $this->vendor('DobropisDodavatel A', 'CZ20000001');
        $invoice = $this->purchase($vendor, 'invoice', 'FA-100', 'received', 12000.0, $this->d(10));
        $credit  = $this->purchase($vendor, 'credit_note', 'DOB-1', 'received', -3000.0, $this->d(15));

        // Dobropis bez vazby + existuje faktura téhož dodavatele → kandidát je k dispozici.
        $creditRow = $this->repo->find($credit, $this->supplierId);
        self::assertTrue($creditRow['has_parent_candidates'], 'existuje faktura téhož dodavatele k propojení');
        self::assertNull($creditRow['linked_parent']);

        // Faktura žádné kandidáty (parent link je jen pro dobropis).
        $invoiceRow = $this->repo->find($invoice, $this->supplierId);
        self::assertFalse($invoiceRow['has_parent_candidates']);

        // Nastav vazbu → find() vrátí linked_parent brief a kandidáty už nenabízí.
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$invoice, $credit]);
        $linkedRow = $this->repo->find($credit, $this->supplierId);
        self::assertSame($invoice, $linkedRow['parent_purchase_invoice_id']);
        self::assertNotNull($linkedRow['linked_parent']);
        self::assertSame($invoice, $linkedRow['linked_parent']['id']);
        self::assertSame('FA-100', $linkedRow['linked_parent']['vendor_invoice_number']);
        self::assertFalse($linkedRow['has_parent_candidates'], 'navázaný dobropis už kandidáty nenabízí');

        // Reverzní pohled: opravovaná faktura ví, který dobropis ji opravuje.
        $reverseRow = $this->repo->find($invoice, $this->supplierId);
        self::assertCount(1, $reverseRow['corrected_by']);
        self::assertSame($credit, $reverseRow['corrected_by'][0]['id']);
        self::assertSame('DOB-1', $reverseRow['corrected_by'][0]['vendor_invoice_number']);
        // Dobropis sám corrected_by nemá (počítá se jen pro nedobropisové doklady).
        self::assertSame([], $linkedRow['corrected_by']);
    }

    public function testSanitizeParentLinkGuards(): void
    {
        $vendor  = $this->vendor('DobropisDodavatel B', 'CZ20000002');
        $invoice = $this->purchase($vendor, 'invoice', 'FA-200', 'received', 5000.0, $this->d(10));
        $advance = $this->purchase($vendor, 'advance', 'ZAL-200', 'received', 1000.0, $this->d(9));
        $credit  = $this->purchase($vendor, 'credit_note', 'DOB-2', 'received', -2000.0, $this->d(12));

        // Platná faktura téhož tenanta u dobropisu → projde.
        self::assertSame($invoice, CreatePurchaseInvoiceAction::sanitizeParentLink(
            $this->db, (string) $invoice, 'credit_note', $this->supplierId, $credit,
        ));

        // Jiný druh dokladu (ne dobropis) vazbu vždy vyčistí.
        self::assertNull(CreatePurchaseInvoiceAction::sanitizeParentLink(
            $this->db, (string) $invoice, 'invoice', $this->supplierId, 0,
        ));

        // Vazba sama na sebe → null.
        self::assertNull(CreatePurchaseInvoiceAction::sanitizeParentLink(
            $this->db, (string) $credit, 'credit_note', $this->supplierId, $credit,
        ));

        // Rodič musí být běžná faktura — záloha (advance) neprojde.
        self::assertNull(CreatePurchaseInvoiceAction::sanitizeParentLink(
            $this->db, (string) $advance, 'credit_note', $this->supplierId, $credit,
        ));

        // Cizí tenant / neexistující id → null.
        self::assertNull(CreatePurchaseInvoiceAction::sanitizeParentLink(
            $this->db, (string) $invoice, 'credit_note', $this->supplierId + 999999, $credit,
        ));
        self::assertNull(CreatePurchaseInvoiceAction::sanitizeParentLink(
            $this->db, '0', 'credit_note', $this->supplierId, $credit,
        ));
        self::assertNull(CreatePurchaseInvoiceAction::sanitizeParentLink(
            $this->db, null, 'credit_note', $this->supplierId, $credit,
        ));
    }

    public function testUpdateDraftPersistsAndClearsParentLink(): void
    {
        $vendor  = $this->vendor('DobropisDodavatel C', 'CZ20000003');
        $invoice = $this->purchase($vendor, 'invoice', 'FA-300', 'received', 8000.0, $this->d(10));
        $credit  = $this->purchase($vendor, 'credit_note', 'DOB-3', 'draft', -2000.0, $this->d(12));

        $base = [
            'vendor_id'             => $vendor,
            'vendor_invoice_number' => 'DOB-3',
            'document_kind'         => 'credit_note',
            'issue_date'            => $this->d(12),
            'tax_date'              => $this->d(12),
            'due_date'              => $this->d(12),
            'currency_id'           => $this->currencyId,
        ];

        // Nastav vazbu.
        $this->repo->updateDraft($credit, $base + ['parent_purchase_invoice_id' => $invoice], $this->supplierId);
        self::assertSame($invoice, $this->repo->find($credit, $this->supplierId)['parent_purchase_invoice_id']);

        // Klíč přítomen, ale null → vazba se vyčistí.
        $this->repo->updateDraft($credit, $base + ['parent_purchase_invoice_id' => null], $this->supplierId);
        self::assertNull($this->repo->find($credit, $this->supplierId)['parent_purchase_invoice_id']);

        // Klíč vůbec neposlán → vazba (znovu nastavená) zůstane nedotčená.
        $this->repo->updateDraft($credit, $base + ['parent_purchase_invoice_id' => $invoice], $this->supplierId);
        $this->repo->updateDraft($credit, $base, $this->supplierId);
        self::assertSame($invoice, $this->repo->find($credit, $this->supplierId)['parent_purchase_invoice_id'],
            'update bez klíče parent_purchase_invoice_id nesmí vazbu shodit');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function d(int $day): string
    {
        return sprintf('%04d-06-%02d', self::YEAR, $day);
    }

    private function vendor(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    private function purchase(int $vendorId, string $kind, string $number, string $status, float $total, string $date): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, is_fixed_asset,
                 vat_deduction, vat_deduction_percent, tax_deductible, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, ?, 0, "full", 100, 1, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $kind, $date, $date, $date, $date,
            $this->currencyId, $total, $total, $status, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->piIds[] = $id;
        return $id;
    }
}
