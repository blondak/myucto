<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Vypnutá kontrola cizích klíčů vypne i `ON DELETE CASCADE`. Kdo v takovém okně smaže
 * rodičovský řádek, nechá po sobě osiřelé děti — a databáze o tom už nikdy neřekne.
 *
 * Reálný dopad (2026-07): {@see \MyInvoice\Tests\Integration\Sample\SampleDataPurgeTest}
 * takhle mazal testovacího dodavatele. Kaskáda nikdy neproběhla, takže po každém běhu
 * zůstalo ~220 řádků účtové osnovy, ~132 ai_jobs, číselné řady i účetní období. Za 28 běhů
 * se v ostré databázi nasbíralo 12 371 osiřelých řádků, které pak shodily migraci
 * insertující do `chart_of_accounts` (FK na neexistujícího dodavatele).
 *
 * Hlídá se proto PŘESNĚ ta chyba, ne vypínání kontrol jako takové: vypnout kontrolu kvůli
 * tabulkám s RESTRICT FK je legitimní (a nutné), ale `DELETE FROM supplier` musí proběhnout
 * až po jejím ZAPNUTÍ — vzor {@see \MyInvoice\Action\Settings\SettingsAction::deleteSupplier()}.
 */
final class ForeignKeyChecksGuardTest extends TestCase
{
    /** Vypnutí kontroly — jen uvnitř skutečného SQL volání, ne v komentáři. */
    private const DISABLE = '/(?:exec|query|prepare)\s*\(\s*[\'"][^\'"]*FOREIGN_KEY_CHECKS\s*=\s*0/i';
    private const ENABLE  = '/(?:exec|query|prepare)\s*\(\s*[\'"][^\'"]*FOREIGN_KEY_CHECKS\s*=\s*1/i';
    private const DELETE_SUPPLIER = '/DELETE\s+FROM\s+`?supplier`?\s+WHERE/i';

    public function testSupplierIsNeverDeletedWithForeignKeyChecksDisabled(): void
    {
        $offenders = [];
        foreach ($this->phpFilesIn(dirname(__DIR__)) as $rel => $code) {
            if (preg_match_all(self::DISABLE, $code, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[0] as [$_, $disableAt]) {
                $enableAt = $this->nextOffset(self::ENABLE, $code, $disableAt);
                $deleteAt = $this->nextOffset(self::DELETE_SUPPLIER, $code, $disableAt);
                // Smazání dodavatele uvnitř okna s vypnutou kontrolou = kaskáda neproběhne.
                if ($deleteAt !== null && ($enableAt === null || $deleteAt < $enableAt)) {
                    $offenders[] = $rel . ' (řádek ' . $this->lineAt($code, $deleteAt) . ')';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "`DELETE FROM supplier` proběhl s vypnutou kontrolou cizích klíčů — tím se vypne\n"
                . "i ON DELETE CASCADE a v databázi zůstanou osiřelé řádky. Kontrolu zapni ZPĚT\n"
                . "ještě před smazáním dodavatele (vzor SettingsAction::deleteSupplier).\n  "
                . implode("\n  ", $offenders),
        );
    }

    /** @return array<string,string> relativní cesta => obsah */
    private function phpFilesIn(string $root): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $out[$rel] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    private function nextOffset(string $pattern, string $code, int $from): ?int
    {
        if (preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE, $from) !== 1) {
            return null;
        }
        return $m[0][1];
    }

    private function lineAt(string $code, int $offset): int
    {
        return substr_count(substr($code, 0, $offset), "\n") + 1;
    }
}
