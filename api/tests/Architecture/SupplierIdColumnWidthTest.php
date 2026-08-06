<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Pojistka proti zúžení tenant klíče `supplier_id`.
 *
 * Migrace `0115_supplier_id_int.sql` záměrně rozšířila `supplier.id` i všechny
 * navázané `supplier_id` z TINYINT UNSIGNED (strop 255) na INT UNSIGNED —
 * hostovaná multi-tenant verze by jinak narazila na 256. dodavateli.
 *
 * Tu opravu už jednou zregresovaly tři pozdější migrace (1111, 1150, 1173),
 * které si nové tabulky založily znovu s TINYINT UNSIGNED. Nikdo si toho nevšiml,
 * protože ani jedna z nich nemá na `supplier_id` FK na `supplier.id` — nesoulad
 * typů by FK odmítl a chyba by se odhalila hned při migraci. Bez FK to vyjde
 * najevo až tím, že firmě s id > 255 selže KAŽDÝ zápis do té tabulky
 * (`sql_mode` je připnutý, takže chyba, ne tiché oříznutí).
 *
 * Test proto kontroluje schéma přímo, ne existenci FK: každý sloupec jménem
 * `supplier_id` musí unést celý rozsah `supplier.id`, tj. být alespoň
 * INT UNSIGNED. Širší (BIGINT UNSIGNED) je v pořádku — jen nekonzistentní.
 */
final class SupplierIdColumnWidthTest extends TestCase
{
    /** Šířka celočíselných typů v bajtech — porovnáváme proti INT (4 B). */
    private const INT_BYTES = [
        'tinyint'   => 1,
        'smallint'  => 2,
        'mediumint' => 3,
        'int'       => 4,
        'integer'   => 4,
        'bigint'    => 8,
    ];

    private Connection $db;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — schema guard vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
            $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testEverySupplierIdColumnFitsWholeSupplierIdRange(): void
    {
        $rows = $this->db->pdo()->query(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND (COLUMN_NAME = 'supplier_id' OR (TABLE_NAME = 'supplier' AND COLUMN_NAME = 'id'))
              ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotEmpty($rows, 'Ve schématu není žádný sloupec supplier_id — test měří něco jiného, než měl.');

        $problems = [];
        foreach ($rows as $row) {
            $table    = (string) $row['TABLE_NAME'];
            $column   = (string) $row['COLUMN_NAME'];
            $type     = strtolower((string) $row['COLUMN_TYPE']);
            $dataType = strtolower((string) $row['DATA_TYPE']);

            $bytes = self::INT_BYTES[$dataType] ?? null;
            if ($bytes === null) {
                $problems[] = "{$table}.{$column} je '{$type}' — tenant klíč musí být celočíselný (INT UNSIGNED)";
                continue;
            }
            if ($bytes < self::INT_BYTES['int']) {
                $problems[] = "{$table}.{$column} je '{$type}' (strop " . ((1 << (8 * $bytes)) - 1) . ')';
                continue;
            }
            if (!str_contains($type, 'unsigned')) {
                $problems[] = "{$table}.{$column} je '{$type}' — chybí UNSIGNED, horní polovina rozsahu se nevejde";
            }
        }

        self::assertSame(
            [],
            $problems,
            "Tenant klíč supplier_id je někde užší než INT UNSIGNED — firma s id > 255 do té tabulky nezapíše:\n  "
            . implode("\n  ", $problems)
            . "\n\nSprávná šířka je INT UNSIGNED (viz db/migrations/0115_supplier_id_int.sql a jeho hlavičku)."
            . "\nNovou tabulku zakládej rovnou s `supplier_id INT UNSIGNED`; existující rozšiř migrací"
            . "\n(MODIFY přepisuje definici celou — zachovej NOT NULL/DEFAULT/COMMENT beze změny).",
        );
    }
}
