<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\VatCoefficientRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\VatCoefficientSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Koeficient § 76 převedených let ze sdílené vrstvy převodů: izolovaná firma v transakci
 * s rollbackem.
 */
#[Group('integration')]
final class SharedVatCoefficientSeederTest extends TestCase
{
    private Connection $db;
    private VatCoefficientSeeder $seeder;
    private VatCoefficientRepository $coefficients;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->seeder = $container->get(VatCoefficientSeeder::class);
            $this->coefficients = $container->get(VatCoefficientRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, is_vat_payer)
             VALUES ("Syntetická firma koeficient", "Účetní 1", "Brno", "60200", ?, "koeficient@example.invalid", "00000019", ?, ?, 1)'
        )->execute([$czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
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

    public function testFirmWithoutReducedDeductionGetsNoCoefficient(): void
    {
        $p = new ImportProtocol('import');
        $this->seeder->seed($this->supplierId, [2091, 2090], $this->userId, $p);

        self::assertNull($this->coefficients->get($this->supplierId, 2090));
        self::assertSame([], $p->toArray()['steps'] ?? []);
    }

    public function testReducedDeductionSettlesClosedYearsAndSeedsFirstProvisional(): void
    {
        $vendor = $this->vendor();
        $this->reducedPurchase($vendor, '2090-03-01');
        $p = new ImportProtocol('import');

        // Pořadí let na vstupu nerozhoduje: vypořádá se všechno kromě posledního roku.
        $this->seeder->seed($this->supplierId, [2091, 2090], $this->userId, $p);

        $first = $this->coefficients->get($this->supplierId, 2090);
        self::assertNotNull($first);
        self::assertNotNull($first['provisional_percent']);
        self::assertNotNull($first['settled_at']);
        self::assertNull($this->coefficients->get($this->supplierId, 2091)['settled_at'] ?? null, 'Poslední rok zůstává otevřený');
        $protocol = json_encode($p->toArray(), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('provisional_from_own_year', (string) $protocol);
        self::assertStringContainsString('Koeficient § 76 za rok 2090', (string) $protocol);

        // Opakovaný převod vypořádaný rok nepřepisuje.
        $again = new ImportProtocol('import');
        $this->seeder->seed($this->supplierId, [2090, 2091], $this->userId, $again);
        self::assertSame([], $again->toArray()['steps']);
    }

    private function vendor(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_customer, is_vendor)
             SELECT ?, "Syntetický dodavatel", "-", "-", "-", country_id, default_currency_id, 0, 1 FROM supplier WHERE id = ?'
        )->execute([$this->supplierId, $this->supplierId]);
        return (int) $pdo->lastInsertId();
    }

    private function reducedPurchase(int $vendorId, string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             SELECT ?, ?, "SYN-1", "invoice", ?, ?, ?, ?, default_currency_id, 1, 0, "{}", 1000, 210, 1210, "received", "40", "reduced", ?
               FROM supplier WHERE id = ?'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $this->userId, $this->supplierId]);
    }
}
