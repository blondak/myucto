<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\InvoiceExportDataResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Epic VH-08 (Plátcovství DPH v čase) — exporty legacy dokladů BEZ supplier
 * snapshotu (nebo se snapshotem bez pole is_vat_payer): resolver nesmí převzít
 * živou cache supplier.is_vat_payer, ale dohledá plátcovství k datu dokladu
 * (tax_date ?? issue_date) ze supplier_vat_status_history.
 *
 * Přes {@see InvoiceExportDataResolver} to kryje StereoXmlExporter (element
 * VATPayer), IsdocExporter (VATApplicable) i ostatní exportéry sdílející resolver.
 */
#[Group('integration')]
final class InvoiceExportVatStatusFallbackTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private InvoiceExportDataResolver $resolver;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db  = $container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->resolver = new InvoiceExportDataResolver($this->db);
        $this->supplierId = $this->createIsolatedSupplier($this->db->pdo(), 1);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        if ($this->supplierId > 0) {
            // supplier_vat_status_history má ON DELETE CASCADE.
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
        $this->db->close();
    }

    public function testLegacyInvoiceWithoutSnapshotUsesStatusAtDocumentDate(): void
    {
        $pdo = $this->db->pdo();
        // Živě plátce (dnes), ale k datu dokladu (2099) už neplátce.
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $this->supplierId, '2099-01-01', false);
        self::assertSame(
            1,
            (int) $pdo->query('SELECT is_vat_payer FROM supplier WHERE id = ' . $this->supplierId)->fetchColumn(),
        );

        $supplier = $this->resolver->supplier([
            'supplier_id'       => $this->supplierId,
            'supplier_snapshot' => null,
            'issue_date'        => '2099-06-01',
        ]);
        self::assertFalse(
            (bool) $supplier['is_vat_payer'],
            'Legacy doklad bez snapshotu → plátcovství k datu dokladu, ne živá cache.',
        );

        // Zrcadlově: doklad z období, kdy firma plátcem BYLA.
        $supplier = $this->resolver->supplier([
            'supplier_id'       => $this->supplierId,
            'supplier_snapshot' => null,
            'issue_date'        => '2098-06-01',
        ]);
        self::assertTrue((bool) $supplier['is_vat_payer']);
    }

    public function testTaxDateTakesPrecedenceOverIssueDate(): void
    {
        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $this->supplierId, '2099-01-01', false);

        $supplier = $this->resolver->supplier([
            'supplier_id'       => $this->supplierId,
            'supplier_snapshot' => null,
            'issue_date'        => '2099-06-01',
            'tax_date'          => '2098-12-15',
        ]);
        self::assertTrue(
            (bool) $supplier['is_vat_payer'],
            'DUZP (tax_date) v období plátcovství má přednost před issue_date.',
        );
    }

    public function testSnapshotWithFlagStillWins(): void
    {
        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $this->supplierId, '2099-01-01', false);

        // Snapshot nese is_vat_payer → historie se NEpoužije (per-doklad pravda).
        $supplier = $this->resolver->supplier([
            'supplier_id'       => $this->supplierId,
            'supplier_snapshot' => json_encode(['company_name' => 'Snap s.r.o.', 'is_vat_payer' => true]),
            'issue_date'        => '2099-06-01',
        ]);
        self::assertTrue((bool) $supplier['is_vat_payer'], 'Snapshot s flagem má vždy přednost.');

        // Snapshot BEZ pole is_vat_payer → fallback na historii k datu dokladu.
        $supplier = $this->resolver->supplier([
            'supplier_id'       => $this->supplierId,
            'supplier_snapshot' => json_encode(['company_name' => 'Snap s.r.o.']),
            'issue_date'        => '2099-06-01',
        ]);
        self::assertFalse(
            (bool) $supplier['is_vat_payer'],
            'Snapshot bez pole is_vat_payer → dohledat k datu dokladu, ne živý flag.',
        );
    }
}
