<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * Povolení pro BypassFinals patří výhradně do `tests/bootstrap.php`.
 *
 * `DG\BypassFinals::allowPaths()` seznam NAHRAZUJE, nerozšiřuje, a `final` se
 * odstraňuje jen v okamžiku načtení třídy. Volání v jednotlivém testovém
 * souboru proto zúží povolení pro celý zbytek procesu a o tom, jestli zdvojení
 * projde, rozhodne pořadí testů. S `--filter` to nikdy nespadne, v plné sadě
 * ano — přesně to 30. 8. 2026 dvakrát shodilo CI, zatímco lokálně bylo zeleno.
 *
 * Jediný seznam v bootstrapu tenhle druh chyby vylučuje: co je povolené, je
 * povolené od začátku procesu a na pořadí nezáleží.
 */
final class BypassFinalsSingleSourceTest extends TestCase
{
    public function testOnlyBootstrapConfiguresBypassFinals(): void
    {
        $offenders = [];
        foreach ($this->testFiles() as $file) {
            // Bootstrap je jediné legitimní místo; tenhle test se vynechává sám,
            // protože hledaný řetězec nutně obsahuje ve své vlastní kontrole.
            if (str_ends_with(str_replace('\\', '/', $file), 'api/tests/bootstrap.php')
                || $file === str_replace('\\', '/', __FILE__)
            ) {
                continue;
            }
            $source = SourceCorpus::read($file);
            if (str_contains($source, 'BypassFinals::allowPaths')) {
                $offenders[] = $file;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Tyhle testy si nastavují vlastní allowPaths a tím zúží povolení celému\n"
            . "procesu. Cestu přidejte do seznamu v api/tests/bootstrap.php:\n  "
            . implode("\n  ", $offenders),
        );
    }

    /** @return list<string> */
    private function testFiles(): array
    {
        return SourceCorpus::files(dirname(__DIR__));
    }
}
