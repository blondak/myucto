<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Report\EpoEnvelope;
use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\TestCase;

/**
 * Obálka EPO podání a čtení souboru `VERSION` smí žít jen v {@see EpoEnvelope}.
 *
 * Do fáze F1 existovala obálka v ŠESTI copy-paste kopiích (DPHDP3, DPHKH1, DPHSHV,
 * OSSEI1, DPFDP7, DPPDP9) a čtení `VERSION` v PĚTI — a ty kopie se stihly rozejít:
 * dvě vracely u prázdného souboru `null`, tři prázdný řetězec. Tatáž instalace by tak
 * poslala do jednoho podání `verzeSW="0"` a do druhého `verzeSW=""`.
 *
 * Je to neškodná položka, ale úplně stejná třída chyby jako ta, kvůli které audit
 * vznikl: pravidlo implementované N× a opravené M &lt; N×. Guard drží počet na jedné.
 *
 * **Rozsah je záměrně jen PODÁNÍ.** `VERSION` čtou i `VersionService` (vrací
 * `'unknown'`, autorita pro UI a health endpoint), `LicenseService` a `ArchiveService`.
 * To NEJSOU kopie k sjednocení — mají vlastní, věcně odlišnou sémantiku prázdné
 * hodnoty a jiné konzumenty. Sjednotit je „protože je to taky VERSION" by bylo přesně
 * to „uklízení", před kterým registr SSOT varuje: ne každá odlišnost je drift.
 */
final class EpoEnvelopeSsotGuardTest extends TestCase
{
    private const SSOT = 'Service/Report/EpoEnvelope.php';

    /**
     * Buildery podání, které obálku používají. Nový formulář sem patří taky —
     * jinak by guard sedmou kopii neviděl.
     *
     * @var list<string>
     */
    private const SUBMISSION_BUILDERS = [
        'Service/Report/DphPriznaniBuilder.php',
        'Service/Report/KontrolniHlaseniBuilder.php',
        'Service/Report/SouhrnneHlaseniBuilder.php',
        'Service/Oss/OssXmlExporter.php',
        'Service/Tax/Return/DpfoXmlBuilder.php',
        'Service/Tax/Return/DppoXmlBuilder.php',
    ];

    public function testEnvelopeIsBuiltOnlyBySsot(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        foreach ($this->phpFiles($srcDir) as $path) {
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
            if ($rel === self::SSOT) {
                continue;
            }
            $code = (string) file_get_contents($path);

            // Obálku nesmí stavět nikdo jiný; čtení VERSION se hlídá jen u builderů
            // podání (jinde má jinou sémantiku — viz docblock třídy).
            $needles = ["createElement('Pisemnost')"];
            if (in_array($rel, self::SUBMISSION_BUILDERS, true) || str_contains($rel, 'Service/Tax/Return/')) {
                $needles[] = "VERSION'";
            }

            foreach ($needles as $needle) {
                if (!str_contains($code, $needle)) {
                    continue;
                }
                $line = $this->lineOf($code, $needle);
                $offenders[] = sprintf(
                    '%s:%d (%s) — %s',
                    $rel,
                    $line,
                    PhpSourceRegions::symbolAtLine($code, $line) ?? '(mimo symbol)',
                    $needle,
                );
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Vlastní obálka podání nebo čtení VERSION mimo SSOT:\n  %s\n\n"
                . 'Použij EpoEnvelope::create() / EpoEnvelope::appVersion().',
            implode("\n  ", $offenders),
        ));
    }

    /** Každý builder podání musí obálku skutečně brát ze SSOT. */
    public function testEverySubmissionBuilderUsesTheEnvelope(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $missing = [];

        foreach (self::SUBMISSION_BUILDERS as $rel) {
            $path = $srcDir . '/' . $rel;
            if (!is_file($path)) {
                $missing[] = $rel . ' — soubor neexistuje, aktualizuj guard';
                continue;
            }
            if (!str_contains((string) file_get_contents($path), 'EpoEnvelope::create')) {
                $missing[] = $rel . ' — nevolá EpoEnvelope::create()';
            }
        }

        self::assertSame([], $missing, sprintf(
            "Builder podání si staví obálku sám:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /**
     * Prázdný `VERSION` musí dát `null`, ne prázdný řetězec — na tomhle se pět kopií
     * rozešlo. Volající mají fallback `'0'` a ten se má uplatnit i tehdy, když soubor
     * existuje, ale je prázdný.
     */
    public function testAppVersionReturnsNullRatherThanEmptyString(): void
    {
        $version = EpoEnvelope::appVersion();

        self::assertNotSame('', $version, 'Prázdná verze se musí hlásit jako null, ne jako "".');
        if ($version !== null) {
            self::assertNotSame('', trim($version), 'Verze nesmí být jen bílé znaky.');
        }
    }

    /** Obálka musí mít očekávaný tvar — jinak by ji volající museli dorovnávat sami. */
    public function testEnvelopeShape(): void
    {
        [$dom, $root] = EpoEnvelope::create('DPHDP3', '03.01');

        self::assertSame('DPHDP3', $root->nodeName);
        self::assertSame('03.01', $root->getAttribute('verzePis'));

        $pisemnost = $root->parentNode;
        self::assertNotNull($pisemnost);
        self::assertSame('Pisemnost', $pisemnost->nodeName);
        self::assertSame(EpoEnvelope::NAZEV_SW, $pisemnost->getAttribute('nazevSW'));
        self::assertNotSame('', $pisemnost->getAttribute('verzeSW'), 'verzeSW nesmí být prázdné.');
        self::assertSame($dom, $root->ownerDocument);
    }

    /** Explicitně předaná verze má přednost před souborem (DPFO/DPPO ji dostávají v meta). */
    public function testExplicitVersionWins(): void
    {
        [, $root] = EpoEnvelope::create('DPPDP9', '09.01', '9.9.9');

        self::assertSame('9.9.9', $root->parentNode?->getAttribute('verzeSW'));
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
