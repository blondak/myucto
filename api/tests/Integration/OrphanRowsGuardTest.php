<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Žádná tabulka nesmí obsahovat řádky ukazující na neexistujícího dodavatele.
 *
 * Tichá koroze, kterou tenhle test dělá hlasitou: v ostré databázi se za 4 dny nasbíralo
 * 12 371 osiřelých řádků (6 160 jen v účtové osnově), protože jeden test mazal dodavatele
 * se `SET FOREIGN_KEY_CHECKS=0` — což vypne i `ON DELETE CASCADE`. Nikdo si toho nevšiml,
 * dokud na tom nespadla migrace insertující do `chart_of_accounts`.
 *
 * Test běží proti TESTOVACÍ databázi (bootstrap vynucuje `*_test`), takže hlídá přesně to
 * prostředí, kde se sirotci rodí. Kontroluje VŠECHNY tabulky se sloupcem `supplier_id`,
 * ne jen ty dnes známé — nová tabulka je pokrytá automaticky.
 *
 * `supplier_id IS NULL` se NEPOČÍTÁ jako sirotek: `activity_log` takhle legitimně eviduje
 * systémové a globální události bez vazby na firmu.
 */
#[Group('integration')]
final class OrphanRowsGuardTest extends TestCase
{
    public function testNoRowsReferenceMissingSupplier(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $db->pdo();

        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        // Jen tabulky, které mají na `supplier` skutečný cizí klíč — hlídaný invariant je
        // „kaskáda proběhla", a ta se týká právě jich. `activity_log` FK nemá (audit se
        // záměrně drží i po smazání firmy a maže ho explicitně SettingsAction::deleteSupplier),
        // takže osiřelý řádek tam není porušení kaskády a test by kvůli němu svítil červeně
        // z nesouvisejícího důvodu.
        $tables = $pdo->prepare(
            "SELECT DISTINCT k.TABLE_NAME
               FROM information_schema.KEY_COLUMN_USAGE k
              WHERE k.TABLE_SCHEMA = ?
                AND k.COLUMN_NAME = 'supplier_id'
                AND k.REFERENCED_TABLE_NAME = 'supplier'
              ORDER BY k.TABLE_NAME"
        );
        $tables->execute([$schema]);

        $orphans = [];
        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM `$table` x
                  WHERE x.supplier_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM supplier s WHERE s.id = x.supplier_id)"
            )->fetchColumn();
            if ($n > 0) {
                $orphans[$table] = $n;
            }
        }

        if ($orphans !== []) {
            $lines = [];
            foreach ($orphans as $table => $n) {
                $lines[] = sprintf('  %-46s %d', $table, $n);
            }
            self::fail(
                "V databázi „$schema\" jsou řádky patřící neexistujícímu dodavateli.\n"
                    . "Typická příčina: mazání dodavatele se SET FOREIGN_KEY_CHECKS=0, které vypne\n"
                    . "i ON DELETE CASCADE (viz ForeignKeyChecksGuardTest).\n"
                    . implode("\n", $lines),
            );
        }
        self::assertSame([], $orphans);
    }
}
