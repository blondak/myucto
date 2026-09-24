<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Cache;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Cache\SupplierWriteGenerations;
use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zmeškaný zápis znamená, že přehled firem ukáže zastaralý výsledek kontrol až do
 * vypršení TTL. Testy proto hlídají hlavně to, aby se zápis do dat nepropašoval.
 */
final class SupplierWriteGenerationsTest extends TestCase
{
    protected function setUp(): void
    {
        SupplierWriteGenerations::reset();
        SupplierWriteGenerations::bind(new RedisFactory(new Config([])));
    }

    protected function tearDown(): void
    {
        SupplierWriteGenerations::reset();
    }

    /** @return iterable<string,array{string,string|null}> */
    public static function statements(): iterable
    {
        yield 'insert'          => ['INSERT INTO journal_entries (id) VALUES (1)', 'journal_entries'];
        yield 'insert ignore'   => ['INSERT IGNORE INTO `payment_matches` (id) VALUES (1)', 'payment_matches'];
        yield 'replace'         => ["REPLACE INTO bank_transactions (id) VALUES (1)", 'bank_transactions'];
        yield 'update'          => ["  UPDATE purchase_invoices SET status = 'paid'", 'purchase_invoices'];
        yield 'update join'     => ['UPDATE invoices i JOIN clients c ON c.id = i.client_id SET i.x = 1', 'invoices'];
        yield 'delete'          => ['DELETE FROM invoice_settlements WHERE id = 1', 'invoice_settlements'];
        yield 'delete alias'    => ['DELETE l FROM journal_entry_lines l WHERE l.id = 1', 'journal_entry_lines'];
        yield 'truncate'        => ['TRUNCATE TABLE cash_documents', 'cash_documents'];
        yield 'select'          => ['SELECT * FROM journal_entries', null];
        yield 'with select'     => ['WITH x AS (SELECT 1) SELECT * FROM x', null];
        yield 'temporary table' => ['CREATE TEMPORARY TABLE t AS SELECT 1', null];
    }

    #[DataProvider('statements')]
    public function testWrittenTable(string $sql, ?string $table): void
    {
        self::assertSame($table, SupplierWriteGenerations::writtenTable($sql));
    }

    public function testWriteMarksRequestSupplier(): void
    {
        SupplierWriteGenerations::setRequestSupplier(42);
        SupplierWriteGenerations::noteStatement('UPDATE journal_entries SET description = ?');

        self::assertSame([42 => true], SupplierWriteGenerations::pending());
    }

    public function testWriteOutsideSupplierMarksGlobalGeneration(): void
    {
        SupplierWriteGenerations::noteStatement('INSERT INTO bank_transactions (id) VALUES (1)');

        self::assertSame([0 => true], SupplierWriteGenerations::pending());
    }

    public function testTechnicalTablesAndReadsDoNotInvalidate(): void
    {
        SupplierWriteGenerations::setRequestSupplier(42);
        foreach ([
            'UPDATE sessions SET last_activity = NOW()',
            'INSERT INTO rate_limit_counters (k) VALUES (1)',
            'INSERT INTO cron_runs (job) VALUES (1)',
            'UPDATE user_preferences SET v = 1',
            'SELECT * FROM journal_entries',
        ] as $sql) {
            SupplierWriteGenerations::noteStatement($sql);
        }

        self::assertSame([], SupplierWriteGenerations::pending());
    }

    public function testUnboundIsNoop(): void
    {
        SupplierWriteGenerations::reset();
        SupplierWriteGenerations::noteStatement('UPDATE journal_entries SET description = ?');

        self::assertSame([], SupplierWriteGenerations::pending());
    }

    public function testFlushWithoutRedisClearsPending(): void
    {
        SupplierWriteGenerations::setRequestSupplier(7);
        SupplierWriteGenerations::noteStatement('DELETE FROM payment_matches WHERE id = 1');
        SupplierWriteGenerations::flush();

        self::assertSame([], SupplierWriteGenerations::pending());
    }
}
