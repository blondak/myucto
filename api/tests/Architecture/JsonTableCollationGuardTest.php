<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Textový sloupec JSON_TABLE nedědí collation tabulky, ale výchozí collation
 * databáze. Na instalaci, jejíž databáze má jinou výchozí collation než tabulky
 * (utf8mb4_czech_ci, utf8mb4_general_ci), pak porovnání s tabulkovým sloupcem
 * padá na „Illegal mix of collations“ (1267). CI to nechytí — zakládá databázi
 * s utf8mb4_unicode_ci. Každý dotaz s textovým sloupcem JSON_TABLE proto musí
 * collation uvést výslovně.
 */
final class JsonTableCollationGuardTest extends TestCase
{
    public function testTextualJsonTableColumnsDeclareCollationExplicitly(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (stripos($source, 'JSON_TABLE') === false) {
                continue;
            }
            // Jeden dotaz = od JSON_TABLE po konec řetězce předaného do prepare()/query().
            preg_match_all('/JSON_TABLE\s*\((?:(?!"\);|\'\);).)*/is', $source, $statements);
            foreach ($statements[0] as $statement) {
                $textual = preg_match('/\b(?:VARCHAR|CHAR|TEXT)\s*(?:\(\d+\))?\s+PATH\b/i', $statement) === 1;
                if ($textual && stripos($statement, 'COLLATE') === false) {
                    $offenders[] = str_replace($root, 'src', $file->getPathname());
                }
            }
        }

        self::assertSame([], array_values(array_unique($offenders)), 'Textový sloupec JSON_TABLE bez výslovné collation: ' . implode(', ', $offenders));
    }
}
