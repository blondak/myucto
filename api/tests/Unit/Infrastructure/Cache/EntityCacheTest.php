<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Cache;

use MyInvoice\Infrastructure\Cache\EntityCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Detekce zápisu je jediné, co stojí mezi cache a zastaralými daty. Nebezpečný
 * směr je JEN jeden: zmeškaná invalidace. Zbytečná stojí dotaz navíc, zmeškaná
 * znamená, že uživatel po odebrání role o ni nepřijde a licence počítá stará
 * místa. Testy proto tlačí hlavně na to, aby se žádný write nepropašoval.
 */
final class EntityCacheTest extends TestCase
{
    /** @return iterable<string,array{string,list<string>}> */
    public static function writeStatements(): iterable
    {
        yield 'UPDATE users' => ['UPDATE users SET is_active = 0 WHERE id = 5', [EntityCache::GROUP_USER]];
        yield 'INSERT users' => ['INSERT INTO users (email) VALUES (?)', [EntityCache::GROUP_USER]];
        yield 'DELETE users' => ['DELETE FROM users WHERE id = ?', [EntityCache::GROUP_USER]];
        yield 'změna role' => ['UPDATE roles SET name = ? WHERE id = ?', [EntityCache::GROUP_USER]];
        yield 'změna oprávnění role' => ['DELETE FROM role_permissions WHERE role_id = ?', [EntityCache::GROUP_USER]];
        yield 'membership' => ['INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)', [EntityCache::GROUP_USER]];
        yield 'UPDATE supplier' => ['UPDATE supplier SET company_name = ? WHERE id = 1', [EntityCache::GROUP_SUPPLIER]];
        yield 'UPDATE license' => ['UPDATE license SET token = ? WHERE id = 1', [EntityCache::GROUP_LICENSE]];

        // Vícetabulkový zápis musí přetočit obě skupiny.
        yield 'JOIN přes users i supplier' => [
            'UPDATE users u JOIN supplier s ON s.id = u.supplier_id SET u.x = 1',
            [EntityCache::GROUP_USER, EntityCache::GROUP_SUPPLIER],
        ];

        // `DELETE alias FROM tabulka` — tvar, na kterém by naivní regex „DELETE FROM (\w+)" selhal.
        yield 'DELETE s aliasem' => ['DELETE u FROM users u JOIN roles r ON r.id = u.role_id', [EntityCache::GROUP_USER]];

        // Velká/malá písmena a odsazení nesmí hrát roli.
        yield 'malá písmena a odsazení' => ["\n   update  users\n set x=1", [EntityCache::GROUP_USER]];

        yield 'TRUNCATE' => ['TRUNCATE TABLE supplier', [EntityCache::GROUP_SUPPLIER]];
        yield 'ALTER (migrace)' => ['ALTER TABLE users ADD COLUMN x INT', [EntityCache::GROUP_USER]];
    }

    /** @param list<string> $expected */
    #[DataProvider('writeStatements')]
    public function testWriteStatementsInvalidateTheirGroups(string $sql, array $expected): void
    {
        $actual = EntityCache::groupsForSql($sql);
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
    }

    /** @return iterable<string,array{string}> */
    public static function readStatements(): iterable
    {
        yield 'SELECT users' => ['SELECT * FROM users WHERE id = ?'];
        yield 'SELECT supplier' => ['SELECT COUNT(*) FROM supplier'];
        yield 'SELECT license' => ['SELECT * FROM license WHERE id = 1'];
        yield 'SELECT s JOINem' => ['SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id'];
        yield 'SHOW' => ['SHOW TABLES'];
        yield 'SET' => ["SET time_zone = '+02:00'"];
    }

    /**
     * Čtení nesmí invalidovat — jinak by se cache přetáčela každým SELECTem
     * a nikdy by nic netrefila.
     */
    #[DataProvider('readStatements')]
    public function testReadStatementsInvalidateNothing(string $sql): void
    {
        self::assertSame([], EntityCache::groupsForSql($sql));
    }

