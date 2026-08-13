<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Payroll-only immutable fixture. The broader legacy TaxConstants table remains
 * an accounting fallback and is deliberately not a runtime input to this registry.
 */
final class CzechPayrollRulesets2026
{
    public const RETRIEVED_ON = '2026-08-03';

    /**
     * Integritní pin nezabavitelných částek (§ 278 o. s. ř., nařízení vlády
     * č. 595/2006 Sb.). Do MZ-14-W11 stejnou roli plnil `EXPECTED_HASH` nad
     * konstantami v `EnforcementRuleset2026`; hodnoty se ale kvůli tomu nedaly
     * změnit bez nasazení. Bydlí proto v registry jako každý jiný parametr
     * a pin hlídá už jen VÝCHOZÍ sadu z kódu — override z administrace má
     * vlastní `content_hash` a vlastní auditní stopu.
     */
    public const ENFORCEMENT_DEDUCTIONS_HASH =
        '353471b01f6be8b43da321dcaceef65743a4a5ae917bc8bb9ec5ba5d72951a42';

    public static function provider(): PayrollRulesetProvider
    {
        $technicalReview = new RulesetTechnicalReview(
            'myucto/payroll-ruleset-source-check',
            self::RETRIEVED_ON,
            'Official-source manifest, exact-value checks and byte-stability test suite; not a professional or legal approval.',
        );

        return new PayrollRulesetProvider([
            self::incomeTax($technicalReview),
            self::socialInsurance($technicalReview),
            self::healthInsurance($technicalReview),
            self::employmentThresholds($technicalReview),
            self::compensationAverages($technicalReview),
            self::travelAllowancesUntilMay($technicalReview),
            self::travelAllowancesFromJune($technicalReview),
            self::enforcementDeductions($technicalReview),
            self::deadlines($technicalReview),
            self::codebooks($technicalReview),
            self::submissions($technicalReview),
        ]);
    }

