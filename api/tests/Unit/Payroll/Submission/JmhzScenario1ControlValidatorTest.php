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
            self::assertSame(JmhzControlOutcome::Unimplemented, $gap->outcome);
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

        foreach ($evaluator->implementedControlIds() as $controlId) {
            $assigned = $parameters->keysForControl($controlId);
            foreach ($assigned as $key) {
                self::assertContains(
                    $key,
                    $declared[$controlId] ?? [],
                    "Kontrola {$controlId} neuvádí parametr {$key} z katalogu.",
                );
            }
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
