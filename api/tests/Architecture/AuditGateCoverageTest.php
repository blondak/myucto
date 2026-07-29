<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * CI brána nad AUDITNÍ VRSTVOU SAMOTNOU (fáze F8).
 *
 * Všechny ostatní guardy hlídají produkční kód. Tenhle hlídá je — protože nejlevnější
 * způsob, jak se zbavit červené v CI, je smazat guard, který ji způsobuje. Bez téhle
 * brány by taková změna prošla jako zelená a nikdo by se nedozvěděl, že obrana zmizela.
 *
 * Je to zobecnění poučení, které se v tomhle auditu opakovalo třikrát: guard, u kterého
 * se neověří, že padá, je horší než žádný. Tady jde o patro výš — guard, který
 * v repozitáři NENÍ, taky nic nehlásí.
 *
 * Test je záměrně statický a bez DB: musí projít i v CI, kde databáze není. Právě proto
 * tam většina integračních testů skipuje, takže by odstranění obrany zůstalo neviditelné.
 */
final class AuditGateCoverageTest extends TestCase
{
    /**
     * Guardy, které vznikly z auditu účetního jádra a nesmí zmizet. Klíč = soubor
     * v `tests/Architecture/`, hodnota = co hlídá (aby šlo z chyby poznat, o co se přišlo).
     *
     * @var array<string, string>
     */
    private const REQUIRED_GUARDS = [
        'DocumentBranchParityGuardsTest.php'  => 'parita vydané a přijaté větve dokladů',
        'PayablePredicateCoverageTest.php'    => 'DDKP nesmí nikde vystupovat jako nezaplacený závazek',
        'VatCoefficientFormulaGuardTest.php'  => '§ 37/2 — daň se počítá koeficientem, backend nepřebírá rozpad od klienta',
        'VatClaimDateSsotTest.php'            => '§ 73 — rozhodné datum odpočtu má jediný zdroj pravdy',
        'ExchangeRateGuardTest.php'           => 'kurz se nepoužije bez pojistky na CZK',
        'AdvanceCostPredicateParityTest.php'  => 'záměrná odlišnost nákladového predikátu záloh',
        'DphLineWhitelistGuardTest.php'       => 'whitelist řádků DPH přiznání se nerozejde s mapou',
        'ExplicitDiBindingArityTest.php'      => 'ruční DI binding dostane všechny závislosti',
        'LocaleParityGuardTest.php'           => 'cs.json a en.json mají identickou množinu klíčů',
    ];

    /** Spustitelné artefakty auditu mimo testovou sadu. */
    private const REQUIRED_ARTIFACTS = [
        'src/Service/Accounting/LedgerInvariantService.php' => 'invarianty účetního jádra (L3)',
        'src/Service/Report/CrossCheckSuite.php'            => 'křížové kontroly (L4)',
        'src/Support/Sql/PayablePredicate.php'              => 'SSOT závazkového predikátu',
        'tests/Support/PhpSourceRegions.php'                => 'symbolová granularita allowlistů guardů',
        // Guard z F1, který nežije v tests/Architecture — potřebuje DPH buildery,
        // takže je vedený jako unit test u nich. Hlídaný je proto tady, ne výše.
        'tests/Unit/Service/Report/EpoIdentificationSsotTest.php' => 'identifikace subjektu v EPO podáních',
        'tests/Invariants/LedgerInvariantsTest.php'         => 'invarianty nad obsahem deníku',
        'tests/Invariants/InvoiceMathFuzzTest.php'          => 'fuzz nad výpočtem DPH',
        'tests/Integration/Accounting/PostingMatrixTest.php' => 'matice typ dokladu × účetní režim',
        'bin/check-invariants.php'                          => 'CLI pro invarianty',
        'bin/cross-check.php'                               => 'CLI pro křížové kontroly',
    ];

    public function testEveryAuditGuardStillExists(): void
    {
        $dir = __DIR__;
        $missing = [];

        foreach (self::REQUIRED_GUARDS as $file => $what) {
            if (!is_file($dir . '/' . $file)) {
                $missing[] = $file . ' — ' . $what;
            }
        }

        self::assertSame([], $missing, sprintf(
            "Z auditní vrstvy zmizel guard:\n  %s\n\n"
                . "Pokud pravidlo přestalo platit, smaž i tenhle záznam a napiš proč.\n"
                . 'Guard, který v repozitáři není, nic nehlásí — a CI zůstane zelené.',
            implode("\n  ", $missing),
        ));
    }

    public function testEveryAuditArtifactStillExists(): void
    {
        $api = dirname(__DIR__, 2);
        $missing = [];

        foreach (self::REQUIRED_ARTIFACTS as $rel => $what) {
            if (!is_file($api . '/' . $rel)) {
                $missing[] = $rel . ' — ' . $what;
            }
        }

        self::assertSame([], $missing, sprintf(
            "Z auditní vrstvy zmizel spustitelný artefakt:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /**
     * Vrstva invariantů musí být v `phpunit.xml` zaregistrovaná jako testsuite —
     * jinak soubory v repozitáři jsou, ale nikdo je nespouští.
     */
    public function testInvariantsSuiteIsRegisteredInPhpunitConfig(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/phpunit.xml');

        self::assertStringContainsString(
            'tests/Invariants',
            $config,
            'Testsuite `Invariants` není v phpunit.xml — testy by existovaly, ale neběžely.',
        );
    }

    /**
     * CI musí auditní vrstvu skutečně spouštět. Kontroluje se workflow, ne jen
     * existence souborů: sada, kterou nikdo nepustí, je dokumentace, ne brána.
     */
    public function testCiWorkflowRunsTheAuditGate(): void
    {
        $workflow = dirname(__DIR__, 3) . '/.github/workflows/ci.yml';
        self::assertFileExists($workflow, 'CI workflow zmizel — brána by neexistovala.');

        $yaml = (string) file_get_contents($workflow);
        foreach (['--testsuite Architecture', '--testsuite Invariants'] as $needle) {
            self::assertStringContainsString(
                $needle,
                $yaml,
                "CI nespouští `{$needle}` jako samostatný krok — selhání guardu by se ztratilo "
                    . 'mezi ostatními testy a nebylo by z výpisu poznat, co se rozbilo.',
            );
        }
    }

    /**
     * Guard nesmí degradovat na prázdnou skořápku. Kdyby někdo nechal soubor, ale
     * vyhodil z něj obsah, testy výše by pořád prošly.
     */
    public function testGuardsAreNotEmptyShells(): void
    {
        $dir = __DIR__;
        $suspicious = [];

        foreach (array_keys(self::REQUIRED_GUARDS) as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue; // hlásí testEveryAuditGuardStillExists
            }
            $code = (string) file_get_contents($path);
            $assertions = preg_match_all('/self::assert\w+\(/', $code);
            $tests = preg_match_all('/function test\w+\(/', $code);

            if ($tests < 1 || $assertions < 1) {
                $suspicious[] = sprintf('%s — %d testů, %d asercí', $file, $tests, $assertions);
            }
        }

        self::assertSame([], $suspicious, sprintf(
            "Guard bez testu nebo bez asercí — vypadá, že hlídá, a nekontroluje nic:\n  %s",
            implode("\n  ", $suspicious),
        ));
    }
}
