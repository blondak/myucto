<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * CTE daňového původu nese jen řetězce storen a ostatní zápisy se připojují přes LEFT JOIN.
 * Referencí je dřívější tvar, který materializoval celý deník firmy — oba musí dát pro každý
 * zápis stejný původ i stejnou množinu zápisů prošlých filtrem uzávěrky.
 */
final class JournalTaxOriginEquivalenceTest extends TestCase
{
    private const LEGACY_CTE = "tax_journal_origins AS (
            SELECT e.id, e.source_type, e.source_id, e.reversed_by
              FROM journal_entries e
             WHERE e.supplier_id = 1
               AND NOT EXISTS (SELECT 1 FROM journal_entries parent WHERE parent.supplier_id = 1 AND parent.reversed_by = e.id)
            UNION ALL
            SELECT reversal.id, origin.source_type, origin.source_id, reversal.reversed_by
              FROM tax_journal_origins origin
              JOIN journal_entries reversal ON reversal.id = origin.reversed_by
             WHERE reversal.supplier_id = 1
        )";

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, source_type TEXT, source_id INTEGER, reversed_by INTEGER)');
        $slot = ClosingSourceId::STOCK_SLOT_BASE + 5;
        $this->pdo->exec("INSERT INTO journal_entries VALUES
            (1, 1, 'invoice', 10, NULL),
            (2, 1, 'purchase_invoice', 20, 3),
            (3, 1, 'purchase_invoice', NULL, 4),
            (4, 1, 'purchase_invoice', NULL, NULL),
            (5, 1, 'manual', NULL, 6),
            (6, 1, 'purchase_invoice', 99, NULL),
            (7, 1, 'closing', 1, 8),
            (8, 1, 'closing', NULL, NULL),
            (9, 1, 'closing', {$slot}, NULL),
            (10, 1, 'closing', 2, NULL),
            (11, 2, 'bank', 5, 12),
            (12, 1, 'bank', NULL, NULL),
            (13, 1, 'opening', NULL, NULL),
            (14, 1, 'closing', {$slot}, 15),
            (15, 1, 'closing', 3, NULL)");
    }

    public function testEveryEntryGetsTheSameOriginAsTheFullJournalCte(): void
    {
        $legacy = $this->rows('WITH RECURSIVE ' . self::LEGACY_CTE . '
            SELECT e.id, tax_origin.source_type, tax_origin.source_id
              FROM journal_entries e JOIN tax_journal_origins tax_origin ON tax_origin.id = e.id
             WHERE e.supplier_id = 1 ORDER BY e.id');
        $current = $this->rows('WITH RECURSIVE ' . JournalTaxOrigin::cte(1) . '
            SELECT e.id, ' . JournalTaxOrigin::sourceTypeSql() . ' AS source_type, ' . JournalTaxOrigin::sourceIdSql() . ' AS source_id
              FROM journal_entries e ' . JournalTaxOrigin::join() . '
             WHERE e.supplier_id = 1 ORDER BY e.id');

        self::assertCount(14, $legacy);
        self::assertSame($legacy, $current);
        // Storno ruční opravy nese vlastní source_id, ale původ je ruční zápis bez zdroje.
        self::assertSame(['id' => 6, 'source_type' => 'manual', 'source_id' => null], $current[5]);
    }

    public function testClosingFilterKeepsTheSameEntries(): void
    {
        $legacy = $this->ids('WITH RECURSIVE ' . self::LEGACY_CTE . "
            SELECT e.id FROM journal_entries e JOIN tax_journal_origins tax_origin ON tax_origin.id = e.id
             WHERE e.supplier_id = 1 AND (tax_origin.source_type <> 'closing' OR tax_origin.source_id >= ?) ORDER BY e.id");
        $current = $this->ids('WITH RECURSIVE ' . JournalTaxOrigin::cte(1) . '
            SELECT e.id FROM journal_entries e ' . JournalTaxOrigin::join() . '
             WHERE e.supplier_id = 1 AND ' . JournalTaxOrigin::includedSql() . ' ORDER BY e.id');

        self::assertSame([1, 2, 3, 4, 5, 6, 9, 12, 13, 14, 15], $legacy);
        self::assertSame($legacy, $current);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $rows = $this->pdo->query($sql)->fetchAll();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'source_type' => $r['source_type'],
            'source_id' => $r['source_id'] === null ? null : (int) $r['source_id'],
        ], $rows);
    }

    /** @return list<int> */
    private function ids(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([(string) ClosingSourceId::STOCK_SLOT_BASE]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
