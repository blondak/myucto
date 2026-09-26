<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\SecuritiesSaleCostLimit;
use PDO;
use PHPUnit\Framework\TestCase;

final class SecuritiesSaleCostLimitRedStornoTest extends TestCase
{
    private PDO $pdo;
    private SecuritiesSaleCostLimit $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("CREATE TABLE journal_entries (
                id INTEGER PRIMARY KEY, supplier_id INTEGER, source_type TEXT, source_id INTEGER,
                reversed_by INTEGER, entry_date TEXT, posted_at TEXT
            );
            CREATE TABLE journal_entry_lines (
                entry_id INTEGER, supplier_id INTEGER, account_id INTEGER, side TEXT, amount REAL,
                is_red_storno INTEGER NOT NULL DEFAULT 0,
                signed_amount REAL GENERATED ALWAYS AS (
                    CASE WHEN is_red_storno = 1 THEN -amount ELSE amount END
                ) VIRTUAL
            );
            CREATE TABLE chart_of_accounts (
                id INTEGER PRIMARY KEY, account_code TEXT, name TEXT,
                account_type TEXT, tax_deductibility TEXT
            );
            CREATE TABLE purchase_invoices (
                id INTEGER PRIMARY KEY, supplier_id INTEGER, tax_deductible INTEGER
            );
            INSERT INTO chart_of_accounts VALUES
                (1, '561P', 'Prodané podíly', 'expense', 'deductible'),
                (2, '661', 'Tržby z prodeje podílů', 'revenue', 'deductible');");

        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $this->pdo);
        $this->service = new SecuritiesSaleCostLimit($db);
    }

    public function testOrdinaryEntriesKeepExistingCostLimit(): void
    {
        $this->entry(1, 1, 'debit', 100.0);
        $this->entry(2, 2, 'credit', 70.0);

        $result = $this->service->forPeriod(1, '2025-01-01', '2025-12-31');

        self::assertSame(100.0, $result['shares_cost']);
        self::assertSame(70.0, $result['income']);
        self::assertSame(30.0, $result['addback']);
    }

    public function testRedStornoReducesShareCostBeforeApplyingLimit(): void
    {
        $this->entry(1, 1, 'debit', 100.0);
        $this->entry(2, 1, 'debit', 20.0, true);
        $this->entry(3, 2, 'credit', 70.0);

        $result = $this->service->forPeriod(1, '2025-01-01', '2025-12-31');

        self::assertSame(80.0, $result['shares_cost']);
        self::assertSame(70.0, $result['income']);
        self::assertSame(10.0, $result['addback']);
    }

    private function entry(int $id, int $accountId, string $side, float $amount, bool $red = false): void
    {
        $this->pdo->prepare(
            "INSERT INTO journal_entries VALUES (?, 1, 'manual', NULL, NULL, '2025-06-30', '2025-06-30')"
        )->execute([$id]);
        $this->pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, is_red_storno)
             VALUES (?, 1, ?, ?, ?, ?)'
        )->execute([$id, $accountId, $side, $amount, $red ? 1 : 0]);
    }
}
