<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Currency;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 1304 — korunový doklad nesmí nést kurz.
 *
 * Kurz na CZK dokladu žádné číslo nemění (PostingService i VatLedgerService u koruny
 * počítají s 1.0 natvrdo), ale je to past pro každou agregaci bez pojistky na CZK
 * (přesně ten tvar hlídá {@see \MyInvoice\Tests\Architecture\ExchangeRateGuardTest}).
 *
 * Testuje se úklid OBOU tabulek a hlavně idempotence: migrace nemá marker, opakovaná
 * spustitelnost stojí čistě na WHERE.
 *
 * Vše v transakci s rollbackem. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class CzkRateCleanupMigrationTest extends TestCase
{
    private Connection $db;
    private int $supplierId = 0;
    private int $czkId = 0;
    private int $eurId = 0;
    private int $clientId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $base = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($base === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací 1", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        )->execute(['CZK kurz uklid s.r.o.', 'czk-cleanup@example.com', $base]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $cur = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1)'
        );
        $cur->execute([$this->supplierId, 'CZK', 'CZK', 'Kč', 'koruna', 'koruna']);
        $this->czkId = (int) $pdo->lastInsertId();
        $cur->execute([$this->supplierId, 'EUR', 'EUR', '€', 'euro', 'euro']);
        $this->eurId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "CZK protistrana s.r.o.", "Test 1", "Praha", "11000", ?, "c@example.com", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->czkId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    public function testClearsCzkRatesOnBothTablesAndIsIdempotent(): void
    {
        $czkPurchase = $this->purchase($this->czkId, 1.0, '2097-02-10');
        $eurPurchase = $this->purchase($this->eurId, 25.5, '2097-02-11');
        $czkInvoice  = $this->invoice($this->czkId, 1.0, '2097-02-12');
        $eurInvoice  = $this->invoice($this->eurId, 25.5, '2097-02-13');

        $this->runMigration();

        self::assertNull($this->purchaseRate($czkPurchase)['exchange_rate']);
        self::assertNull($this->purchaseRate($czkPurchase)['exchange_rate_date']);
        self::assertNull($this->invoiceRate($czkInvoice)['exchange_rate']);
        self::assertNull($this->invoiceRate($czkInvoice)['exchange_rate_date']);

        self::assertEqualsWithDelta(25.5, (float) $this->purchaseRate($eurPurchase)['exchange_rate'], 1e-6,
            'Cizoměnový doklad se migrací nesmí dotknout.');
        self::assertEqualsWithDelta(25.5, (float) $this->invoiceRate($eurInvoice)['exchange_rate'], 1e-6);

        // Druhý běh nesmí spadnout ani nic dalšího změnit — idempotence stojí na WHERE.
        $this->runMigration();

        self::assertNull($this->purchaseRate($czkPurchase)['exchange_rate']);
        self::assertNull($this->invoiceRate($czkInvoice)['exchange_rate']);
        self::assertEqualsWithDelta(25.5, (float) $this->purchaseRate($eurPurchase)['exchange_rate'], 1e-6);
        self::assertEqualsWithDelta(25.5, (float) $this->invoiceRate($eurInvoice)['exchange_rate'], 1e-6);
    }

    private function runMigration(): void
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/1304_czk_doklady_bez_kurzu.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        // Komentáře pryč, ať se `;` uvnitř textu nepletou do rozdělení příkazů.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->db->pdo()->exec($statement);
        }
    }

    private function purchase(int $currencyId, float $rate, string $date): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, exchange_rate_date,
                 exchange_rate_source, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, "import", 0, "{}", 100, 0, 100, "received", "full", ?)'
        )->execute([
            $this->supplierId, $this->clientId, 'CZKCLEAN-' . bin2hex(random_bytes(3)),
            $date, $date, $date, $date, $currencyId, $rate, $date, $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(int $currencyId, float $rate, string $date): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, exchange_rate_date, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, 0, 100, 0, 100, "issued", ?)'
        )->execute([
            $this->supplierId, (string) random_int(2000000000, 2099999999), $this->clientId,
            $date, $date, $date, $currencyId, $rate, $date, $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function purchaseRate(int $id): array
    {
        return $this->fetchRate('purchase_invoices', $id);
    }

    /** @return array<string,mixed> */
    private function invoiceRate(int $id): array
    {
        return $this->fetchRate('invoices', $id);
    }

    /** @return array<string,mixed> */
    private function fetchRate(string $table, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT exchange_rate, exchange_rate_date FROM {$table} WHERE id = ?"
        );
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
