<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FrequentExpenseAccountRedStornoTest extends TestCase
{
    private PDO $pdo;
    private ClosingService $closing;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, account_code TEXT)');
        $this->pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, vendor_id INTEGER)');
        $this->pdo->exec('CREATE TABLE journal_entries (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            entry_date TEXT,
            source_type TEXT,
            source_id INTEGER,
            posted_at TEXT,
            reversed_by INTEGER
        )');
        $this->pdo->exec('CREATE TABLE journal_entry_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER,
            entry_id INTEGER,
            account_id INTEGER,
            side TEXT,
            amount REAL,
            is_red_storno INTEGER NOT NULL DEFAULT 0,
            signed_amount REAL GENERATED ALWAYS AS (
                CASE WHEN is_red_storno = 1 THEN -amount ELSE amount END
            ) VIRTUAL
        )');

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $db = new Connection($config);
        (new ReflectionClass($db))->getProperty('pdo')->setValue($db, $this->pdo);

        $this->closing = (new ReflectionClass(ClosingService::class))->newInstanceWithoutConstructor();
        (new ReflectionClass($this->closing))->getProperty('db')->setValue($this->closing, $db);

        $this->pdo->exec("INSERT INTO chart_of_accounts VALUES (1,'518'),(2,'501')");
    }

    public function testPartialRedStornoOnVendorInvoiceStillCountsAsOneAccountUse(): void
    {
        $this->purchaseEntry(1, 10, 77, [
            [1, 100.0, false],
            [1, 20.0, true],
        ]);
        $this->purchaseEntry(2, 11, 77, [
            [2, 50.0, false],
        ]);

        self::assertSame('518', $this->frequentExpenseAccount(1, 77, '2025-12-31'));
    }

    public function testOrdinarySplitLinesKeepLegacyRowFrequencyBeforeAmountTieBreaker(): void
    {
        $this->purchaseEntry(1, 10, 77, [
            [1, 10.0, false],
            [1, 10.0, false],
            [1, 10.0, false],
        ]);
        $this->purchaseEntry(2, 11, 77, [[2, 1000.0, false]]);
        $this->purchaseEntry(3, 12, 77, [[2, 1000.0, false]]);

        self::assertSame('518', $this->frequentExpenseAccount(1, 77, '2025-12-31'));
    }

    /** @param list<array{0:int,1:float,2:bool}> $lines */
    private function purchaseEntry(int $entryId, int $invoiceId, int $vendorId, array $lines): void
    {
        $this->pdo->prepare('INSERT INTO purchase_invoices VALUES (?,1,?)')->execute([$invoiceId, $vendorId]);
        $this->pdo->prepare(
            "INSERT INTO journal_entries VALUES (?,1,'2025-06-30','purchase_invoice',?,'2025-06-30',NULL)"
        )->execute([$entryId, $invoiceId]);

        $stmt = $this->pdo->prepare(
            "INSERT INTO journal_entry_lines
                (supplier_id, entry_id, account_id, side, amount, is_red_storno)
             VALUES (1,?,?,'debit',?,?)"
        );
        foreach ($lines as [$accountId, $amount, $redStorno]) {
            $stmt->execute([$entryId, $accountId, $amount, $redStorno ? 1 : 0]);
        }
    }

    private function frequentExpenseAccount(int $supplierId, int $vendorId, string $asOf): ?string
    {
        return (new ReflectionClass($this->closing))
            ->getMethod('frequentExpenseAccount')
            ->invoke($this->closing, $supplierId, $vendorId, $asOf);
    }
}
