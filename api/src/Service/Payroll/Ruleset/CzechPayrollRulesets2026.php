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
                'credit.taxpayer.monthly' => PayrollRuleValue::moneyMinor(257_000),
                'dpp.withholding.maximum' => PayrollRuleValue::moneyMinor(1_199_900),
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
            [self::socialSecurity()],
            [
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(4_896_700),
                'wage_compensation.reduction_boundaries' => PayrollRuleValue::manualReview(
                    'Hourly reduction boundaries and absence-specific methods need a dedicated sourced fixture.',
                ),
            ],
            $technicalReview,
        );
    }

    private static function enforcementDeductions(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.enforcement-deductions.v1',
            PayrollRulesetDomain::EnforcementDeductions,
            PayrollRulesetCapability::ManualReview,
            [self::civilProcedure()],
            [
                'calculation' => PayrollRuleValue::manualReview(
                    'Protected amounts, dependants and priority ordering require a separately reviewed legal fixture.',
                ),
            ],
            $technicalReview,
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
