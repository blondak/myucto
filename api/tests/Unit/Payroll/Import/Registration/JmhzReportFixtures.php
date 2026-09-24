<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;

/**
 * Syntetická měsíční hlášení JMHZ „cizího" mzdového programu.
 *
 * XML nevzniká ručně psanou šablonou, ale SKUTEČNÝM serializérem aplikace
 * nad syntetickým normalizovaným dokumentem — kruhový test tak ověřuje, že
 * import je zrcadlem toho, co aplikace do hlášení píše. Všechny hodnoty jsou
 * vymyšlené; OIČ a rodná čísla procházejí kontrolou modulo 11.
 */
final class JmhzReportFixtures
{
    public const VENDOR = 'Syntetické mzdy';

    /**
     * Jedna osoba s jedním vztahem. Výchozí čísla drží daňovou identitu
     * 10298 − 10305 − 10304 = uplatněné slevy (6 000 − 2 163 − 1 267 = 2 570).
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    public static function person(array $o = []): array
    {
        $o += [
            'employment_id' => 101,
            'oic' => RegistrationXmlFixtures::oic(7),
            'id_ppv' => '200000000000000000101',
            'primary' => true,
            'wage' => 40_000,
            'taxable' => 40_000,
            'base' => 40_000,
            'computed' => 6_000,
            'after_credits' => 2_163,
            'bonus' => 0,
            'declaration' => true,
            'basic_credit' => 2_570,
            'ztp_p_credit' => null,
            'children' => [[
                'identity' => ['given_name' => 'Eliška', 'family_name' => 'Testovací', 'birth_date' => '2018-05-20'],
                'ztp_p' => false,
                'order' => '1',
            ]],
            'other_caregivers' => [],
            'child_monthly' => 1_267,
            'child_applied' => 1_267,
            'social_base' => 40_000,
            'social_discount' => null,
            'work_place' => 'Brno',
            'municipality' => '582786',
            'apz' => 'no',
            'functional' => 'no',
            'worked_millihours' => 168_000,
            'worked_days' => 21,
            'average_minor' => 23_810,
            'irregular' => 0,
            'withholding' => null,
            'insurance_from' => null,
            'insurance_to' => null,
            'standard_fund' => 168_000,
            'agreed_fund' => 168_000,
            'unworked' => [],
        ];
        $childCredit = null;
        if ($o['declaration'] && $o['children'] !== []) {
            $childCredit = [
                'monthly_credit_czk' => $o['child_monthly'],
                'applied_credit_czk' => $o['child_applied'],
                'other_household_caregiver' => $o['other_caregivers'] !== [],
                'other_household_caregivers' => $o['other_caregivers'],
                'children' => $o['children'],
            ];
        }

        return [
            'insurance_from' => $o['insurance_from'],
            'insurance_to' => $o['insurance_to'],
            'summary' => [
                'income_total_czk' => $o['wage'],
                'exempt_income_czk' => null,
                'employer_contributions_czk' => [],
                'net_income_czk' => (int) round($o['wage'] * 0.78),
                'deductions_recorded' => false,
                'employee_health_czk' => (int) ceil($o['wage'] * 0.045),
                'employer_health_czk' => (int) ceil($o['wage'] * 0.09),
                'employee_social_czk' => (int) ceil($o['social_base'] * 0.071),
                'employer_social_czk' => (int) ceil($o['social_base'] * 0.248),
                'taxpayer_declaration_signed' => $o['declaration'],
                'advance_tax_czk' => [
                    'base' => $o['base'],
                    'computed' => $o['computed'],
                    'after_credits' => $o['after_credits'],
                    'bonus' => $o['bonus'],
                    'taxable_income' => $o['taxable'],
                ],
                'withholding_tax_czk' => $o['withholding'],
                'tax_credits_czk' => [
                    'basic' => $o['declaration'] ? $o['basic_credit'] : null,
                    'disability_basic' => null,
                    'disability_extended' => null,
                    'ztp_p' => $o['declaration'] ? $o['ztp_p_credit'] : null,
                ],
                'child_credit' => $childCredit,
                'annual' => [],
            ],
            'employment' => [
                'employment_id' => $o['employment_id'],
                'primary' => $o['primary'],
                'identity' => [
                    'person_external_identifier' => $o['oic'],
                    'employment_external_identifier' => $o['id_ppv'],
                ],
                'selector' => ['scenario_key' => 'scenario_1', 'activity_code' => '1', 'relationship_detail_code' => '1'],
                'term' => [
                    'work_place' => $o['work_place'],
                    'jmhz_workplace_municipality_code' => $o['municipality'],
                    'jmhz_workplace_country_code' => 'CZ',
                    'jmhz_apz_contribution_status' => $o['apz'],
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => $o['functional'],
                    'jmhz_temporary_assignment_status' => 'no',
                ],
                'jmhz_default_interpretations' => null,
                'work_month' => ['jmhz_work_summary' => ['values' => $o['unworked'] + [
                    'standard_fund_millihours' => $o['standard_fund'],
                    'agreed_fund_millihours' => $o['agreed_fund'],
                    'weekly_work_centihours' => 4_000,
                    'evidence_days' => 30,
                    'worked_millihours' => $o['worked_millihours'],
                    'worked_days' => $o['worked_days'],
                    'unworked_total_millihours' => null,
                    'employee_obstacle_paid_millihours' => null,
                    'employer_obstacle_millihours' => null,
                ]]],
                'social_base' => [
                    'assessment_base_czk' => $o['social_base'],
                    'reported_income_czk' => $o['social_base'],
                    'paragraph5_letter' => 'a',
                ],
                'reports_social_contributions' => $o['primary'],
                'employee_social_discount' => $o['social_discount'] === null ? null : ['amount_czk' => $o['social_discount']],
                'part_time_discount' => null,
                'taxable_income_czk' => $o['taxable'],
                'earnings_by_attribute_czk' => [
                    '10328' => $o['wage'],
                    '10329' => $o['wage'] - $o['irregular'],
                    '10330' => 0,
                    '10331' => $o['irregular'],
                ],
                'average_hourly' => ['minor_units' => $o['average_minor'], 'scale' => 2],
            ],
        ];
    }

    /**
     * Hlášení za měsíc. Osoby se stejným `oic` a víc vztahy se sloučí do
     * jedné osoby (souběh); souhrnná data nese vztah s `primary = true`.
     *
     * @param list<array<string,mixed>> $people výstupy {@see person()}
     * @param array<string,mixed> $o
     */
    public static function report(array $people, int $year, int $month, array $o = []): string
    {
        $o += [
            'guid_seed' => 1,
            'filled_at' => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
                ->modify('+1 month +9 days')->format('Y-m-d') . 'T08:00:00Z',
            'type' => 'R',
        ];
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

        $byPerson = [];
        $formGuids = [];
        $after = 0;
        $bonus = 0;
        foreach ($people as $person) {
            $employment = $person['employment'];
            $insuranceFrom = $person['insurance_from'] ?? $monthStart;
            $insuranceTo = $person['insurance_to'] ?? $monthEnd;
            $employment['eldp'] = [
                'insurance_interval' => ['insurance_from' => $insuranceFrom, 'insurance_to' => $insuranceTo],
                'eldp_sections' => [[
                    'ordinal' => 1,
                    'code' => '1++',
                    'valid_from' => $insuranceFrom,
                    'valid_to' => $insuranceTo,
                    'insurance_days' => (int) substr($insuranceTo, 8, 2) - (int) substr($insuranceFrom, 8, 2) + 1,
                    'assessment_base_czk' => $employment['social_base']['assessment_base_czk'],
                    'excluded_days' => null,
                ]],
            ];
            $key = (string) $employment['identity']['person_external_identifier'];
            if (!isset($byPerson[$key])) {
                $byPerson[$key] = ['summary' => $person['summary'], 'employments' => []];
            }
            if ($employment['primary'] === true) {
                $byPerson[$key]['summary'] = $person['summary'];
                $after += $person['summary']['advance_tax_czk']['after_credits'];
                $bonus += $person['summary']['advance_tax_czk']['bonus'];
            }
            $byPerson[$key]['employments'][] = $employment;
            $formGuids[(int) $employment['employment_id']] = self::guid((int) $o['guid_seed'], (int) $employment['employment_id']);
        }

        $payload = [
            'schema_reference' => JmhzScenario1NormalizedDocument::SCHEMA_REFERENCE,
            'scope' => ['scenario_key' => 'scenario_1', 'submission_kind' => 'regular'],
            'header' => ['type' => 'R', 'variable_symbol' => '1234567890', 'month' => $month, 'year' => $year],
            'employer' => [
                'summary_totals' => ['advance_tax_after_credits' => $after, 'tax_bonus' => $bonus],
                'pvpoj' => ['values' => [
                    'pojistne' => [
                        'zakladZamestnavateleA' => 40_000,
                        'pojistneZamestnavateleA' => 9_920,
                        'pojistneZamestnavateleCelkem' => 9_920,
                        'pojistneZamestnance' => 2_840,
                        'pojistneCelkem' => 12_760,
                    ],
                    'pojistneUhrada' => 12_760,
                ]],
            ],
            'people' => array_values($byPerson),
        ];
        $xml = (new JmhzScenario1XmlSerializer())->serialize(
            new JmhzScenario1NormalizedDocument($payload),
            JmhzSubmissionEnvelope::create(
                self::guid((int) $o['guid_seed'], 0),
                $formGuids,
                (string) $o['filled_at'],
                self::VENDOR,
                '1.0',
            ),
        );
        if ($o['type'] === 'O') {
            $xml = str_replace(
                ['<typPodani>R</typPodani>', '<typFormulare>R</typFormulare>'],
                ['<typPodani>O</typPodani>', '<typFormulare>O</typFormulare>'],
                $xml,
            );
        }

        return $xml;
    }

