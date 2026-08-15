<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzScenario1XmlSerializerTest extends TestCase
{
    public function testResolvedProfileProducesByteStableXmlValidAgainstPinnedSchema(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertSame($this->golden(), $result['xml']);
        self::assertSame(
            hash('sha256', $this->golden()),
            $result['sha256'],
        );
        self::assertSame('jmhz-1.4.3.4', $result['schema']['package_key']);
        self::assertSame('1.4.3', $result['schema']['data_version']);
    }

    public function testRepeatedSerializationIsIdentical(): void
    {
        $validator = new JmhzScenario1XmlValidator();

        self::assertSame(
            $validator->dryRun($this->resolution(), $this->envelope())['sha256'],
            $validator->dryRun($this->resolution(), $this->envelope())['sha256'],
        );
    }

    /**
     * XSD hlídá jen tvar. Že se element jmenuje tak, jak ho pojmenoval datový
     * slovník ČSSZ, ověří až porovnání proti připnutému manifestu — jinak by
     * překlep v názvu prošel, kdyby náhodou seděl na jiný platný element.
     */
    public function testEveryEmittedFormElementMatchesPinnedDictionaryPath(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4)
                    . '/resources/payroll/jmhz/dictionary-1.4.1.6/manifest.json',
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        $known = [];
        foreach ($manifest['payload']['dictionary_attributes'] as $attribute) {
            $mapping = $attribute['xsd_mapping'] ?? null;
            if (!is_string($mapping)) {
                continue;
            }
            $path = preg_replace('/\s*\(ID \d+\)$/D', '', $mapping);
            if (is_string($path)) {
                $known[$path] = true;
            }
        }

        $dom = new \DOMDocument();
        $dom->loadXML(
            (new JmhzScenario1XmlValidator())
                ->dryRun($this->resolution(), $this->envelope())['xml'],
            LIBXML_NONET | LIBXML_NOBLANKS,
        );
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('form', 'http://schemas.cssz.cz/JMHZ/form/1.0');
        $leaves = $xpath->query('//form:bezPriznaku//form:*[not(*)]');
        self::assertInstanceOf(\DOMNodeList::class, $leaves);
        self::assertGreaterThan(20, $leaves->length);
        $paths = [];
        foreach ($leaves as $leaf) {
            self::assertInstanceOf(\DOMElement::class, $leaf);
            $segments = [];
            for (
                $node = $leaf;
                $node instanceof \DOMElement && $node->localName !== 'bezPriznaku';
                $node = $node->parentNode
            ) {
                array_unshift($segments, $node->localName);
            }
            $paths[] = implode('.', $segments);
        }

        $unknown = array_values(array_filter(
            array_unique($paths),
            static fn (string $path): bool => !isset($known[$path]),
        ));
        self::assertSame([], $unknown);
    }

    /**
     * Zaměstnanec s podepsaným prohlášením je běžný případ, ne okrajový —
     * dokud rozpad slev nešel vykázat, blokoval se prakticky každý.
     */
    public function testSignedDeclarationWithCreditEmitsBreakdownAndStaysXsdValid(): void
    {
        $payload = $this->payload();
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['claimed_non_refundable_credits_minor_units'] = 257_000;
        $tax['applied_non_refundable_credits_minor_units'] = 257_000;
        $tax['claimed_non_refundable_credit_breakdown'] = ['taxpayer' => 257_000];
        $tax['advance_tax']['non_refundable_credits_minor_units'] = 257_000;
        $tax['advance_tax']['tax_before_credits_minor_units'] = 272_000;
        $tax['advance_tax']['tax_after_credits_minor_units'] = 15_000;
        unset($tax);
        $payload['people'][0]['employments'][0]['term']
            ['tax_declaration_signed'] = true;

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:prohlaseniPoplatnika>true</form:prohlaseniPoplatnika>',
            $result['xml'],
        );
        self::assertStringContainsString(
            '<form:zakladniSleva>2570</form:zakladniSleva>',
            $result['xml'],
        );
        self::assertStringNotContainsString('zakladniSlevaInvalidita12', $result['xml']);
    }

    public function testCreditWithoutSignedDeclarationIsRefused(): void
    {
        $payload = $this->payload();
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['claimed_non_refundable_credits_minor_units'] = 257_000;
        $tax['applied_non_refundable_credits_minor_units'] = 257_000;
        $tax['claimed_non_refundable_credit_breakdown'] = ['taxpayer' => 257_000];
        $tax['advance_tax']['non_refundable_credits_minor_units'] = 257_000;
        unset($tax);

        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
            self::fail('Sleva bez podepsaného prohlášení musela podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_xml_credit_without_declaration',
                $exception->validationCode,
            );
        }
    }

    public function testBlockedResolutionIsNeverSerialized(): void
    {
        $payload = $this->payload();
        unset($payload['ordinary_evidence']);

        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('Blokovaný dokument nelze serializovat');
        (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );
    }

    public function testUnverifiedTristateIsNotTreatedAsNo(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['term']
            ['jmhz_functional_benefits_status'] = 'unverified';

        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
            self::fail('Neověřený tri-state musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_attribute_unresolved', $exception->validationCode);
            self::assertStringContainsString('10247', $exception->getMessage());
        }
    }

    public function testMissingFrozenAttributeIsNeverFilledWithZero(): void
    {
        $payload = $this->payload();
        unset(
            $payload['people'][0]['employments'][0]['work_month']
                ['jmhz_work_summary']['values']['evidence_days'],
        );

        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
            self::fail('Chybějící zmrazený atribut musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_attribute_unresolved', $exception->validationCode);
            self::assertStringContainsString('10265', $exception->getMessage());
        }
    }

    public function testEldpSectionWithDaysRequiresCode(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0]['code'] = null;

        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
            self::fail('ELDP sekce s dny bez kódu musela podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_eldp_code_required', $exception->validationCode);
        }
    }

    public function testNonUuidV7GuidIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        JmhzSubmissionEnvelope::create(
            '0195e2c4-1a2b-4c3d-8e4f-5a6b7c8d9e0f',
            [101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    public function testSharedGuidBetweenSubmissionAndFormIsRefused(): void
    {
        $guid = '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F';

        $this->expectException(JmhzXmlException::class);
        JmhzSubmissionEnvelope::create(
            $guid,
            [101 => $guid],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    public function testNonCanonicalFilledAtIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        JmhzSubmissionEnvelope::create(
            '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F',
            [101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
            '2026-08-05 09:30:00',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    private function envelope(): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::create(
            '0195e2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f',
            [101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    private function resolution(): \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution
    {
        return $this->resolutionFor($this->payload());
    }

    /** @param array<string,mixed> $payload */
    private function resolutionFor(
        array $payload,
    ): \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution {
        $preparation = new JmhzVerifiedPreparationSnapshot(
            501,
            7,
            'test',
            401,
            301,
            1,
            '2026-07-01',
            '2026-07-31',
            'scenario_1',
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
            [],
            [
                'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
                'status' => 'source_ready',
                'issue_count' => 0,
                'issues' => [],
                'official_submission_supported' => false,
            ],
            $payload,
        );

        return (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(),
        );
    }

    private function pvpoj(): JmhzPvpojPreview
    {
        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            '2026-07',
            ['revision_input_hash' => str_repeat('d', 64)],
            [
                'pojistne' => [
                    'zakladZamestnavateleA' => 1_000,
                    'pojistneZamestnavateleA' => 248,
                    'pojistneZamestnavateleCelkem' => 248,
                    'pojistneZamestnance' => 71,
                    'pojistneCelkem' => 319,
                ],
                'pojistneUhrada' => 319,
            ],
            [['employee_id' => 11]],
        );
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'schema_reference' => 'payroll-jmhz-preparation-source.v5',
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => 7,
                'environment' => 'test',
                'run_id' => 401,
                'source_revision_id' => 301,
                'revision_no' => 1,
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'scenario_key' => 'scenario_1',
            ],
            'specification' => [
                'package_key' => 'synthetic-package',
                'spec_manifest_sha256' => str_repeat('a', 64),
                'scenario_catalog_key' => 'synthetic-scenarios',
                'scenario_manifest_sha256' => str_repeat('b', 64),
                'control_catalog_key' => 'synthetic-controls',
                'control_manifest_sha256' => str_repeat('c', 64),
            ],
            'source_revision' => [
                'input_snapshot_hash' => str_repeat('d', 64),
                'result_snapshot_hash' => str_repeat('e', 64),
                'ruleset_manifest_hash' => str_repeat('f', 64),
            ],
            'employer_summary' => [
                'employer' => ['identification_number' => '00000019'],
                'office' => ['social_security_variable_symbol' => '1234567890'],
            ],
            'ordinary_evidence' => [
                'attribute_values' => ['10116' => false, '10546' => false],
            ],
            'people' => [[
                'employee_id' => 11,
                'person_summary' => [
                    'totals' => ['jmhz_amount_minor' => 100_000],
                    'statutory' => [
                        'status' => 'calculated',
                        'health_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'employee_contribution_minor_units' => 4_500,
                            'employer_contribution_minor_units' => 9_000,
                        ],
                        'social_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'capped_assessment_base_minor_units' => 100_000,
                            'employee_contribution_minor_units' => 7_100,
                            'employer_contribution_minor_units' => 24_800,
                        ],
                        'income_tax' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'withholding_tax_minor_units' => 0,
                            'withholding_groups' => [],
                            'claimed_non_refundable_credits_minor_units' => 0,
                            'applied_non_refundable_credits_minor_units' => 0,
                            'claimed_non_refundable_credit_breakdown' => [],
                            'advance_tax' => [
                                'taxable_income_minor_units' => 100_000,
                                'rounded_tax_base_minor_units' => 100_000,
                                'tax_before_credits_minor_units' => 15_000,
                                'non_refundable_credits_minor_units' => 0,
                                'child_credit_minor_units' => 0,
                                'tax_after_credits_minor_units' => 15_000,
                                'tax_bonus_minor_units' => 0,
                            ],
                        ],
                        'net_pay' => [
                            'relationships' => [['relationship_id' => 'employment:101']],
                            'net_before_deductions_minor_units' => 73_400,
                            'deducted_minor_units' => 0,
                            'net_payable_minor_units' => 73_400,
                            'deductions' => [],
                        ],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'identity' => [
                        'person_external_identifier' => ['value' => '1000000001'],
                        'jmhz_employment_external_identifier' => [
                            'value' => '2000000000000000000001',
                        ],
                    ],
                    'employment' => ['is_primary' => true],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                        'tax_declaration_signed' => false,
                        'work_place' => 'Brno',
                        'jmhz_workplace_municipality_code' => '582786',
                        'jmhz_workplace_country_code' => 'CZ',
                        'jmhz_apz_contribution_status' => 'no',
                        'jmhz_functional_benefits_status' => 'no',
                        'jmhz_temporary_assignment_status' => 'no',
                    ],
                    'scenario_resolution' => ['scenario_key' => 'scenario_1'],
                    'eldp' => [
                        'confirmation' => ['in03_active' => false, 'in04_active' => false],
                        'insurance_interval' => [
                            'insurance_from' => '2026-07-01',
                            'insurance_to' => '2026-07-31',
                        ],
                        'eldp_sections' => [[
                            'ordinal' => 1,
                            'code' => '1++',
                            'valid_from' => '2026-07-01',
                            'valid_to' => '2026-07-31',
                            'insurance_days' => 31,
                            'assessment_base_czk' => 1_000,
                            'excluded_days' => null,
                            'deducted_days' => null,
                        ]],
                    ],
                    'work_month' => [
                        'jmhz_work_summary' => [
                            'derivation_version' => 'jmhz-work-month.v2',
                            'interactions' => ['IN07' => false, 'IN08' => false],
                            'values' => [
                                'standard_fund_millihours' => 184_000,
                                'agreed_fund_millihours' => 184_000,
                                'weekly_work_centihours' => 4_000,
                                'evidence_days' => 31,
                                'worked_millihours' => 184_000,
                                'unworked_total_millihours' => null,
                                'employee_obstacle_paid_millihours' => null,
                                'employer_obstacle_millihours' => null,
                            ],
                        ],
                    ],
                    'average_earning' => ['average_hourly_minor' => 27_550],
                    'earnings_by_attribute_minor' => [
                        '10328' => 100_000,
                        '10329' => 100_000,
                        '10330' => 0,
                        '10331' => 0,
                    ],
                    'insurance' => ['relationship_id' => 'employment:101'],
                ]],
            ]],
            'source_versions' => [
                'office_id' => 9,
                'employments' => [],
                'ordinary_evidence' => [
                    'id' => 601,
                    'source_manifest_sha256' => str_repeat('4', 64),
                    'snapshot_fingerprint' => str_repeat('5', 64),
                ],
            ],
            'readiness_issue_codes' => [],
            'readiness_issues' => [],
        ];
    }

    private function golden(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" xmlns:so="http://schemas.cssz.cz/JMHZ/souhrn/1.0" xmlns:pvpoj="http://schemas.cssz.cz/JMHZ/PVPOJ/1.0" xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0" verze="1.4.3">
              <VENDOR productName="MyÚčto.cz" productVersion="5.6.0"/>
              <hlavicka>
                <idPodani>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F</idPodani>
                <typPodani>R</typPodani>
                <variabilniSymbol>1234567890</variabilniSymbol>
                <mesic>7</mesic>
                <rok>2026</rok>
                <datumVyplneni>2026-08-05T09:30:00Z</datumVyplneni>
                <balikPoradi>1</balikPoradi>
                <balikyPocet>1</balikyPocet>
                <formularePocetVBaliku>3</formularePocetVBaliku>
                <formularePocetCelkem>3</formularePocetCelkem>
              </hlavicka>
              <so:souhrn>
                <so:danUdajeMesic>
                  <so:danZalohaPoSleve>150</so:danZalohaPoSleve>
                  <so:danBonus>0</so:danBonus>
                </so:danUdajeMesic>
              </so:souhrn>
              <pvpoj:PVPOJ>
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleCelkem>248</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>319</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>319</pvpoj:pojistneUhrada>
              </pvpoj:PVPOJ>
              <formulareOsob>
                <formularOsoby xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0">
                  <hlavicka>
                    <idFormulare>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10</idFormulare>
                    <typFormulare>R</typFormulare>
                    <primarniPpv>true</primarniPpv>
                  </hlavicka>
                  <form:bezPriznaku xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0">
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
                        <form:danBonus>0</form:danBonus>
                      </form:zalohaNaDan>
                      <form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>
                      <form:mzdaCista>
                        <form:mzdaCista>734</form:mzdaCista>
                        <form:srazkyZeMzdyEvidovany>false</form:srazkyZeMzdyEvidovany>
                      </form:mzdaCista>
                      <form:zdravPojZamestnavatel>
                        <form:zdravotniPojisteni>90</form:zdravotniPojisteni>
                      </form:zdravPojZamestnavatel>
                      <form:zdravPojZamestnanec>
                        <form:zdravotniPojisteni>45</form:zdravotniPojisteni>
                      </form:zdravPojZamestnanec>
                    </form:souhrnDataZec>
                    <form:pojisteni>
                      <form:trvani>
                        <form:pojisteniOd>2026-07-01</form:pojisteniOd>
                        <form:pojisteniDo>2026-07-31</form:pojisteniDo>
                      </form:trvani>
                      <form:vymerovaciZaklad>
                        <form:castkaOdvodPojistneho>1000</form:castkaOdvodPojistneho>
                      </form:vymerovaciZaklad>
                      <form:eldpSeznam>
                        <form:eldp>
                          <form:kod>1++</form:kod>
                          <form:platnostOd>2026-07-01</form:platnostOd>
                          <form:platnostDo>2026-07-31</form:platnostDo>
                          <form:pocetDnu>31</form:pocetDnu>
                          <form:vymerovaciZaklad>1000</form:vymerovaciZaklad>
                        </form:eldp>
                      </form:eldpSeznam>
                      <form:pojisteniZamestnanec>
                        <form:socialniPojisteni>71</form:socialniPojisteni>
                      </form:pojisteniZamestnanec>
                      <form:pojisteniZamestnavatel>
                        <form:socialniPojisteni>248</form:socialniPojisteni>
                      </form:pojisteniZamestnavatel>
                    </form:pojisteni>
                    <form:vykonavanaPozice>
                      <form:mistoVykonuPrace>
                        <form:obec>Brno</form:obec>
                        <form:kodObce>582786</form:kodObce>
                        <form:kodStatu>CZ</form:kodStatu>
                      </form:mistoVykonuPrace>
                      <form:uplatnujiPrispevekApz>false</form:uplatnujiPrispevekApz>
                      <form:funkcniPozitky>false</form:funkcniPozitky>
                      <form:docasnePrideleniEvidovano>false</form:docasnePrideleniEvidovano>
                      <form:fondPracovniDoby>
                        <form:stanovenyFond>184.000</form:stanovenyFond>
                        <form:sjednanyFond>184.000</form:sjednanyFond>
                        <form:stanovenaTydenniDoba>40.00</form:stanovenaTydenniDoba>
                      </form:fondPracovniDoby>
                    </form:vykonavanaPozice>
                    <form:prubehZamestnani>
                      <form:odpracovaneDny>
                        <form:dnyEvidencniStav>31</form:dnyEvidencniStav>
                      </form:odpracovaneDny>
                      <form:odpracovaneHodiny>
                        <form:pocet>184.000</form:pocet>
                      </form:odpracovaneHodiny>
                    </form:prubehZamestnani>
                    <form:prijem>
                      <form:dan>
                        <form:zakladDane>1000</form:zakladDane>
                      </form:dan>
                    </form:prijem>
                    <form:mzda>
                      <form:mzdaZuctovana>1000</form:mzdaZuctovana>
                      <form:mzdaRozpad>
                        <form:tarif>1000</form:tarif>
                        <form:odmenyPravidelne>0</form:odmenyPravidelne>
                        <form:odmenyNepravidelne>0</form:odmenyNepravidelne>
                      </form:mzdaRozpad>
                      <form:vydelek>
                        <form:vydelekPrumernyHod>275.50</form:vydelekPrumernyHod>
                      </form:vydelek>
                    </form:mzda>
                  </form:bezPriznaku>
                </formularOsoby>
              </formulareOsob>
            </jmhz>
            XML;
    }
}
