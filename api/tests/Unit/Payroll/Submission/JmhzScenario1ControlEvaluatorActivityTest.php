<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlFinding;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlVerdict;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioSelectorResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Kontroly 155 a 343 stojí na druhu činnosti (10239). Ten podání nese jen
 * v jmenné větvi `identifikaceType`, tedy u zaměstnance, kterému ČSSZ ještě
 * nepřidělila OIČ ani ID PPV. Dokud je evaluátor neuměl, zastavilo každé
 * hlášení s novým nástupem odeslání mezerou v pokrytí.
 */
final class JmhzScenario1ControlEvaluatorActivityTest extends TestCase
{
    public function testActivityFromTheCodebookPassesControl155(): void
    {
        self::assertSame(
            [JmhzControlOutcome::Passed],
            $this->outcomes(155, self::byName('1')),
        );
    }

    /** `L` projde vzorem `druhCinnostiType`, ale v číselníku druhů činnosti není. */
    public function testActivityOutsideTheCodebookFailsControl155(): void
    {
        $verdicts = $this->verdicts(155, self::byName('L'));

        self::assertSame([JmhzControlOutcome::Failed], self::outcomesOf($verdicts));
        self::assertSame(0, $verdicts[0]->formOrdinal);
    }

    public function testControl155WithoutThePinnedCodebookIsAGapNotAPass(): void
    {
        self::assertSame(
            [JmhzControlOutcome::Unverifiable],
            $this->outcomes(155, self::byName('1'), codebooks: false),
        );
    }

    /** Větev OIČ + ID PPV druh činnosti nenese — obě kontroly nemají co číst. */
    public function testActivityControlsDoNotApplyWithoutActivityInTheSubmission(): void
    {
        foreach ([155, 343] as $controlId) {
            self::assertSame(
                [JmhzControlOutcome::NotApplicable],
                $this->outcomes($controlId, JmhzXmlSample::minimal()),
                "Kontrola {$controlId} nemá bez 10239 co vyhodnotit.",
            );
        }
    }

    public function testMatchingFormTypePassesControl343(): void
    {
        foreach ([
            ['T', 'bezPriznaku'],
            ['A', 'bezPriznaku'],
            ['15', 'bezPriznaku'],
            ['M', 'pestoun'],
            ['K', 'cinnostKS'],
            ['10', 'ozpTpp'],
            ['13', 'jinyPrijem'],
            ['12', 'mezinarodniPronajemSily'],
        ] as [$activity, $body]) {
            self::assertSame(
                [JmhzControlOutcome::Passed],
                $this->outcomes(343, self::byName($activity, $body)),
                "Druh činnosti {$activity} patří do formuláře {$body}.",
            );
        }
    }

    public function testMismatchedFormTypeFailsControl343(): void
    {
        foreach ([
            ['M', 'bezPriznaku'],
            ['K', 'bezPriznaku'],
            ['T', 'cinnostKS'],
            ['1', 'pestoun'],
            ['1', 'ozpTpp'],
            ['10', 'bezPriznaku'],
        ] as [$activity, $body]) {
            self::assertSame(
                [JmhzControlOutcome::Failed],
                $this->outcomes(343, self::byName($activity, $body)),
                "Druh činnosti {$activity} do formuláře {$body} nepatří.",
            );
        }
    }

    /**
     * U činností 1 až 9 volí mezi `bezPriznaku`, `cinnostKS` a `vezen` bližší
     * určení PPV (10502), které měsíční hlášení nenese. Vydat to za splněné by
     * tvrdilo shodu, kterou jsme neviděli.
     */
    public function testControl343CannotDecideActivities1To9WithoutRelationshipDetail(): void
    {
        foreach (['bezPriznaku', 'cinnostKS', 'vezen'] as $body) {
            self::assertSame(
                [JmhzControlOutcome::NotEvaluable],
                $this->outcomes(343, self::byName('1', $body)),
                "Formulář {$body} u činnosti 1 rozhoduje 10502.",
            );
        }
    }

    public function testDeferredIncomeFormFollowsItsOwnRule(): void
    {
        $withType = '<form:typ>A</form:typ>';

        self::assertSame(
            [JmhzControlOutcome::Passed],
            $this->outcomes(343, self::byName('1', 'odlozenyPrijem', $withType)),
        );
        self::assertSame(
            [JmhzControlOutcome::Failed],
            $this->outcomes(343, self::byName('10', 'odlozenyPrijem', $withType)),
            'Činnost 10 odložený příjem podávat nesmí.',
        );
        self::assertSame(
            [JmhzControlOutcome::Failed],
            $this->outcomes(343, self::byName('T', 'odlozenyPrijem')),
            'Odložený příjem musí mít vyplněný typ 10548.',
        );
    }