    private static function incomeTax(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.income-tax.v1',
            PayrollRulesetDomain::IncomeTax,
            PayrollRulesetCapability::Supported,
            [self::financialAdministration()],
            [
                'advance.high_rate' => PayrollRuleValue::rate('0.23'),
                'advance.high_threshold.monthly' => PayrollRuleValue::moneyMinor(14_690_100),
                'advance.low_rate' => PayrollRuleValue::rate('0.15'),
                'advance.rounding.base_above_100_czk' => PayrollRuleValue::text('ceil-to-100-czk'),
                'advance.rounding.base_up_to_100_czk' => PayrollRuleValue::text('ceil-to-1-czk'),
                'advance.rounding.result' => PayrollRuleValue::text('ceil-to-1-czk'),
                'bonus.minimum_amount.monthly' => PayrollRuleValue::moneyMinor(5_000),
                'bonus.minimum_income.monthly' => PayrollRuleValue::moneyMinor(1_120_000),
                'bonus.minimum_income.yearly' => PayrollRuleValue::moneyMinor(13_440_000),
                'credit.child.first.monthly' => PayrollRuleValue::moneyMinor(126_700),
                'credit.child.second.monthly' => PayrollRuleValue::moneyMinor(186_000),
                'credit.child.third_and_next.monthly' => PayrollRuleValue::moneyMinor(232_000),
                'credit.disability.basic.monthly' => PayrollRuleValue::moneyMinor(21_000),
                'credit.disability.extended.monthly' => PayrollRuleValue::moneyMinor(42_000),
                'credit.taxpayer.monthly' => PayrollRuleValue::moneyMinor(257_000),
                'credit.ztp_p.monthly' => PayrollRuleValue::moneyMinor(134_500),
                'dpp.withholding.maximum' => PayrollRuleValue::moneyMinor(1_199_900),
                'other.withholding.maximum' => PayrollRuleValue::moneyMinor(449_900),
                'withholding.rate' => PayrollRuleValue::rate('0.15'),
            ],
            $technicalReview,
        );
    }

    private static function socialInsurance(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.social-insurance.v1',
            PayrollRulesetDomain::SocialInsurance,
            PayrollRulesetCapability::Supported,
            [self::socialSecurity()],
            [
                'employee.discount.agriculture_dpp' => PayrollRuleValue::manualReview(
                    'Eligibility depends on the statutory agriculture conditions and must be reviewed.',
                ),
                'employee.discount.working_pensioner' => PayrollRuleValue::rate('0.065'),
                'employee.rate.ordinary' => PayrollRuleValue::rate('0.071'),
                'employer.discount.part_time' => PayrollRuleValue::rate('0.05'),
                'employer.rate.ordinary' => PayrollRuleValue::rate('0.248'),
                'employer.rate.rescue_and_company_fire_service' => PayrollRuleValue::manualReview(
                    'The 0.298 rate is official, but occupational classification requires manual review.',
                ),
                'employer.rate.risk_employment' => PayrollRuleValue::manualReview(
                    'The 0.278 rate is official, but risk-employment classification requires manual review.',
                ),
                'maximum_assessment_base.yearly' => PayrollRuleValue::moneyMinor(235_041_600),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_200_000),
                'participation.small_scale.minimum' => PayrollRuleValue::moneyMinor(450_000),
            ],
            $technicalReview,
        );
    }

    private static function healthInsurance(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.health-insurance.v1',
            PayrollRulesetDomain::HealthInsurance,
            PayrollRulesetCapability::Supported,
            [self::healthInsuranceMethod(), self::healthInsurance2026(), self::healthAgreements2026()],
            [
                'employee.rate' => PayrollRuleValue::rate('0.045'),
                'employer.rate' => PayrollRuleValue::rate('0.09'),
                'minimum_assessment_base.monthly' => PayrollRuleValue::moneyMinor(2_240_000),
                'minimum_contribution.monthly' => PayrollRuleValue::moneyMinor(302_400),
                'participation.dpc.minimum' => PayrollRuleValue::moneyMinor(450_000),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_200_000),
                'rounding.total' => PayrollRuleValue::text('ceil-to-1-czk'),
                'total.rate' => PayrollRuleValue::rate('0.135'),
            ],
            $technicalReview,
        );
    }

    private static function employmentThresholds(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.employment-thresholds.v1',
            PayrollRulesetDomain::EmploymentThresholds,
            PayrollRulesetCapability::Supported,
            [self::minimumWage(), self::socialSecurity()],
            [
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(4_896_700),
                'minimum_wage.hourly_40h_week' => PayrollRuleValue::moneyMinor(13_440),
                'minimum_wage.monthly_40h_week' => PayrollRuleValue::moneyMinor(2_240_000),
                'participation.dpc.minimum' => PayrollRuleValue::moneyMinor(450_000),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_200_000),
                'participation.small_scale.minimum' => PayrollRuleValue::moneyMinor(450_000),
            ],
            $technicalReview,
        );
    }

    private static function compensationAverages(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.compensation-averages.v1',
            PayrollRulesetDomain::CompensationAverages,
            PayrollRulesetCapability::ManualReview,
            [self::socialSecurity(), self::labourCode()],
            [
                'average_earning.minimum_worked_days' => PayrollRuleValue::integer(21),
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(4_896_700),
                'leave.agreement_weekly_minutes' => PayrollRuleValue::integer(1_200),
                'leave.entitlement_weeks.statutory_minimum' => PayrollRuleValue::integer(4),
                'leave.minimum_continuous_calendar_days' => PayrollRuleValue::integer(28),
                'leave.minimum_worked_week_multiples' => PayrollRuleValue::integer(4),
                'leave.weeks_per_year' => PayrollRuleValue::integer(52),
                'wage_compensation.compensation_rate' => PayrollRuleValue::rate('0.60'),
                'wage_compensation.hourly_boundary_1_minor' => PayrollRuleValue::moneyMinor(28_578),
                'wage_compensation.hourly_boundary_2_minor' => PayrollRuleValue::moneyMinor(42_858),
                'wage_compensation.hourly_boundary_3_minor' => PayrollRuleValue::moneyMinor(85_698),
                'wage_compensation.manual_review' => PayrollRuleValue::manualReview(
                    'Eligibility, published-shift completeness, benefit conflicts and partial-shift breaks require payroll review.',
                ),
                'wage_compensation.reduction_band_1_rate' => PayrollRuleValue::rate('0.90'),
                'wage_compensation.reduction_band_2_rate' => PayrollRuleValue::rate('0.60'),
                'wage_compensation.reduction_band_3_rate' => PayrollRuleValue::rate('0.30'),
                'wage_compensation.window_calendar_days' => PayrollRuleValue::integer(14),
            ],
            $technicalReview,
        );
    }

    /**
     * Tuzemské cestovní náhrady. Časová pásma, krácení za bezplatné jídlo a
     * zaokrouhlení plynou přímo ze zákoníku práce, peněžní sazby z vyhlášky
     * č. 573/2025 Sb. Novela č. 78/2026 Sb. zvedla od 1. 6. 2026 průměrnou cenu
     * motorové nafty, proto má rok dvě neprolínající se účinné verze.
     */
    private static function travelAllowancesUntilMay(
        RulesetTechnicalReview $technicalReview,
    ): PayrollRulesetVersion {
        return self::travelAllowances(
            'cz-payroll-2026.travel-allowances.v1',
            '2026.1.0',
            '2026-01-01',
            '2026-05-31',
            3_410,
            [self::labourCodeTravel(), self::travelAllowanceDecree2026()],
            $technicalReview,
        );
    }

    private static function travelAllowancesFromJune(
        RulesetTechnicalReview $technicalReview,
    ): PayrollRulesetVersion {
        return self::travelAllowances(
            'cz-payroll-2026.travel-allowances.v2',
            '2026.2.0',
            '2026-06-01',
            '2026-12-31',
            4_450,
            [
                self::labourCodeTravel(),
                self::travelAllowanceDecree2026(),
                self::travelAllowanceDieselAmendment2026(),
            ],
            $technicalReview,
        );
    }

    /** @param non-empty-list<RulesetSource> $sources */
    private static function travelAllowances(
        string $id,
        string $version,
        string $effectiveFrom,
        string $effectiveTo,
        int $dieselPerLitreMinor,
        array $sources,
        RulesetTechnicalReview $technicalReview,
    ): PayrollRulesetVersion {
        $parameters = [
            'foreign_travel' => PayrollRuleValue::manualReview(
                'Foreign business trips (foreign meal allowance, pocket money, currency conversion) are outside this ruleset and must be settled manually.',
            ),
            'fuel.average_price.diesel_per_litre' => PayrollRuleValue::moneyMinor($dieselPerLitreMinor),
            'fuel.average_price.electricity_per_kwh' => PayrollRuleValue::moneyMinor(720),
            'fuel.average_price.petrol_95_per_litre' => PayrollRuleValue::moneyMinor(3_470),
            'fuel.average_price.petrol_98_per_litre' => PayrollRuleValue::moneyMinor(3_900),
            'meal_allowance.band_1.free_meal_reduction_rate' => PayrollRuleValue::rate('0.70'),
            'meal_allowance.band_1.minimum' => PayrollRuleValue::moneyMinor(15_500),
            'meal_allowance.band_1.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(18_500),
            'meal_allowance.band_1.to_minutes' => PayrollRuleValue::integer(720),
            'meal_allowance.band_2.free_meal_reduction_rate' => PayrollRuleValue::rate('0.35'),
            'meal_allowance.band_2.minimum' => PayrollRuleValue::moneyMinor(23_600),
            'meal_allowance.band_2.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(28_400),
            'meal_allowance.band_2.to_minutes' => PayrollRuleValue::integer(1_080),
            'meal_allowance.band_3.free_meal_reduction_rate' => PayrollRuleValue::rate('0.25'),
            'meal_allowance.band_3.minimum' => PayrollRuleValue::moneyMinor(37_000),
            'meal_allowance.band_3.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(44_200),
            'meal_allowance.from_minutes' => PayrollRuleValue::integer(300),
            'meal_allowance.two_day_merge_rule' => PayrollRuleValue::text(
                'merge-two-calendar-days-when-more-favourable',
            ),
            'rounding.entitlement' => PayrollRuleValue::text('ceil-to-1-czk'),
            'vehicle.basic_compensation.car_per_km' => PayrollRuleValue::moneyMinor(590),
            'vehicle.basic_compensation.single_track_per_km' => PayrollRuleValue::moneyMinor(160),
        ];
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            $id,
            $version,
            PayrollRulesetDomain::TravelAllowances,
            $effectiveFrom,
            $effectiveTo,
            PayrollRulesetLifecycle::Reviewed,
            PayrollRulesetCapability::Supported,
            $sources,
            $parameters,
            null,
            $technicalReview,
        );
    }

    /**
     * Nezabavitelné částky a pravidla pořadí exekučních srážek. Životní minimum
     * i normativní náklady na bydlení mění vláda nařízením několikrát za rok,
     * proto jsou hodnoty administrovatelné a výchozí sada je jen pinnutý default.
     *
     * Odvozené částky (základ pro výpočet, nezabavitelná částka na povinného,
     * hranice plně zabavitelného zbytku) se ZÁMĚRNĚ vezou jako samostatné
     * parametry, ne jako runtime dopočet: nařízení je vyhlašuje přímo v korunách
     * a účetní je opisuje z tabulky. Jejich soulad se vstupy hlídá test.
     */
    private static function enforcementDeductions(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.enforcement-deductions.v1',
            PayrollRulesetDomain::EnforcementDeductions,
            PayrollRulesetCapability::Supported,
            [
                self::civilProcedure(),
                self::enforcementCalculator(),
                self::enforcementIncome(),
                self::insolvencyDebtRelief(),
                self::labourCodeDeductions(),
            ],
            [
                'debtor_share.denominator' => PayrollRuleValue::integer(100),
                'debtor_share.numerator' => PayrollRuleValue::integer(85),
                'dependant_share.denominator' => PayrollRuleValue::integer(4),
                'dependant_share.numerator' => PayrollRuleValue::integer(1),
                'employer_flat_fee.maximum.monthly' => PayrollRuleValue::moneyMinor(5_000),
                'employer_flat_fee.order_effective_from' => PayrollRuleValue::text('2022-01-01'),
                'energy_flat.monthly' => PayrollRuleValue::moneyMinor(230_000),
                'four_enforcement_rule.pension_exception_limit' =>
                    PayrollRuleValue::moneyMinor(108_900),
                'fully_attachable.factor_denominator' => PayrollRuleValue::integer(10),
                'fully_attachable.factor_numerator' => PayrollRuleValue::integer(19),
                'fully_attachable.threshold.monthly' => PayrollRuleValue::moneyMinor(3_152_100),
                'life_minimum.monthly' => PayrollRuleValue::moneyMinor(486_000),
                'normative_rent.monthly' => PayrollRuleValue::moneyMinor(943_000),
                'protected_amount.calculation_base.monthly' =>
                    PayrollRuleValue::moneyMinor(1_659_000),
                'protected_amount.debtor_base.monthly' => PayrollRuleValue::moneyMinor(1_410_150),
                'rounding.proportional_allocation' =>
                    PayrollRuleValue::text('floor_minor_units_then_largest_remainder'),
                'rounding.protected_total' => PayrollRuleValue::text('ceil_to_whole_czk_after_sum'),
                'rounding.thirds_base' =>
                    PayrollRuleValue::text('floor_to_whole_czk_divisible_by_three'),
            ],
            $technicalReview,
            self::ENFORCEMENT_DEDUCTIONS_HASH,
        );
    }

    private static function deadlines(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.deadlines.v1',
            PayrollRulesetDomain::Deadlines,
            PayrollRulesetCapability::ManualReview,
            [self::jmhzDocumentation()],
            [
                'submission_calendar' => PayrollRuleValue::manualReview(
                    'Deadlines depend on agenda, event, channel and transition rules and are not inferred.',
                ),
            ],
            $technicalReview,
        );
    }

    private static function codebooks(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.codebooks.v1',
            PayrollRulesetDomain::Codebooks,
            PayrollRulesetCapability::ManualReview,
            [self::jmhzDocumentation()],
            [
                'catalog_versions' => PayrollRuleValue::manualReview(
                    'Runtime codebooks must be imported with their own publication date and checksum.',
                ),
            ],
            $technicalReview,
        );
    }

    private static function submissions(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.submissions.v1',
            PayrollRulesetDomain::Submissions,
            PayrollRulesetCapability::ManualReview,
            [self::jmhzDocumentation()],
            [
                'dzmh.schema_version' => PayrollRuleValue::text('1.1'),
                'jmhz.schema_version' => PayrollRuleValue::text('1.4.3.4'),
                'prezec.schema_version' => PayrollRuleValue::text('1.2'),
                'regzec.schema_version' => PayrollRuleValue::text('1.4.0.4'),
                'regzeldopl.schema_version' => PayrollRuleValue::text('1.2'),
                'submission' => PayrollRuleValue::manualReview(
                    'Schema versions are recorded, but direct submission remains outside calculation capability.',
                ),
            ],
            $technicalReview,
        );
    }

    /**
     * @param non-empty-list<RulesetSource> $sources
     * @param non-empty-array<string, PayrollRuleValue> $parameters
     */
    private static function version(
        string $id,
        PayrollRulesetDomain $domain,
        PayrollRulesetCapability $capability,
        array $sources,
        array $parameters,
        RulesetTechnicalReview $technicalReview,
        ?string $expectedHash = null,
    ): PayrollRulesetVersion {
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            $id,
            '2026.1.0',
            $domain,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Reviewed,
            $capability,
            $sources,
            $parameters,
            null,
            $technicalReview,
            $expectedHash,
        );
    }

    private static function financialAdministration(): RulesetSource
    {
        return new RulesetSource(
            'fs-dependent-activity-2026',
            'Finanční správa: zaměstnanci a zaměstnavatelé, zdaňovací období 2026',
            'https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/zamestnanci-zamestnavatele/obecne-informace',
            self::RETRIEVED_ON,
        );
    }

    private static function socialSecurity(): RulesetSource
    {
        return new RulesetSource(
            'cssz-key-data-2026',
            'ČSSZ: Přehled nejdůležitějších údajů pro sociální zabezpečení v roce 2026',
            'https://www.cssz.cz/documents/20143/2872693/TZ__P%C5%99ehled%20nejd%C5%AFle%C5%BEit%C4%9Bj%C5%A1%C3%ADch%20%C3%BAdaj%C5%AF%20pro%20soci%C3%A1ln%C3%AD%20zabezpe%C4%8Den%C3%AD%20v%20roce%202026.pdf/3c0800f6-15d0-a4df-8a9e-35b93969b355',
            self::RETRIEVED_ON,
        );
    }

    private static function minimumWage(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-minimum-wage-2026',
            'MPSV: Minimální mzda v roce 2026',
            'https://ppropo.mpsv.cz/xviii1minimalnimzdaanejnizsiurov',
            self::RETRIEVED_ON,
        );
    }

    private static function healthInsuranceMethod(): RulesetSource
    {
        return new RulesetSource(
            'vzp-employer-method',
            'VZP: Plátce pojistného – zaměstnavatel',
            'https://www.vzp.cz/platci/informace/povinnosti-platcu-metodika/2-4-platce-pojistneho-zamestnavatel',
            self::RETRIEVED_ON,
        );
    }

    private static function healthInsurance2026(): RulesetSource
    {
        return new RulesetSource(
            'vzp-health-payments-2026',
            'VZP: Platby zdravotního pojištění v roce 2026',
            'https://www.vzp.cz/o-nas/tiskove-centrum/otazky-tydne/platby-zdravotniho-pojisteni-v-roce-2026',
            self::RETRIEVED_ON,
        );
    }

    private static function healthAgreements2026(): RulesetSource
    {
        return new RulesetSource(
            'vzp-dpp-dpc-2026',
            'VZP: Změny u odvodů na zdravotním pojištění pro DPP a DPČ 2026',
            'https://www.vzp.cz/o-nas/tiskove-centrum/otazky-tydne/zmeny-u-odvodu-na-zdravotnim-pojisteni-pro-dpp-a-dpc-2026',
            self::RETRIEVED_ON,
        );
    }

    private static function civilProcedure(): RulesetSource
    {
        return new RulesetSource(
            'e-sbirka-civil-procedure',
            'e-Sbírka: občanský soudní řád č. 99/1963 Sb.',
            'https://www.e-sbirka.cz/sb/1963/99',
            self::RETRIEVED_ON,
        );
    }

    private static function enforcementCalculator(): RulesetSource
    {
        return new RulesetSource(
            'justice-enforcement-calculator-2026',
            'Justice.cz: výpočet srážek ze mzdy pro rok 2026',
            'https://exekuce.justice.cz/vypocet-srazek-ze-mzdy/',
            self::RETRIEVED_ON,
        );
    }

    private static function enforcementIncome(): RulesetSource
    {
        return new RulesetSource(
            'justice-enforcement-income',
            'Justice.cz: srážky ze mzdy a jiných příjmů',
            'https://exekuce.justice.cz/srazky-ze-mzdy-a-jinych-prijmu/',
            self::RETRIEVED_ON,
        );
    }

    private static function insolvencyDebtRelief(): RulesetSource
    {
        return new RulesetSource(
            'justice-insolvency-debt-relief',
            'Justice.cz: oddlužení — jak ven z dluhové pasti',
            'https://insolvence.justice.cz/jak-ven-z-dluhove-pasti/oddluzeni/',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCodeDeductions(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-deductions',
            'MPSV: srážky z příjmu z pracovněprávního vztahu',
            'https://ppropo.mpsv.cz/pdf/XXI4Srazkyzprijmuzpracovnepravni.pdf',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCode(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-current',
            'MPSV: zákoník práce č. 262/2006 Sb., § 192, § 213 a § 351 až 362',
            'https://ppropo.mpsv.cz/zakon_262_2006',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCodeTravel(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-travel',
            'MPSV: zákoník práce č. 262/2006 Sb., § 156 až 189 (cestovní náhrady, časová pásma stravného a krácení za bezplatné jídlo)',
            'https://ppropo.mpsv.cz/zakon_262_2006',
            self::RETRIEVED_ON,
        );
    }

    private static function travelAllowanceDecree2026(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-travel-allowance-decree-2026',
            'MPSV: vyhláška č. 573/2025 Sb., o sazbě základní náhrady, stravném a průměrné ceně pohonných hmot pro rok 2026',
            'https://ppropo.mpsv.cz/Vyhlaska_573_2025',
            self::RETRIEVED_ON,
        );
    }

    private static function travelAllowanceDieselAmendment2026(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-travel-allowance-diesel-2026',
            'MPSV: vyhláška č. 78/2026 Sb. — změna průměrné ceny motorové nafty od 1. 6. 2026',
            'https://mpsv.gov.cz/rust-cen-nafty-se-promita-do-cestovnich-nahrad-mpsv-aktualizuje-vyhlasku',
            self::RETRIEVED_ON,
        );
    }

    private static function jmhzDocumentation(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-jmhz-documentation',
            'MPSV: technická dokumentace JMHZ',
            'https://developers.mpsv.cz/api-list/jednotne-mesicni-hlaseni-zamestnavatelu/documentation/4589f5c6-30e8-4e2b-b341-fe8481ad4e70',
            self::RETRIEVED_ON,
        );
    }
}
