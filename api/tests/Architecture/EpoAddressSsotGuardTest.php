<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\TestCase;

/**
 * Adresní parsing pro podání smí žít jen v {@see \MyInvoice\Service\Report\EpoSupplierBlockBuilder}.
 *
 * Historie, kterou tenhle guard uzavírá: logika byla INLINE uvnitř `fillVetaP()`, tedy
 * nevolatelná, a vznikly z ní čtyři kopie (DPFO, DPPO, ČSSZ a originál). Registr SSOT
 * to označil za nejrizikovější zbytek fáze F1 — a měl pravdu ze strukturálního důvodu,
 * ne kvůli nedbalosti: **SSOT, který nejde zavolat, se okopíruje rychleji, než kdyby
 * žádný nebyl**, protože vytváří dojem, že pravidlo je vyřešené. Příští oprava adresy
 * by zase dopadla jen na část podání, přesně jako oprava jména u chyby #200.
 *
 * Guard je záměrně na REGEXU, ne na jménu metody: kopii nedělá ten, kdo si napíše
 * `parseStreet()`, ale ten, kdo si vedle napíše ten samý vzor.
 */
final class EpoAddressSsotGuardTest extends TestCase
{
    /** Jediné místo, kde vzor adresy smí být. */
    private const SSOT = 'Service/Report/EpoSupplierBlockBuilder.php';

    /** Symbol v SSOT, který vzor drží — kdyby se přejmenoval, guard to musí poznat. */
    private const SSOT_SYMBOL = 'parseStreet';

    /**
     * Vzory, které rozdělují ulici od čísla popisného/orientačního. Stačí, aby se
     * v souboru objevil kterýkoli — je to charakteristický otisk kopie.
     *
     * @var list<string>
     */
    private const STREET_PATTERNS = [
        '\d+[a-zA-Z]?(?:\s*\/\s*\d+[a-zA-Z]?)?\s*$',
        '(\d+[a-zA-Z]?)(?:\s*\/\s*(\d+[a-zA-Z]?))?',
    ];

    public function testStreetParsingLivesOnlyInTheSsot(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        foreach ($this->phpFiles($srcDir) as $path) {
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
            if ($rel === self::SSOT) {
                continue;
            }
            $code = (string) file_get_contents($path);

            foreach (self::STREET_PATTERNS as $pattern) {
                if (!str_contains($code, $pattern)) {
                    continue;
                }
                $line = $this->lineOf($code, $pattern);
                $offenders[] = sprintf(
                    '%s:%d (%s)',
                    $rel,
                    $line,
                    PhpSourceRegions::symbolAtLine($code, $line) ?? '(mimo symbol)',
                );
            }
        }

        self::assertSame([], array_unique($offenders), sprintf(
            "Vlastní kopie adresního parsingu mimo SSOT:\n  %s\n\n"
                . 'Zavolej EpoSupplierBlockBuilder::parseStreet() (a houseNumber() tam, kde se '
                . "číslo skládá do jednoho pole).\n"
                . 'Kopie znamená, že příští oprava adresy dopadne jen na část podání.',
            implode("\n  ", array_unique($offenders)),
        ));
    }

    /**
     * Pojistka, že guard má co hlídat: kdyby se SSOT přejmenoval nebo vzor z něj zmizel,
     * sken výše by nenašel nic a zůstal zelený — tvrdil by, že hlídá, a nekontroloval nic.
     */
    public function testSsotStillHoldsThePattern(): void
    {
        $path = dirname(__DIR__, 2) . '/src/' . self::SSOT;
        self::assertFileExists($path, 'SSOT adresního parsingu zmizel — aktualizuj guard.');

        $code = (string) file_get_contents($path);
        self::assertSame(
            [],
            PhpSourceRegions::missingSymbols($code, [self::SSOT_SYMBOL]),
            'V SSOT chybí metoda ' . self::SSOT_SYMBOL . '() — guard by nekontroloval nic.',
        );

        $found = false;
        foreach (self::STREET_PATTERNS as $pattern) {
            if (str_contains($code, $pattern)) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'V SSOT už není žádný z hlídaných vzorů — guard se rozešel s kódem.');
    }

    /** Volající musí SSOT skutečně volat, ne mít vlastní (byť shodnou) implementaci. */
    public function testSubmissionBuildersDelegateToTheSsot(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $callers = [
            'Service/Tax/Return/DpfoXmlBuilder.php',
            'Service/Tax/Return/DppoXmlBuilder.php',
            'Service/Tax/Return/CsszPrehledXmlBuilder.php',
        ];

        $missing = [];
        foreach ($callers as $rel) {
            $path = $srcDir . '/' . $rel;
            if (!is_file($path)) {
                $missing[] = $rel . ' — soubor neexistuje, aktualizuj guard';
                continue;
            }
            if (!str_contains((string) file_get_contents($path), 'EpoSupplierBlockBuilder::parseStreet')) {
                $missing[] = $rel . ' — nevolá EpoSupplierBlockBuilder::parseStreet()';
            }
        }

        self::assertSame([], $missing, sprintf(
            "Builder podání nedeleguje adresní parsing na SSOT:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /**
     * Buildery EPO výkazů nesmí mít vlastní SELECT dodavatele. Tři téměř shodné seznamy
     * sloupců byly příčinou tiché degradace: `fillVetaP()` u zapomenutého sloupce jen
     * neemitoval atribut a podání odešlo neúplné.
     */
    public function testEpoBuildersLoadSupplierThroughTheSsot(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $builders = [
            'Service/Report/DphPriznaniBuilder.php',
            'Service/Report/KontrolniHlaseniBuilder.php',
            'Service/Report/SouhrnneHlaseniBuilder.php',
        ];

        $offenders = [];
        foreach ($builders as $rel) {
            $path = $srcDir . '/' . $rel;
            if (!is_file($path)) {
                $offenders[] = $rel . ' — soubor neexistuje, aktualizuj guard';
                continue;
            }
            $code = (string) file_get_contents($path);

            if (!str_contains($code, 'EpoSupplierBlockBuilder::loadSupplier')) {
                $offenders[] = $rel . ' — nevolá EpoSupplierBlockBuilder::loadSupplier()';
            }
            if (str_contains($code, 'FROM supplier s')) {
                $offenders[] = $rel . ' — má vlastní SELECT z tabulky supplier';
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Builder EPO výkazu si načítá dodavatele sám:\n  %s\n\n"
                . 'Vlastní seznam sloupců se rozejde a fillVetaP() na chybějící sloupec '
                . 'reagoval tiše vynechaným atributem v podání.',
            implode("\n  ", $offenders),
        ));
    }

    private function lineOf(string $code, string $needle): int
    {
        $offset = strpos($code, $needle);

        return $offset === false ? 1 : substr_count(substr($code, 0, $offset), "\n") + 1;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