    /**
     * Příznak z E2E: hlášení s novým nástupem (jmenná větev, činnost 1) končilo
     * `dry_run_incomplete`, protože kontroly 155 a 343 byly mezerou v pokrytí.
     */
    public function testSubmissionWithANewHireIsSubmittable(): void
    {
        $xml = self::byName('1');
        $this->assertSchemaValid($xml);

        $report = JmhzScenario1ControlValidator::create(
            CzechPayrollRulesets2026::provider(),
        )->validate($xml, new JmhzControlContext('2026-08-14', null, true));

        self::assertSame([], array_map(
            static fn (JmhzControlFinding $finding): int => $finding->controlId,
            [...$report->blocking(), ...$report->coverageGaps()],
        ));
        self::assertTrue($report->submittable());
        $outcomes = [];
        foreach ($report->findings as $finding) {
            $outcomes[$finding->controlId][] = $finding->outcome;
        }
        self::assertSame([JmhzControlOutcome::Passed], $outcomes[155] ?? null);
        self::assertSame([JmhzControlOutcome::NotEvaluable], $outcomes[343] ?? null);
    }

    /**
     * Kontrola 343 a resolver scénáře drží tentýž koncept (druh činnosti →
     * typ formuláře) každý zvlášť, aby kontrola resolver opravdu ověřovala.
     * Tady se hlídá, že se nerozejdou: formulář, který resolver zvolí, musí
     * kontrola přijmout, a každý kód číselníku musí mít aspoň jeden formulář.
     */
    public function testControl343AgreesWithTheScenarioResolverOnEveryActivity(): void
    {
        $resolver = JmhzScenarioSelectorResolver::load();
        $codebook = new JmhzCodebookCatalog((new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        ));
        $checked = 0;
        foreach ($codebook->entries('druh_cinnosti') as $entry) {
            $activity = (string) $entry['item_code'];
            $allowed = JmhzScenario1ControlEvaluator::formTypesForActivity($activity);
            self::assertNotSame([], $allowed, "Druh činnosti {$activity} nemá formulář.");
            foreach ([null, '1', '2', '3'] as $detail) {
                foreach ([null, 'scenario_8'] as $manual) {
                    $resolution = $resolver->resolve($activity, $detail, $manual);
                    if (!$resolution['supported']) {
                        continue;
                    }
                    $entrypoint = (string) ($resolution['evidence']['xsd_entrypoint'] ?? '');
                    self::assertMatchesRegularExpression('/^form\w+\.xsd$/D', $entrypoint);
                    $body = lcfirst(substr($entrypoint, 4, -4));
                    self::assertContains(
                        $body,
                        $allowed,
                        "Resolver dává činnosti {$activity} formulář {$body}, kontrola 343 ho odmítá.",
                    );
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(44, $checked);
    }

    /**
     * Minimální podání přepnuté do jmenné větve `identifikaceType` — tak ho
     * serializér staví za zaměstnance bez OIČ a ID PPV.
     */
    private static function byName(
        string $activity,
        string $body = 'bezPriznaku',
        string $leading = '',
    ): string {
        $xml = (string) preg_replace(
            '~<form:ikMpsv>[^<]*</form:ikMpsv>\s*<form:idPpv>[^<]*</form:idPpv>~',
            '<form:prijmeni>Nováková</form:prijmeni>'
                . '<form:jmeno>Jana</form:jmeno>'
                . '<form:datumNarozeni>1990-04-12</form:datumNarozeni>'
                . '<form:datumNastupu>2026-03-01</form:datumNastupu>'
                . "<form:druhCinnosti>{$activity}</form:druhCinnosti>",
            JmhzXmlSample::minimal(),
            1,
        );

        return str_replace(
            ['<form:bezPriznaku>', '</form:bezPriznaku>'],
            ["<form:{$body}>{$leading}", "</form:{$body}>"],
            $xml,
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function verdicts(int $controlId, string $xml, bool $codebooks = true): array
    {
        $catalog = JmhzControlSourceCatalog::load();
        $evaluator = new JmhzScenario1ControlEvaluator(
            $catalog->parameters(),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            null,
            $codebooks
                ? new JmhzCodebookCatalog((new JmhzSpecPackageCatalog())->load(
                    JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                    JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                ))
                : null,
        );

        return $evaluator->evaluate(
            $controlId,
            JmhzAttributeProjection::fromXml($xml),
            new JmhzControlContext('2026-08-14'),
        );
    }

    /** @return list<JmhzControlOutcome> */
    private function outcomes(int $controlId, string $xml, bool $codebooks = true): array
    {
        return self::outcomesOf($this->verdicts($controlId, $xml, $codebooks));
    }

    /**
     * @param list<JmhzControlVerdict> $verdicts
     * @return list<JmhzControlOutcome>
     */
    private static function outcomesOf(array $verdicts): array
    {
        return array_map(
            static fn (JmhzControlVerdict $verdict): JmhzControlOutcome => $verdict->outcome,
            $verdicts,
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
