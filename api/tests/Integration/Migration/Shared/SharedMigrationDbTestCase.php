<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Základ testů sdílené vrstvy převodů nad skutečnou DB: izolovaná syntetická firma
 * a transakce s rollbackem.
 */
abstract class SharedMigrationDbTestCase extends TestCase
{
    protected Connection $db;
    protected ContainerInterface $container;
    protected int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 5) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->userId = (int) ($this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->db->pdo()->beginTransaction();
        $this->inTx = true;
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

    protected function supplier(string $ico = '00000019', string $name = 'Sdílená vrstva s.r.o.'): int
    {
        $pdo = $this->db->pdo();
        $cz = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currency = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRate = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($cz === 0 || $currency === 0 || $vatRate === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 1", "Brno", "60200", ?, "shared@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence")'
        )->execute([$name, $cz, $ico, 'CZ' . $ico, $currency, $vatRate]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    protected function period(int $supplierId, int $year): int
    {
        $this->db->pdo()->prepare('INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $year, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function entry(int $supplierId, int $periodId, string $sourceType, ?int $sourceId = null, string $date = '2025-03-01'): int
    {
        $this->db->pdo()->prepare('INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $periodId, $date, 'Zápis', $sourceType, $sourceId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    protected function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    protected function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