    /** Syntetický UUIDv7 (jen tvar); `index` 0 je GUID podání, jinak vztah. */
    public static function guid(int $seed, int $index): string
    {
        return sprintf(
            '%08X-%04X-7%03X-8%03X-%012X',
            0x0195E2C4 + $seed,
            $seed & 0xFFFF,
            $index & 0xFFF,
            $seed & 0xFFF,
            $seed * 100_000 + $index,
        );
    }

    /** Opravné podání se stornem jedné součásti (formulář typu S bez těla). */
    public static function componentCancellation(int $year, int $month, int $guidSeed, int $employmentId, string $filledAt): string
    {
        $submission = self::guid($guidSeed, 0);
        $form = self::guid($guidSeed, $employmentId);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" verze="1.4.3">
              <VENDOR productName="Syntetické mzdy" productVersion="1.0"/>
              <hlavicka>
                <idPodani>{$submission}</idPodani>
                <typPodani>O</typPodani>
                <variabilniSymbol>1234567890</variabilniSymbol>
                <mesic>{$month}</mesic>
                <rok>{$year}</rok>
                <datumVyplneni>{$filledAt}</datumVyplneni>
                <balikPoradi>1</balikPoradi>
                <balikyPocet>1</balikyPocet>
                <formularePocetVBaliku>1</formularePocetVBaliku>
                <formularePocetCelkem>1</formularePocetCelkem>
              </hlavicka>
              <formulareOsob>
                <formularOsoby>
                  <hlavicka>
                    <idFormulare>{$form}</idFormulare>
                    <typFormulare>S</typFormulare>
                  </hlavicka>
                </formularOsoby>
              </formulareOsob>
            </jmhz>
            XML;
    }

    /** Stornující podání celého hlášení (bez formulářů). */
    public static function submissionCancellation(int $year, int $month, int $guidSeed, string $filledAt): string
    {
        $submission = self::guid($guidSeed, 0);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" verze="1.4.3">
              <hlavicka>
                <idPodani>{$submission}</idPodani>
                <typPodani>S</typPodani>
                <variabilniSymbol>1234567890</variabilniSymbol>
                <mesic>{$month}</mesic>
                <rok>{$year}</rok>
                <datumVyplneni>{$filledAt}</datumVyplneni>
              </hlavicka>
            </jmhz>
            XML;
    }
}
