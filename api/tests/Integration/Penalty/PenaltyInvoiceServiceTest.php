<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Penalty;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CnbRepoRateRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Penalty\PenaltyInvoiceService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Založení penalizační faktury z faktury po splatnosti + no-op když není po
 * splatnosti. Vše v transakci, rollback v tearDown.
 */
#[Group('integration')]
final class PenaltyInvoiceServiceTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $invoices;
    private PenaltyInvoiceService $service;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db       = $container->get(Connection::class);
            $this->invoices = $container->get(InvoiceRepository::class);
            $this->service  = $container->get(PenaltyInvoiceService::class);
            $rates          = $container->get(CnbRepoRateRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/0%-vat/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        // Repo sazba k 2025-01-01 pro deterministický výpočet (seed migrace 1048 ji má,
        // ale zajistíme ji i kdyby ji admin smazal).
        $rates->upsert('2025-01-01', 4.000, 'test');
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

    public function testPreviewComputesInterestAndCreateBuildsPenaltyInvoice(): void
    {
        $sourceId = $this->overdueInvoice('FV-OVERDUE-1', '2025-01-10', 100000.00);
        $source   = $this->invoices->find($sourceId);

        // 100000 × (4+8)% × 30 dní / 365 = 986,30
        $preview = $this->service->preview($source, '2025-02-09', 100000.00);
        self::assertSame(30, $preview['total_days']);
        self::assertSame(986.30, $preview['total_interest']);

        $penalty = $this->service->create($source, $this->userId, '2025-02-09', 100000.00);
        self::assertSame('penalty', $penalty['invoice_type']);
        self::assertSame($sourceId, (int) $penalty['parent_invoice_id']);
        self::assertEqualsWithDelta(986.30, (float) $penalty['total_with_vat'], 0.001, 'Penalizační faktura = vypočtený úrok.');
        self::assertEqualsWithDelta(0.0, (float) $penalty['total_vat'], 0.001, 'Penalizace bez DPH.');
        self::assertCount(1, $penalty['items']);
    }

    public function testNotOverdueIsNoop(): void
    {
        // Splatnost v budoucnu → k datu as_of není po splatnosti.
        $sourceId = $this->overdueInvoice('FV-FUTURE-1', '2025-06-30', 50000.00);
        $source   = $this->invoices->find($sourceId);

        $preview = $this->service->preview($source, '2025-02-09', 50000.00);
        self::assertSame(0.0, $preview['total_interest']);

        $this->expectException(\DomainException::class);
        $this->service->create($source, $this->userId, '2025-02-09', 50000.00);
    }

    public function testConsecutivePenaltiesDoNotOverlapDays(): void
    {
        // Regrese (audit Nález 5): druhá penalizace ke stejné zdrojové faktuře
        // MUSÍ počítat úrok jen za dny NEPOKRYTÉ dřívější penalizací — jinak by
        // se stejné dny prodlení vyúčtovaly dvakrát.
        $sourceId = $this->overdueInvoice('FV-OVERDUE-2', '2025-01-10', 100000.00);
        $source   = $this->invoices->find($sourceId);

        self::assertNull(
            $this->invoices->lastPenaltyCoveredThrough($sourceId),
            'Před první penalizací žádné dřívější pokrytí.',
        );

        // 1. penalizace: 11.1.–9.2.2025 = 30 dní, 100000×12%×30/365 = 986,30
        $penalty1 = $this->service->create($source, $this->userId, '2025-02-09', 100000.00);
        self::assertEqualsWithDelta(986.30, (float) $penalty1['total_with_vat'], 0.001);
        self::assertSame('2025-02-09', $this->invoices->lastPenaltyCoveredThrough($sourceId));

        // 2. penalizace o měsíc později: náhled MUSÍ hlásit pokrytí do 9.2. a počítat
        // jen od 10.2. — NE od původního počátku prodlení (11.1.), jinak by se leden
        // až únor penalizoval podruhé.
        $preview2 = $this->service->preview($source, '2025-03-11', 100000.00);
        self::assertSame('2025-02-09', $preview2['previously_covered_through']);
        self::assertSame(30, $preview2['total_days'], 'Jen 10.2.–11.3. (30 dní), NE 11.1.–11.3. (60 dní).');
        self::assertSame('2025-02-10', $preview2['segments'][0]['from']);
        self::assertEqualsWithDelta(986.30, $preview2['total_interest'], 0.001, 'Stejná sazba/basis/dny jako 1. penalizace → stejná částka pro DALŠÍCH 30 dní.');

        $penalty2 = $this->service->create($source, $this->userId, '2025-03-11', 100000.00);
        self::assertNotSame((int) $penalty1['id'], (int) $penalty2['id']);
        self::assertSame($sourceId, (int) $penalty2['parent_invoice_id']);
        self::assertEqualsWithDelta(986.30, (float) $penalty2['total_with_vat'], 0.001);
        self::assertSame('2025-03-11', $this->invoices->lastPenaltyCoveredThrough($sourceId));

        // Součet obou penalizací odpovídá přesně 60 dnům v jednom kuse (linearita
        // úroku při stejné sazbě/basis) — žádné dny nechybí ani nejsou navíc.
        self::assertEqualsWithDelta(
            1972.60,
            (float) $penalty1['total_with_vat'] + (float) $penalty2['total_with_vat'],
            0.001,
        );
    }

    public function testFullyCoveredPeriodRefusesNewPenalty(): void
    {
        // Regrese (audit Nález 5): pokus o penalizaci období, které už CELÉ pokrývá
        // dřívější penalizace (stejné nebo dřívější as_of), musí být odmítnut
        // srozumitelnou chybou, ne tiše vystavit fakturu na 0 Kč.
        $sourceId = $this->overdueInvoice('FV-OVERDUE-3', '2025-01-10', 100000.00);
        $source   = $this->invoices->find($sourceId);

        $this->service->create($source, $this->userId, '2025-02-09', 100000.00);

        $preview = $this->service->preview($source, '2025-02-09', 100000.00);
        self::assertSame(0.0, $preview['total_interest']);
        self::assertSame('2025-02-09', $preview['previously_covered_through']);

        $this->expectException(\DomainException::class);
        $this->service->create($source, $this->userId, '2025-02-09', 100000.00);
    }

    public function testPreviewUsesPartialPaymentTimelineAndImplicitFullPaymentDate(): void
    {
        $sourceId = $this->overdueInvoice('FV-PARTIAL-1', '2025-01-10', 100000.00);
        $this->payment($sourceId, '2025-01-20', 40000.00);
        $this->payment($sourceId, '2025-01-30', 60000.00);
        $source = $this->invoices->find($sourceId);

        $preview = $this->service->preview($source);

        self::assertSame('2025-01-30', $preview['as_of'], 'Implicitní datum končí skutečnou úplnou úhradou.');
        self::assertSame(20, $preview['total_days']);
        self::assertSame(526.03, $preview['total_interest']);
        self::assertCount(2, $preview['segments']);
        self::assertSame('2025-01-20', $preview['segments'][0]['to']);
        self::assertSame('2025-01-21', $preview['segments'][1]['from']);
    }

    public function testPrincipalOverridePreservesStaticPrincipalDespitePayments(): void
    {
        $sourceId = $this->overdueInvoice('FV-PARTIAL-2', '2025-01-10', 100000.00);
        $this->payment($sourceId, '2025-01-20', 40000.00);
        $this->payment($sourceId, '2025-01-30', 60000.00);
        $source = $this->invoices->find($sourceId);

        $preview = $this->service->preview($source, null, 50000.00);

        self::assertSame(50000.0, $preview['principal']);
        self::assertSame('2025-01-30', $preview['as_of']);
        self::assertSame(20, $preview['total_days']);
        self::assertSame(328.77, $preview['total_interest']);
        self::assertCount(1, $preview['segments']);
    }

    public function testPreviousPenaltyCoverageCombinesWithLaterPartialPayment(): void
    {
        $sourceId = $this->overdueInvoice('FV-PARTIAL-3', '2025-01-10', 100000.00);
        $this->payment($sourceId, '2025-01-20', 40000.00);
        $source = $this->invoices->find($sourceId);

        $this->service->create($source, $this->userId, '2025-01-15');
        $preview = $this->service->preview($source, '2025-01-30');

        self::assertSame('2025-01-15', $preview['previously_covered_through']);
        self::assertSame('2025-01-16', $preview['segments'][0]['from']);
        self::assertSame('2025-01-20', $preview['segments'][0]['to']);
        self::assertSame('2025-01-21', $preview['segments'][1]['from']);
        self::assertSame(15, $preview['total_days']);
        self::assertSame(361.64, $preview['total_interest']);
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function overdueInvoice(string $varsymbol, string $dueDate, float $total): int
    {
        $clientId = $this->client('Dlužník ' . $varsymbol);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, "issued", "3", ?)'
        );
        $issue = '2025-01-01';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $dueDate, $this->currencyId, $total, $total, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Plnění', 1, 'ks', ?, ?, 0, ?, 0, ?, 0)"
        );
        $stmt->execute([$id, $total, $this->vatRateId, $total, $total]);
        return $id;
    }

    private function payment(int $invoiceId, string $paidOn, float $amount): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, created_by)
             VALUES (?, ?, ?, ?, "CZK", "manual", ?)'
        );
        $stmt->execute([$this->supplierId, $invoiceId, $paidOn, $amount, $this->userId]);
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET paid_total = (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = ?),
                    paid_at = ?
              WHERE id = ?'
        )->execute([$invoiceId, $paidOn, $invoiceId]);
    }
}
