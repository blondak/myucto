<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlEvaluationReport;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlFinding;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlPassability;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use PHPUnit\Framework\TestCase;

final class JmhzScenario1ControlValidatorTest extends TestCase
{
    public function testCleanSubmissionHasNoFailedControl(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());

        self::assertSame([], array_map(
            static fn (JmhzControlFinding $finding): int => $finding->controlId,
            $report->blocking(),
        ));
        self::assertSame([], $report->warnings());
        self::assertGreaterThan(30, $report->counts()[JmhzControlOutcome::Passed->value]);
    }

    /**
     * Nejdůležitější vlastnost celé vrstvy: nedodělané pokrytí katalogu se
     * nesmí tvářit jako zelený výsledek. Dokud zbývá nepropustná kontrola,
     * kterou na podání umíme vztáhnout a neumíme vyhodnotit, podání není
     * připravené k odeslání.
     */
    public function testUnimplementedBlockingControlKeepsSubmissionUnready(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());

        if ($report->coverageGaps() === []) {
            self::assertTrue($report->submittable());

            return;
        }
        self::assertFalse($report->submittable());
        foreach ($report->coverageGaps() as $gap) {
            self::assertContains(
                $gap->outcome,
                [JmhzControlOutcome::Unimplemented, JmhzControlOutcome::Unverifiable],
            );
            self::assertSame(JmhzControlPassability::Blocking, $gap->passability);
        }
    }

    /**
     * Sazba se bere z parametrických konstant katalogu, ne z kódu. Pojistné
     * za zaměstnavatele je 24,8 % základu zaokrouhlených nahoru — 248 Kč
     * z 1 000 Kč projde, 247 Kč ne.
     */
    public function testEmployerInsuranceRateIsCheckedAgainstCatalogParameter(): void
    {
        $report = $this->validate(JmhzXmlSample::withPvpoj(<<<'XML'
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>247</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleCelkem>247</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>318</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>318</pvpoj:pojistneUhrada>
            XML));

        self::assertContains(8, $this->blockingIds($report));
        self::assertFalse($report->submittable());
    }

    public function testEmployerInsuranceTotalMustMatchTheSumOfRates(): void
    {
        $report = $this->validate(JmhzXmlSample::withPvpoj(<<<'XML'
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleCelkem>300</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>371</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>371</pvpoj:pojistneUhrada>
            XML));

        self::assertContains(11, $this->blockingIds($report));
    }

    public function testInsurancePayableMustMatchTotalAfterDiscounts(): void
    {
        $report = $this->validate(JmhzXmlSample::withPvpoj(<<<'XML'
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleCelkem>248</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>319</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>300</pvpoj:pojistneUhrada>
            XML));

        self::assertContains(4, $this->blockingIds($report));
    }

    public function testPersonIdentifierChecksumIsEnforced(): void
    {
        $report = $this->validate(str_replace(
            '<form:ikMpsv>1000000001</form:ikMpsv>',
            '<form:ikMpsv>1000000002</form:ikMpsv>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(37, $this->blockingIds($report));
    }

    public function testDuplicateEmploymentIdentifierIsReported(): void
    {
        $report = $this->validate(JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001')
                . JmhzXmlSample::form('1000000012', '2000000000000000000001', primary: false),
            formCount: 4,
        ));

        self::assertContains(253, $this->blockingIds($report));
    }

    /**
     * Nejvýš jedno primární PPV na osobu. Kontrola je v katalogu vedená jako
     * nevykonávaná vzdáleně, takže nesmí blokovat — musí ale být vidět.
     */
    public function testTwoPrimaryEmploymentsForOnePersonWarn(): void
    {
        $report = $this->validate(JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001')
                . JmhzXmlSample::form('1000000001', '2000000000000000000002'),
            formCount: 4,
        ));

        self::assertContains(260, array_map(
            static fn (JmhzControlFinding $finding): int => $finding->controlId,
            $report->warnings(),
        ));
        self::assertNotContains(260, $this->blockingIds($report));
    }

    public function testEldpValidityOrderingIsEnforced(): void
    {
        $report = $this->validate(str_replace(
            '<form:platnostDo>2026-07-31</form:platnostDo>',
            '<form:platnostDo>2026-06-30</form:platnostDo>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(100, $this->blockingIds($report));
    }

    /**
     * Katalog píše `10356 <= 10355 - 10354`, tedy rozdíl dat. Celý červenec má
     * ale 31 dnů pojištění, ne 30 — doslovné znění by neprošlo ani bezvadné
     * hlášení za celý měsíc, takže se interval počítá včetně krajních dnů.
     */
    public function testFullMonthOfInsuranceDaysPassesDespiteLiteralFormula(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());

        self::assertNotContains(134, $this->blockingIds($report));
    }

    public function testInsuranceDaysBeyondIntervalFail(): void
    {
        $report = $this->validate(str_replace(
            '<form:pocetDnu>31</form:pocetDnu>',
            '<form:pocetDnu>45</form:pocetDnu>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(134, $this->blockingIds($report));
    }

    public function testInsuranceIntervalOutsideReportedMonthFails(): void
    {
        $report = $this->validate(str_replace(
            '<form:pojisteniOd>2026-07-01</form:pojisteniOd>',
            '<form:pojisteniOd>2026-06-01</form:pojisteniOd>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(299, $this->blockingIds($report));
    }

    public function testPeriodBeforeStartOfSchemeFails(): void
    {
        $report = $this->validate(JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001'),
            month: '12',
            year: '2025',
        ));

        self::assertContains(31, $this->blockingIds($report));
        self::assertContains(131, $this->blockingIds($report));
    }

    /**
     * Podat lze až za uplynulý měsíc. Rozhoduje den vyhodnocení, který se
     * dodává zvenčí — kontrola nesmí sahat na systémové hodiny sama.
     */
    public function testUnfinishedPeriodIsRefused(): void
    {
        $report = $this->validate(
            JmhzXmlSample::minimal(),
            new JmhzControlContext('2026-07-15'),
        );

        self::assertContains(90, $this->blockingIds($report));
    }

    public function testGovTalkVariableSymbolIsNotEvaluatedWithoutEnvelope(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());
        $finding = $this->finding($report, 355);

        self::assertSame(JmhzControlOutcome::NotEvaluable, $finding->outcome);
    }

    public function testGovTalkVariableSymbolMismatchFails(): void
    {
        $report = $this->validate(
            JmhzXmlSample::minimal(),
            new JmhzControlContext('2026-08-14', '9999999999'),
        );

        self::assertContains(355, $this->blockingIds($report));
    }

    public function testGovTalkVariableSymbolMatchPasses(): void
    {
        $report = $this->validate(
            JmhzXmlSample::minimal(),
            new JmhzControlContext('2026-08-14', '1234567890'),
        );

        self::assertSame(JmhzControlOutcome::Passed, $this->finding($report, 355)->outcome);
    }

    /**
     * Kontroly proti registru ČSSZ se nesmí vydávat za splněné. Pro uživatele
     * je rozdíl mezi „ověřeno" a „ověří až ČSSZ" podstatný.
     */
    public function testRegistryControlsAreReportedAsNotEvaluable(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());

        foreach ([143, 261, 262, 263, 264, 326] as $controlId) {
            self::assertSame(
                JmhzControlOutcome::NotEvaluable,
                $this->finding($report, $controlId)->outcome,
                "Kontrola {$controlId} má být vedená jako lokálně neověřitelná.",
            );
        }
    }

    public function testControlWithoutAnyPresentAttributeIsNotApplicable(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());
        $projection = \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection
            ::fromXml(JmhzXmlSample::minimal());
        // Vlastní implementace smí prohlásit kontrolu za nedopadající i z jiného
        // důvodu (kontrola 132 se týká jen opravného hlášení), proto se testuje
        // jen odvození z přítomnosti atributů.
        $evaluator = new JmhzScenario1ControlEvaluator(
            JmhzControlSourceCatalog::load()->parameters(),
        );
        $notApplicable = array_values(array_filter(
            $report->findings,
            static fn (JmhzControlFinding $finding): bool
                => $finding->outcome === JmhzControlOutcome::NotApplicable
                && !$evaluator->handles($finding->controlId),
        ));

        self::assertNotSame([], $notApplicable);
        foreach ($notApplicable as $finding) {
            foreach ($finding->attributeIds as $attributeId) {
                self::assertFalse(
                    $projection->has($attributeId),
                    "Kontrola {$finding->controlId} je vedená jako nedopadající,"
                        . " ale atribut {$attributeId} v podání je.",
                );
            }
        }
    }

    public function testEveryCatalogControlGetsExactlyOneDisposition(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());
        $catalog = JmhzControlSourceCatalog::load();
        $seen = [];
        foreach ($report->findings as $finding) {
            $seen[$finding->controlId] = true;
        }

        self::assertSame(
            array_keys($catalog->definitions()),
            array_keys($seen),
        );
    }

    /**
     * Guard proti tichému přesunu sazby mezi kontrolami: každá parametrická
     * konstanta, kterou katalog přiřadil implementované kontrole, musí být
     * v implementaci vědomě uvedená.
     */
    public function testImplementedControlsDeclareEveryParameterTheCatalogAssignsThem(): void
    {
        $catalog = JmhzControlSourceCatalog::load();
        $parameters = $catalog->parameters();
        $evaluator = new JmhzScenario1ControlEvaluator($parameters);
        $declared = $evaluator->declaredParameterKeys();

        $unenforced = $evaluator->unenforcedParameterKeys();

        foreach ($evaluator->implementedControlIds() as $controlId) {
            $assigned = $parameters->keysForControl($controlId);
            foreach ($assigned as $key) {
                self::assertContains(
                    $key,
                    array_merge($declared[$controlId] ?? [], $unenforced[$controlId] ?? []),
                    "Kontrola {$controlId} neuvádí parametr {$key} z katalogu.",
                );
            }
        }
    }

    /**
     * Deklarovat sazbu a nepoužít ji je horší než ji nedeklarovat: guard nad
     * katalogem je pak spokojený, přestože kontrola z parametru nic nečte.
     * Vědomé neuplatnění proto musí být přiznané zvlášť a doložené odchylkou.
     */
    public function testDeclaredParametersAreActuallyReadByTheEvaluator(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4)
                . '/src/Service/Payroll/Submission/Jmhz/JmhzScenario1ControlEvaluator.php',
        );
        $evaluator = new JmhzScenario1ControlEvaluator(
            JmhzControlSourceCatalog::load()->parameters(),
        );

        foreach ($evaluator->declaredParameterKeys() as $controlId => $keys) {
            foreach ($keys as $key) {
                self::assertStringContainsString(
                    "'{$key}'",
                    $source,
                    "Kontrola {$controlId} deklaruje parametr {$key}, ale nikde ho nečte.",
                );
            }
        }
        foreach ($evaluator->unenforcedParameterKeys() as $controlId => $keys) {
            self::assertArrayHasKey(
                $controlId,
                $evaluator->documentedDeviations(),
                "Neuplatněný parametr u kontroly {$controlId} musí být přiznaný jako odchylka.",
            );
            self::assertNotSame([], $keys);
        }
    }

    public function testCatalogPinIsCarriedIntoTheReport(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());

        self::assertSame(JmhzControlSourceCatalog::CATALOG_KEY, $report->catalogKey);
        self::assertSame(
            JmhzControlSourceCatalog::MANIFEST_SHA256,
            $report->catalogManifestSha256,
        );
    }

    /**
     * Jediná kontrola, která spojí souhrn za zaměstnavatele s individualizovanými
     * součástmi. Bez ní projde podání, kde se odvod a jeho rozpad rozcházejí.
     */
    public function testEmployeeInsuranceMustMatchTheSumOfForms(): void
    {
        $report = $this->validate(str_replace(
            '<form:socialniPojisteni>71</form:socialniPojisteni>',
            '<form:socialniPojisteni>60</form:socialniPojisteni>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(12, $this->failedIds($report));
    }

    /**
     * Regrese na skutečné odmítnutí z testovacího prostředí ČSSZ: podání bez
     * vyměřovacího základu (10477) tam skončilo chybou 20315. Vykázané
     * pojistné se totiž porovnává se základem a chybějící základ se bere jako
     * nula — kontroly 118 i 315 proto musí padnout i u nás, jinak by lokální
     * brána pustila dál něco, co ČSSZ vrátí.
     */
    public function testInsuranceWithoutAssessmentBaseIsRefusedLikeCsszDoes(): void
    {
        $sample = JmhzXmlSample::minimal();
        $stripped = preg_replace(
            '~\s*<form:vymerovaciZaklad>.*?</form:vymerovaciZaklad>~s',
            '',
            $sample,
        );
        self::assertNotSame($sample, $stripped);
        $report = $this->validate((string) $stripped);

        $failed = $this->failedIds($report);
        self::assertContains(118, $failed);
        self::assertContains(315, $failed);
    }

    /**
     * Sazba se bere z katalogu k prvnímu dni období, ne z literálu — a rozdíl
     * o korunu je vada, ne zaokrouhlení.
     */
    public function testInsuranceThatDoesNotMatchTheRateIsRefused(): void
    {
        $report = $this->validate(str_replace(
            '<form:castkaOdvodPojistneho>1000</form:castkaOdvodPojistneho>',
            '<form:castkaOdvodPojistneho>1001</form:castkaOdvodPojistneho>',
            JmhzXmlSample::minimal(),
        ));

        $failed = $this->failedIds($report);
        self::assertContains(118, $failed);
        self::assertContains(315, $failed);
    }

    public function testCreditsWithoutSignedDeclarationAreRefused(): void
    {
        $report = $this->validate(str_replace(
            '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>',
            '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>'
                . "\n<form:prohlaseniPoplatnikaDane><form:zakladniSleva>2570</form:zakladniSleva>"
                . '</form:prohlaseniPoplatnikaDane>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(244, $this->failedIds($report));
    }

    /**
     * Vykázaná nula slevu neuplatňuje. Katalog zakazuje „nabývat hodnot",
     * ne uvést nulu — jinak by neprošel ani zaměstnanec bez prohlášení.
     */
    public function testZeroTaxBonusWithoutDeclarationIsAccepted(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());

        self::assertNotContains(244, $this->failedIds($report));
    }

    public function testSummaryDataOnNonPrimaryEmploymentIsRefused(): void
    {
        $report = $this->validate(JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001')
                . JmhzXmlSample::form('1000000012', '2000000000000000000002', primary: false),
            formCount: 4,
        ));

        self::assertContains(248, $this->failedIds($report));
    }

    public function testDeclaredFormCountMustMatchReality(): void
    {
        $report = $this->validate(JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001'),
            formCount: 9,
        ));

        self::assertContains(235, $this->failedIds($report));
        self::assertContains(227, $this->failedIds($report));
    }

    public function testPackageOrdinalBeyondPackageCountFails(): void
    {
        $report = $this->validate(str_replace(
            '<balikPoradi>1</balikPoradi>',
            '<balikPoradi>3</balikPoradi>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(84, $this->failedIds($report));
    }

    public function testEldpCodeMustComeFromThePinnedCodebook(): void
    {
        $report = $this->validate(str_replace(
            '<form:kod>1++</form:kod>',
            '<form:kod>9ZZ</form:kod>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(157, $this->failedIds($report));
    }

    /**
     * Kód ELDP je tří- až čtyřznakový a první pozice je druh činnosti o jednom
     * nebo dvou znacích. Pozice se proto počítají od konce, ne od začátku.
     */
    public function testFourCharacterEldpCodePositionsAreReadFromTheEnd(): void
    {
        $report = $this->validate(str_replace(
            '<form:kod>1++</form:kod>',
            '<form:kod>ZCV+</form:kod>',
            JmhzXmlSample::minimal(),
        ));

        self::assertNotContains(157, $this->failedIds($report));
        // Druhá pozice V omezuje započtené dny délkou intervalu, ne nulou.
        self::assertNotContains(135, $this->failedIds($report));
    }

    public function testWageBreakdownWithZeroWageIsRefused(): void
    {
        $report = $this->validate(str_replace(
            '<form:mzdaZuctovana>1000</form:mzdaZuctovana>',
            '<form:mzdaZuctovana>0</form:mzdaZuctovana>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(267, $this->failedIds($report));
    }

    public function testFormGuidMustBeUniqueWithinSubmission(): void
    {
        $report = $this->validate(JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001')
                . JmhzXmlSample::form('1000000012', '2000000000000000000002', primary: false),
            formCount: 4,
        ));

        self::assertContains(306, $this->failedIds($report));
    }

    public function testMissingSummaryLayerBreaksRegularStructure(): void
    {
        $xml = (string) preg_replace(
            '~<so:souhrn>.*?</so:souhrn>~s',
            '',
            JmhzXmlSample::minimal(),
        );
        $report = $this->validate($xml);

        self::assertContains(232, $this->failedIds($report));
    }

    /**
     * Kontroly mimo profil se nesmí vypnout natvrdo. Jakmile se rozhodný
     * atribut v podání objeví, musí se z nich stát viditelná mezera v pokrytí,
     * ne tichá výjimka.
     */
    public function testOutOfProfileControlBecomesACoverageGapWhenItsTriggerAppears(): void
    {
        $clean = $this->validate(JmhzXmlSample::minimal());
        self::assertSame(
            JmhzControlOutcome::NotApplicable,
            $this->finding($clean, 293)->outcome,
        );

        $withStudy = $this->validate(str_replace(
            '</form:mzda>',
            '</form:mzda>'
                . "\n<form:teoretickaPraktickaPriprava><form:obdobi>"
                . '<form:datumOd>2026-07-01</form:datumOd>'
                . '</form:obdobi></form:teoretickaPraktickaPriprava>',
            JmhzXmlSample::minimal(),
        ));

        self::assertSame(
            JmhzControlOutcome::Unimplemented,
            $this->finding($withStudy, 293)->outcome,
        );
    }

    /**
     * Kontroly 61 a 62 nedělají nic jiného než validaci proti XSD. Vykázat je
     * jako splněné smí jen ten, kdo ji opravdu provedl.
     */
    public function testSchemaControlsNeedProofThatSchemaValidationRan(): void
    {
        // Chybějící důkaz o validaci není rozhodnutí ČSSZ, ale mezera na naší
        // straně — musí proto blokovat odeslání, ne se jen tiše zaznamenat.
        $without = $this->validate(JmhzXmlSample::minimal());
        self::assertSame(
            JmhzControlOutcome::Unverifiable,
            $this->finding($without, 61)->outcome,
        );
        self::assertNotSame([], $without->coverageGaps());
        self::assertFalse($without->submittable());

        $with = $this->validate(
            JmhzXmlSample::minimal(),
            new JmhzControlContext('2026-08-14', null, true),
        );
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($with, 61)->outcome);
    }

    public function testCleanSubmissionIsSubmittableOnceSchemaValidationIsProven(): void
    {
        $report = $this->validate(
            JmhzXmlSample::minimal(),
            new JmhzControlContext('2026-08-14', null, true),
        );

        self::assertSame([], $report->blocking());
        self::assertSame([], $report->coverageGaps());
        self::assertTrue($report->submittable());
    }

    /**
     * ELDP sekce se skládá ze dvou úrovní: kód a počet dnů leží přímo v `eldp`,
     * odečítané doby o úroveň hlouběji. Seskupení podle přímého rodiče proto
     * jednu sekci rozpadlo na dvě skupiny — kontrola 309 pak nikdy neměla obě
     * hodnoty pohromadě a tiše procházela i tam, kde počet dnů neseděl.
     */
    public function testInsuranceDaysAgainstDeductedTimeSeesTheWholeEldpSection(): void
    {
        $report = $this->validate(str_replace(
            '<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>',
            '<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>'
                . "\n<form:odecitaneDny><form:odecitaneDobyCelkem>5</form:odecitaneDobyCelkem>"
                . '</form:odecitaneDny>',
            str_replace('<form:kod>1++</form:kod>', '<form:kod>TZ+</form:kod>', JmhzXmlSample::minimal()),
        ));

        self::assertContains(309, $this->failedIds($report));
    }

    /**
     * Druhá strana téhož rozpadu: kontrola 307 naopak falešně selhávala,
     * protože skupina s odečítanými dobami nikdy neobsahovala kód ELDP,
     * který přitom v téže sekci byl.
     */
    public function testEldpDetailIsNotReportedAsOrphanedWhenTheCodeIsPresent(): void
    {
        $report = $this->validate(str_replace(
            '<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>',
            '<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>'
                . "\n<form:vylouceneDny><form:vylouceneDobyCelkem>3</form:vylouceneDobyCelkem>"
                . '</form:vylouceneDny>',
            JmhzXmlSample::minimal(),
        ));

        self::assertNotContains(307, $this->failedIds($report));
    }

    /**
     * Brána u kontroly 103 stála na nepřítomnosti identifikace, tedy přesně na
     * tom porušení, které měla chytat. Evidované dočasné přidělení bez
     * identifikace uživatele tak procházelo jako „kontrola nedopadá".
     */
    public function testTemporaryAssignmentWithoutIdentificationIsRefused(): void
    {
        $report = $this->validate(str_replace(
            '<form:docasnePrideleniEvidovano>false</form:docasnePrideleniEvidovano>',
            '<form:docasnePrideleniEvidovano>true</form:docasnePrideleniEvidovano>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(103, $this->failedIds($report));
    }

    /**
     * Kontrola, jejíž vstupy v podání nejsou, se nesmí hlásit jako splněná.
     * „Prošlo" a „nebylo co číst" jsou dvě různé odpovědi.
     */
    public function testControlThatReadsNothingIsNotReportedAsPassed(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());

        // 23 porovnává neodpracované hodiny s dovolenou; první profil ani
        // jedno nevykazuje, takže kontrola nemá co číst.
        self::assertSame(
            JmhzControlOutcome::NotApplicable,
            $this->finding($report, 23)->outcome,
        );
    }

    /**
     * Zaměstnání malého rozsahu nemá ve slovníku 1.4.1.6 mapování na XSD,
     * takže se z podání nedá přečíst. Kontrola 133 se proto nesmí tvářit,
     * že něco ověřila.
     */
    public function testControlStandingOnAnUnmappedAttributeIsNotEvaluable(): void
    {
        $report = $this->validate(JmhzXmlSample::minimal());

        self::assertSame(
            JmhzControlOutcome::NotEvaluable,
            $this->finding($report, 133)->outcome,
        );
    }

    /**
     * Chybějící protějšek není nula. Vykázané pojistné k úhradě bez pojistného
     * celkem se nesmí odbýt jako „kontrola nedopadá" — je to přesně ten rozpor,
     * kvůli kterému kontrola existuje.
     */
    public function testHalfOfAPairIsAFindingNotAnExcuse(): void
    {
        $report = $this->validate(JmhzXmlSample::withPvpoj(<<<'XML'
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleCelkem>248</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>319</pvpoj:pojistneUhrada>
            XML));

        self::assertContains(4, $this->failedIds($report));
        self::assertContains(13, $this->failedIds($report));
    }

    /**
     * Odvod, který nedokládá žádná součást, je rozpor mezi pojistnou částí
     * a individualizovanou — a to je jediná kontrola, která je spojuje.
     */
    public function testEmployeeInsuranceWithoutAnySupportingFormFails(): void
    {
        $xml = (string) preg_replace(
            '~\s*<form:pojisteniZamestnanec>.*?</form:pojisteniZamestnanec>~s',
            '',
            JmhzXmlSample::minimal(),
        );

        self::assertContains(12, $this->failedIds($this->validate($xml)));
    }

    /**
     * Třetí pravidlo kontroly 135: mimo pozice P a V, s třetí pozicí „T"
     * a uvedenými odečtenými dobami je počet započtených dnů dán přesně.
     */
    public function testInsuranceDaysMustMatchTheIntervalReducedByDeductedTime(): void
    {
        $report = $this->validate(str_replace(
            '<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>',
            '<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>'
                . "\n<form:odecitaneDny><form:odecitaneDobyCelkem>5</form:odecitaneDobyCelkem>"
                . '</form:odecitaneDny>',
            str_replace('<form:kod>1++</form:kod>', '<form:kod>1DT</form:kod>', JmhzXmlSample::minimal()),
        ));

        self::assertContains(135, $this->failedIds($report));
    }

    /**
     * Ověřeno proti oficiálním příkladům ČSSZ: u nulového vyměřovacího základu
     * se odpovídající pojistné neuvádí vůbec. Trvat na dvojici by odmítalo
     * podání, která ČSSZ sama rozesílá jako vzor.
     */
    public function testZeroBaseMayOmitTheMatchingInsuranceAmount(): void
    {
        $report = $this->validate(JmhzXmlSample::withPvpoj(<<<'XML'
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:zakladZamestnavateleB>0</pvpoj:zakladZamestnavateleB>
                  <pvpoj:zakladZamestnavateleC>0</pvpoj:zakladZamestnavateleC>
                  <pvpoj:pojistneZamestnavateleCelkem>248</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>319</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>319</pvpoj:pojistneUhrada>
            XML));

        self::assertNotContains(10, $this->failedIds($report));
        self::assertNotContains(167, $this->failedIds($report));
    }

    /**
     * Nenulový základ bez pojistného je naopak vada — dopočítat ho nulou by
     * zakrylo neodvedené pojistné.
     */
    public function testNonZeroBaseWithoutInsuranceStillFails(): void
    {
        $report = $this->validate(JmhzXmlSample::withPvpoj(<<<'XML'
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:zakladZamestnavateleB>5000</pvpoj:zakladZamestnavateleB>
                  <pvpoj:pojistneZamestnavateleCelkem>248</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>319</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>319</pvpoj:pojistneUhrada>
            XML));

        self::assertContains(10, $this->failedIds($report));
    }

    /**
     * Katalog žádá, aby hodnota byla z číselníku CISOB, ne aby se název shodoval
     * na bajt: ČSSZ ve vlastním příkladu píše u kódu 554782 „Praha", zatímco
     * číselník má „Hlavní město Praha".
     */
    public function testMunicipalityIsCheckedByCodeNotByLiteralName(): void
    {
        $report = $this->validate(str_replace(
            ['<form:obec>Brno</form:obec>', '<form:kodObce>582786</form:kodObce>'],
            ['<form:obec>Praha</form:obec>', '<form:kodObce>554782</form:kodObce>'],
            JmhzXmlSample::minimal(),
        ));

        self::assertNotContains(152, $this->failedIds($report));
        self::assertNotContains(335, $this->failedIds($report));
    }

    public function testUnknownMunicipalityCodeStillFails(): void
    {
        $report = $this->validate(str_replace(
            '<form:kodObce>582786</form:kodObce>',
            '<form:kodObce>999999</form:kodObce>',
            JmhzXmlSample::minimal(),
        ));

        self::assertContains(152, $this->failedIds($report));
    }

    /**
     * Prázdný kontejner není hodnota. Bez tohohle rozlišení shodil
     * `<form:zalohaNaDan/>` celé promítnutí — a přitom se v oficiálních
     * příkladech ČSSZ běžně vyskytuje.
     */
    public function testEmptyContainerDoesNotBreakTheProjection(): void
    {
        $report = $this->validate(str_replace(
            '<form:odpracovaneDny>',
            '<form:odpracovaneHodiny/><form:odpracovaneDny>',
            JmhzXmlSample::minimal(),
        ));

        self::assertNotSame([], $report->findings);
    }

    /**
     * Přetečené datum se nesmí tiše posunout. `createFromFormat` z „2026-02-30"
     * udělá 2. březen, takže by se interval počítal z data, které v podání není.
     */
    public function testOverflowedDateDoesNotSilentlyShift(): void
    {
        $report = $this->validate(str_replace(
            '<form:pojisteniDo>2026-07-31</form:pojisteniDo>',
            '<form:pojisteniDo>2026-07-32</form:pojisteniDo>',
            JmhzXmlSample::minimal(),
        ));

        // Tvar data hlídá XSD, sem se takové datum v ostrém běhu nedostane.
        // Podstatné je, že se z něj nespočítá délka intervalu — kontrola 134
        // by jinak porovnávala počet dnů proti smyšlenému rozsahu.
        self::assertNotContains(134, $this->failedIds($report));
    }

    /**
     * Den vyhodnocení se bere v českém čase. V UTC by mezi půlnocí a druhou
     * hodinou letního času prvního dne měsíce vycházel ještě měsíc předchozí
     * a hlášení za právě skončený měsíc by se odmítlo jako nedokončené.
     */
    public function testEvaluationDayFollowsTheCzechCalendar(): void
    {
        $context = JmhzControlContext::today();
        $czechToday = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))
            ->format('Y-m-d');

        self::assertSame($czechToday, $context->evaluatedOn);
    }

    /** @return list<int> */
    private function failedIds(JmhzControlEvaluationReport $report): array
    {
        return array_map(
            static fn (JmhzControlFinding $finding): int => $finding->controlId,
            array_values(array_filter(
                $report->findings,
                static fn (JmhzControlFinding $finding): bool
                    => $finding->outcome === JmhzControlOutcome::Failed,
            )),
        );
    }

    private function validate(
        string $xml,
        ?JmhzControlContext $context = null,
    ): JmhzControlEvaluationReport {
        return JmhzScenario1ControlValidator::create()->validate(
            $xml,
            $context ?? new JmhzControlContext('2026-08-14'),
        );
    }

    /** @return list<int> */
    private function blockingIds(JmhzControlEvaluationReport $report): array
    {
        return array_map(
            static fn (JmhzControlFinding $finding): int => $finding->controlId,
            $report->blocking(),
        );
    }

    private function finding(JmhzControlEvaluationReport $report, int $controlId): JmhzControlFinding
    {
        foreach ($report->findings as $finding) {
            if ($finding->controlId === $controlId) {
                return $finding;
            }
        }

        self::fail("Kontrola {$controlId} v reportu chybí.");
    }
}
