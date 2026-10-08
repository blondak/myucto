<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlVerdict;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Kontroly katalogu 1.4.2.10, které formuláře scénářů 4 až 6 vymezují
 * výslovně:
 * - 36: „pro formuláře typu Vězeň (formVezen.xsd) je kontrola ignorována",
 * - 165: platí jen pro bezPriznaku, pestoun, cinnostKS a odložený příjem,
 * - 78: „nepostihuje dat. scénář pronájem mezinárodní síly",
 * - 79: u scénáře 12 musí být vyplněna jen 10321,
 * - 7 a 12: formulář vězně vyměřovací základ ani pojistné nenese.
 */
final class JmhzSpecialFormsControlTest extends TestCase
{
    public function testPrisonerFormIsValidAgainstPinnedSchema(): void
    {
        $this->assertSchemaValid($this->document($this->prisonerForm()));
    }

    public function testInternationalHireFormIsValidAgainstPinnedSchema(): void
    {
        $this->assertSchemaValid($this->document($this->internationalHireForm()));
    }

    public function testPrisonerOvertimeWithoutSurchargeIsIgnoredByControl36(): void
    {
        self::assertSame(
            [JmhzControlOutcome::NotApplicable],
            $this->outcomes(36, $this->document($this->prisonerForm())),
        );
        self::assertSame(
            [JmhzControlOutcome::Failed],
            $this->outcomes(36, $this->document($this->prisonerForm('bezPriznaku'))),
            'Mimo formulář vězně přesčas bez příplatku zůstává vadou.',
        );
    }

    public function testPrisonerSection18TotalWithoutBreakdownPassesControl165(): void
    {
        self::assertSame(
            [JmhzControlOutcome::NotApplicable],
            $this->outcomes(165, $this->document($this->prisonerForm())),
        );
    }

    public function testInternationalHireAnnualResultCarriesOnlyTheTotal(): void
    {
        $xml = $this->document($this->internationalHireForm());

        self::assertSame([JmhzControlOutcome::NotApplicable], $this->outcomes(78, $xml));
        self::assertSame([JmhzControlOutcome::Passed], $this->outcomes(79, $xml));
    }

    public function testPrisonerContributionsAreNotComparedWithTheInsurancePart(): void
    {
        $xml = $this->document($this->prisonerForm());

        self::assertSame([JmhzControlOutcome::NotEvaluable], $this->outcomes(12, $xml));
        self::assertSame([JmhzControlOutcome::NotEvaluable], $this->outcomes(7, $xml));
    }

    private function prisonerForm(string $body = 'vezen'): string
    {
        $form = <<<'XML'
                <formularOsoby>
                  <hlavicka>
                    <idFormulare>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10</idFormulare>
                    <typFormulare>R</typFormulare>
                    <primarniPpv>true</primarniPpv>
                  </hlavicka>
                  <form:vezen>
                    <form:identifikace>
                      <form:ikMpsv>1000000001</form:ikMpsv>
                      <form:idPpv>2000000000000000000001</form:idPpv>
                    </form:identifikace>
                    <form:souhrnDataZec>
                      <form:prijmy>
                        <form:zuctovanoCelkem>1000</form:zuctovanoCelkem>
                      </form:prijmy>
                      <form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>
                      <form:mzdaCista>
                        <form:mzdaCista>850</form:mzdaCista>
                        <form:srazkyZeMzdyEvidovany>false</form:srazkyZeMzdyEvidovany>
                      </form:mzdaCista>
                    </form:souhrnDataZec>
                    <form:pojisteni>
                      <form:trvani>
                        <form:pojisteniOd>2026-07-01</form:pojisteniOd>
                        <form:pojisteniDo>2026-07-31</form:pojisteniDo>
                      </form:trvani>
                      <form:eldpSeznam>
                        <form:eldp>
                          <form:kod>1++</form:kod>
                          <form:platnostOd>2026-07-01</form:platnostOd>
                          <form:platnostDo>2026-07-31</form:platnostDo>
                          <form:pocetDnu>31</form:pocetDnu>
                          <form:vymerovaciZaklad>1000</form:vymerovaciZaklad>
                          <form:vylouceneDny>
                            <form:vylouceneDobyCelkem>2</form:vylouceneDobyCelkem>
                            <form:docasNeschopnost>2</form:docasNeschopnost>
                            <form:penezitaPomocMaterstvi>0</form:penezitaPomocMaterstvi>
                            <form:vyloucenePar18>2</form:vyloucenePar18>
                          </form:vylouceneDny>
                        </form:eldp>
                      </form:eldpSeznam>
                      <form:slevaZamestnance>
                        <form:slevaZamestnanceEvidovana>false</form:slevaZamestnanceEvidovana>
                        <form:slevaZamestnanceOvoZelEvidovana>false</form:slevaZamestnanceOvoZelEvidovana>
                      </form:slevaZamestnance>
                    </form:pojisteni>
                    <form:prubehZamestnani>
                      <form:odpracovaneHodiny>
                        <form:pocet>160.000</form:pocet>
                        <form:rozpad>
                          <form:prescas>8.000</form:prescas>
                        </form:rozpad>
                      </form:odpracovaneHodiny>
                    </form:prubehZamestnani>
                    <form:prijem>
                      <form:dan>
                        <form:zakladDane>1000</form:zakladDane>
                      </form:dan>
                    </form:prijem>
                    <form:mzda/>
                  </form:vezen>
                </formularOsoby>
            XML;

        return str_replace(['<form:vezen>', '</form:vezen>'], ["<form:{$body}>", "</form:{$body}>"], $form);
    }