    /**
     * Zápis do nehlídané tabulky se cache netýká — jinak by každý zaúčtovaný
     * doklad zahodil licenci i dodavatele.
     */
    public function testWritesToUnwatchedTablesAreIgnored(): void
    {
        // Tabulky mimo hlídané skupiny — zápis do nich nesmí nic invalidovat,
        // jinak by se cache přetáčela při běžném provozu a nikdy nic netrefila.
        self::assertSame([], EntityCache::groupsForSql('INSERT INTO sessions (id) VALUES (1)'));
        self::assertSame([], EntityCache::groupsForSql('UPDATE activity_log SET x = 1'));
        self::assertSame([], EntityCache::groupsForSql('DELETE FROM cron_runs WHERE id = 1'));
    }

    /**
     * Náhled doplatku daně je projekce celoročního účetnictví, takže ho musí
     * shodit jakýkoli účetní zápis. Podinvalidace by znamenala ukazovat
     * uživateli zastaralou částku daně — proto je skupina široká.
     *
     * @return iterable<string,array{string}>
     */
    public static function accountingWrites(): iterable
    {
        yield 'zápis do deníku' => ['INSERT INTO journal_entries (id) VALUES (1)'];
        yield 'řádek deníku' => ['UPDATE journal_lines SET amount = 1 WHERE id = 2'];
        yield 'vydaná faktura' => ['UPDATE invoices SET total = 1 WHERE id = 3'];
        yield 'položka přijaté faktury' => ['DELETE FROM purchase_invoice_items WHERE id = 4'];
        yield 'bankovní transakce' => ['INSERT INTO bank_transactions (id) VALUES (5)'];
        yield 'majetek' => ['UPDATE assets SET disposal_date = NULL WHERE id = 6'];
        yield 'účetní období' => ['UPDATE accounting_periods SET status = ? WHERE id = 7'];
        yield 'závěrkový krok' => ['INSERT INTO closing_steps (id) VALUES (8)'];
        yield 'daňové přiznání' => ['UPDATE tax_returns SET status = ? WHERE id = 9'];
    }

    #[DataProvider('accountingWrites')]
    public function testAccountingWritesInvalidateTaxPreview(string $sql): void
    {
        self::assertContains(EntityCache::GROUP_ACCOUNTING, EntityCache::groupsForSql($sql));
    }

    /**
     * Hranice slova: `supplier_bank_accounts` není `supplier`. Bez toho by se
     * skupina přetáčela při každém zápisu do vedlejších tabulek a cache by
     * ztratila smysl.
     */
    public function testTableNamesMatchOnWordBoundary(): void
    {
        self::assertSame([], EntityCache::groupsForSql('UPDATE supplier_bank_accounts SET iban = ?'));
        self::assertSame([], EntityCache::groupsForSql('INSERT INTO users_archive (id) VALUES (1)'));
    }

    /**
     * Vypnutá cache musí být ÚPLNĚ průchozí — ani memo v rámci requestu. Instance
     * z disabled() není napojená na WriteWatcher, takže by se memo nikdy nevyčistilo
     * a volající by po vlastním zápisu dostal starou hodnotu.
     */
    public function testDisabledCacheAlwaysCallsTheProducer(): void
    {
        $cache = EntityCache::disabled();
        $calls = 0;
        $producer = function () use (&$calls): int {
            $calls++;

            return $calls;
        };

        self::assertSame(1, $cache->remember(EntityCache::GROUP_USER, 'k', $producer));
        self::assertSame(2, $cache->remember(EntityCache::GROUP_USER, 'k', $producer));
        self::assertSame(2, $calls);
    }

    public function testDisabledCacheInvalidationIsHarmless(): void
    {
        $cache = EntityCache::disabled();
        $cache->invalidateGroup(EntityCache::GROUP_USER);
        self::assertSame(7, $cache->remember(EntityCache::GROUP_USER, 'k', static fn (): int => 7));
    }
}
