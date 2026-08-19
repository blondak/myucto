<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Pojistka pro přechod z MyInvoice.cz na MyÚčto.cz.
 *
 * Instalace MyInvoice se povyšuje na MyÚčto **in-place**: kód se vymění a nad
 * existující databází se pustí `migrate.php`, který dojede migrace 1000+.
 * Schéma pod 1000 mají oba projekty shodné, takže to funguje — až na jednu
 * výjimku, kterou hlídá tenhle test.
 *
 * MyInvoice pár sloupců, které MyÚčto dál používá, svými vlastními migracemi
 * ZAHODIL (viz {@see self::DROPPED_BY_MYINVOICE}). Migrace MyÚčta 1000+ na ně
 * ale sahají, a `ALTER TABLE … MODIFY|CHANGE COLUMN` nemá variantu
 * `IF EXISTS` — na instalaci přicházející z MyInvoice by taková migrace spadla
 * na error 1054 a celý upgrade by se zastavil uprostřed. Přesně to dělala
 * `1137_supplier_data_box_type_comment.sql`, než dostala předřazené
 * `ADD COLUMN IF NOT EXISTS`.
 *
 * Pravidlo: sahá-li migrace 1000+ na takový sloupec přes MODIFY/CHANGE, musí
 * si ho ve stejném souboru nejdřív zajistit přes `ADD COLUMN IF NOT EXISTS`.
 * Na instalacích, které sloupec mají, je to no-op; na těch z MyInvoice ho to
 * zavede se správnou definicí. Data se neztrácejí — MyInvoice ty sloupce
 * zahazoval právě proto, že byly všude NULL.
 *
 * Přibude-li v MyInvoice další takový DROP, dopiš ho do konstanty níž.
 */
final class MyInvoiceUpgradePathTest extends TestCase
{
    /**
     * Sloupce, které MyInvoice.cz zahazuje svými migracemi pod 1000, ale
     * MyÚčto je dál používá. Formát: 'tabulka.sloupec' => 'migrace MyInvoice'.
     */
    private const DROPPED_BY_MYINVOICE = [
        'supplier.data_box_type' => '0140_supplier_drop_data_box_type.sql',
    ];

    public function testMigrationsTouchingDroppedColumnsEnsureThemFirst(): void
    {
        $dir   = dirname(__DIR__, 3) . '/db/migrations';
        $files = glob($dir . '/[1-9][0-9][0-9][0-9]_*.sql') ?: [];

        self::assertNotEmpty($files, 'Nenalezeny žádné migrace 1000+.');

        $problems = [];

        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            // Komentáře pryč — zmínka sloupce v hlavičce není zásah do schématu.
            $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

            foreach (self::DROPPED_BY_MYINVOICE as $qualified => $droppedBy) {
                [$table, $column] = explode('.', $qualified, 2);

                $modifies = (bool) preg_match(
                    '/\b(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?`?' . preg_quote($column, '/') . '`?\b/i',
                    $sql
                );
                if (!$modifies) {
                    continue;
                }

                $ensures = (bool) preg_match(
                    '/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?' . preg_quote($column, '/') . '`?\b/i',
                    $sql
                );
                if ($ensures) {
                    continue;
                }

                $problems[] = sprintf(
                    "%s: MODIFY/CHANGE nad `%s`.`%s`, který MyInvoice zahazuje v %s,\n"
                    . "    ale chybí předřazené `ALTER TABLE %s ADD COLUMN IF NOT EXISTS %s …`.\n"
                    . "    Upgrade z MyInvoice by na téhle migraci spadl na error 1054.",
                    basename($file),
                    $table,
                    $column,
                    $droppedBy,
                    $table,
                    $column
                );
            }
        }

        self::assertSame(
            [],
            $problems,
            "Migrace rozbíjejí in-place upgrade z MyInvoice.cz:\n\n" . implode("\n\n", $problems) . "\n"
        );
    }
}