    private function internationalHireForm(): string
    {
        return <<<'XML'
                <formularOsoby>
                  <hlavicka>
                    <idFormulare>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10</idFormulare>
                    <typFormulare>R</typFormulare>
                    <primarniPpv>true</primarniPpv>
                  </hlavicka>
                  <form:mezinarodniPronajemSily>
                    <form:identifikace>
                      <form:ikMpsv>1000000001</form:ikMpsv>
                      <form:idPpv>2000000000000000000001</form:idPpv>
                    </form:identifikace>
                    <form:souhrnDataZec>
                      <form:prijmy>
                        <form:zuctovanoCelkem>1000</form:zuctovanoCelkem>
                      </form:prijmy>
                      <form:zalohaNaDan>
                        <form:zakladDane>1000</form:zakladDane>
                        <form:vypoctenaZaloha>150</form:vypoctenaZaloha>
                        <form:danZalohaPoSleve>150</form:danZalohaPoSleve>
                      </form:zalohaNaDan>
                      <form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>
                      <form:rocniUhrny>
                        <form:rocniZuctovaniProvedeno>true</form:rocniZuctovaniProvedeno>
                        <form:vysledekRocnihoZuctovani>
                          <form:preplatekRok>500</form:preplatekRok>
                        </form:vysledekRocnihoZuctovani>
                      </form:rocniUhrny>
                    </form:souhrnDataZec>
                    <form:prijem>
                      <form:dan>
                        <form:zakladDane>1000</form:zakladDane>
                      </form:dan>
                    </form:prijem>
                  </form:mezinarodniPronajemSily>
                </formularOsoby>
            XML;
    }

    private function document(string $form): string
    {
        return JmhzXmlSample::document($form);
    }

    /** @return list<JmhzControlOutcome> */
    private function outcomes(int $controlId, string $xml): array
    {
        $evaluator = new JmhzScenario1ControlEvaluator(
            JmhzControlSourceCatalog::load()->parameters(),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
        );

        return array_map(
            static fn (JmhzControlVerdict $verdict): JmhzControlOutcome => $verdict->outcome,
            $evaluator->evaluate(
                $controlId,
                JmhzAttributeProjection::fromXml($xml),
                new JmhzControlContext('2026-08-14'),
            ),
        );
    }

    private function assertSchemaValid(string $xml): void
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)
            && $dom->schemaValidate((new JmhzSchemaCatalog())->entryPoint()['path']);
        $messages = array_map(
            static fn (\LibXMLError $error): string => trim($error->message),
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode('; ', $messages));
    }
}
