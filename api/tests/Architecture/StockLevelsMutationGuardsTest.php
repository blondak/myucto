<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Epic SKLAD, plán §8.3 — mutace tabulky `stock_levels` VÝHRADNĚ přes
 * {@see \MyInvoice\Service\Stock\StockLevelService} (class docblock tam
 * to prohlašuje závazně). Static source-level guard: žádný jiný soubor pod
 * `src/` nesmí obsahovat `UPDATE stock_levels` ani `INSERT [IGNORE] INTO
 * stock_levels` — to by obešlo zamykání (lockLevels) a haléřovou aritmetiku
 * klouzavého průměru (StockValuation) a mohlo by vytvořit nekonzistentní
 * (nebo záporný) stav bez replaye.
 */
final class StockLevelsMutationGuardsTest extends TestCase
{
    private const ALLOWED_FILE = 'StockLevelService.php';

    public function testOnlyStockLevelServiceMutatesStockLevels(): void
    {
        $dir = dirname(__DIR__, 2) . '/src';
        $files = self::rglob($dir, '*.php');
        self::assertNotEmpty($files, 'src adresář nenalezen.');

        $pattern = '/\b(?:UPDATE\s+`?stock_levels`?|INSERT\s+(?:IGNORE\s+)?INTO\s+`?stock_levels`?)\b/i';

        $violations = [];
        foreach ($files as $file) {
            $base = basename($file);
            if ($base === self::ALLOWED_FILE) {
                continue;
            }
            $code = file_get_contents($file);
            self::assertIsString($code, "Nelze přečíst $file");
            if (preg_match($pattern, $code) === 1) {
                $violations[] = $file;
            }
        }

        self::assertSame(
            [],
            $violations,
            "Mutace stock_levels mimo StockLevelService (Epic SKLAD §8.3):\n" . implode("\n", $violations)
        );
    }

    /** @return list<string> */
    private static function rglob(string $dir, string $pattern): array
    {
        $out = [];
        $items = glob($dir . '/*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $out = array_merge($out, self::rglob($item, $pattern));
                continue;
            }
            if (fnmatch($pattern, basename($item))) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
