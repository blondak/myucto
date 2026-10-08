<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlVerdict;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola 59 „Vyměřovací základ s podmínkami" (katalog MH 1.4.2.10). Dřív ji
 * evaluátor vedl jako nevyhodnotitelnou s důvodem, že hlášení vyloučené doby
 * (10357) nenese — to už dávno neplatí, takže chybný základ prošel až do DIS.
 * Hodnoty jsou smyšlené.
 */
final class JmhzScenario1ControlEvaluatorBaseConditionsTest extends TestCase
{
    public function testCorrectMonthPassesControl59(): void
    {
        self::assertSame([JmhzControlOutcome::Passed], $this->outcomes(JmhzXmlSample::minimal()));
    }

    /** Část 2: všechny dny vyloučenou dobou a základ — chyba. */
    public function testWhollyExcludedMonthWithBaseFailsPart2(): void
    {
        $verdicts = $this->verdicts(self::section('1++', '2026-07-01', '2026-07-31', 31, 1000, excluded: 31));

        self::assertSame([JmhzControlOutcome::Failed], self::outcomesOf($verdicts));
        self::assertStringContainsString('2. část', (string) $verdicts[0]->message);
    }

    public function testWhollyExcludedMonthWithZeroBasePasses(): void
    {
        self::assertSame(
            [JmhzControlOutcome::Passed],
            $this->outcomes(self::section('1++', '2026-07-01', '2026-07-31', 31, 0, excluded: 31)),
        );
    }

    /** Část 5: bez započtených dnů (měsíc „X") musí být základ 0. */
    public function testMonthWithoutDaysAndWithBaseFailsPart5(): void
    {
        $verdicts = $this->verdicts(self::section('1++', '2026-07-01', '2026-07-31', 0, 1000, excluded: 0));

        self::assertSame([JmhzControlOutcome::Failed], self::outcomesOf($verdicts));
    }

    /** Část 3: navazuje-li sekce s kódem D, předcházející sekce má základ 0. */
    public function testBaseBeforePensionAgeSectionFailsPart3(): void
    {
        $sections = self::eldp('1++', '2026-07-01', '2026-07-15', 15, 500)
            . self::eldp('1D+', '2026-07-16', '2026-07-31', 16, 500);
        $verdicts = $this->verdicts(JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001', eldp: $sections),
        ));

        self::assertSame([JmhzControlOutcome::Failed], self::outcomesOf($verdicts));
        self::assertStringContainsString('3. část', (string) $verdicts[0]->message);

        $moved = self::eldp('1++', '2026-07-01', '2026-07-15', 15, 0)
            . self::eldp('1D+', '2026-07-16', '2026-07-31', 16, 1000);
        self::assertSame([JmhzControlOutcome::Passed], $this->outcomes(JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001', eldp: $moved),
        )));
    }

    private static function section(string $code, string $from, string $to, int $days, int $base, int $excluded): string
    {
        return JmhzXmlSample::document(JmhzXmlSample::form(
            '1000000001',
            '2000000000000000000001',
            eldp: self::eldp($code, $from, $to, $days, $base, $excluded),
        ));
    }

    private static function eldp(string $code, string $from, string $to, int $days, int $base, ?int $excluded = null): string
    {
        $excludedXml = $excluded === null
            ? ''
            : "\n                        <form:vylouceneDny>\n"
                . "                          <form:vylouceneDobyCelkem>{$excluded}</form:vylouceneDobyCelkem>\n"
                . ($excluded > 0 ? "                          <form:docasNeschopnost>{$excluded}</form:docasNeschopnost>\n" : '')
                . '                        </form:vylouceneDny>';

        return <<<XML
                      <form:eldp>
                        <form:kod>{$code}</form:kod>
                        <form:platnostOd>{$from}</form:platnostOd>
                        <form:platnostDo>{$to}</form:platnostDo>
                        <form:pocetDnu>{$days}</form:pocetDnu>
                        <form:vymerovaciZaklad>{$base}</form:vymerovaciZaklad>{$excludedXml}
                      </form:eldp>

            XML;
    }

    /** @return list<JmhzControlVerdict> */
    private function verdicts(string $xml): array
    {
        $catalog = JmhzControlSourceCatalog::load();

        return (new JmhzScenario1ControlEvaluator(
            $catalog->parameters(),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
        ))->evaluate(59, JmhzAttributeProjection::fromXml($xml), new JmhzControlContext('2026-08-14'));
    }

    /** @return list<JmhzControlOutcome> */
    private function outcomes(string $xml): array
    {
        return self::outcomesOf($this->verdicts($xml));
    }

    /**
     * @param list<JmhzControlVerdict> $verdicts
     * @return list<JmhzControlOutcome>
     */
    private static function outcomesOf(array $verdicts): array
    {
        return array_map(static fn (JmhzControlVerdict $verdict): JmhzControlOutcome => $verdict->outcome, $verdicts);
    }
}
