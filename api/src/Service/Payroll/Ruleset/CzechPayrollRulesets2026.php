<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Payroll-only immutable fixture. The broader legacy TaxConstants table remains
 * an accounting fallback and is deliberately not a runtime input to this registry.
 *
 * ## Proč je dodaná sada rovnou `active`
 *
 * Do 8/2026 tady stálo `PayrollRulesetLifecycle::Reviewed` a zákazník musel
 * u každé z deseti domén projít `review → approve → activate`, jinak si mzdy
 * nespočítal vůbec. Rešerše patnácti českých mzdových systémů
 * (`private/LEGISLATIVNI-SADY-KONKURENCE.md`) ukázala, že schvalování
 * legislativních sazeb uživatelem NEMÁ ANI JEDEN — kdo má nejsilnější důvod
 * přenést odpovědnost, přenáší ji smlouvou, ne klikáním.
 *
 * Sada je proto dodávaná jako účinná. Invarianta „účinné vyžaduje schválení"
 * se NERUŠÍ, jen se omezuje na obsah, který se od dodané sady liší — viz
 * {@see VendorRulesetManifest}. Za dodané hodnoty ručí dodavatel a doloženy jsou
 * {@see RulesetSource} (odkaz + datum stažení) a {@see RulesetTechnicalReview}.
 *
 * Co tím zatím NEVZNIKÁ: formální záznam o tom, KDO za hodnoty ručí. Technická
 * kontrola není odborné ani právní schválení a `approval` zůstává `null`.
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
     *
     * Pozor, tenhle pin je nad PLNÝM snapshotem, tedy VČETNĚ lifecyclu — proto se
     * posunul při překlopení dodané sady na `active`. Otisk obsahu, podle kterého
     * se pozná dodaná sada, je v {@see VendorRulesetManifest} a ten se překlopením
     * nezměnil.
     */
    public const ENFORCEMENT_DEDUCTIONS_HASH =
        '2eac7d62318c2d361ad6a00ce6d6d443fc68c78d9fff40f1bf900768302edfbd';

    public static function provider(): PayrollRulesetProvider
    {
        $technicalReview = new RulesetTechnicalReview(
            'myucto/payroll-ruleset-source-check',
            self::RETRIEVED_ON,
            'Manifest oficiálních zdrojů, kontrola přesných hodnot a testy bajtové stability — '
            . 'technická kontrola, ne odborné ani právní schválení.',
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
                // § 6 odst. 9 písm. b) ZDP — příspěvek na stravování je osvobozený
                // „v úhrnu do výše 70 % horní hranice stravného, které lze poskytnout
                // zaměstnancům odměňovaným platem při pracovní cestě trvající 5 až
                // 12 hodin". To je limit NA SMĚNU, ne na rok: 2026 vychází 70 % ze
                // 185 Kč = 129,50 Kč (`meal_allowance.band_1.tax_exempt_maximum`
                // v doméně cestovních náhrad). Roční limit mzdové složky
                // (`payroll_component_definitions.annual_limit_minor`) ho vyjádřit
                // neumí, protože nezná počet směn — proto tu není částka, ale
                // vědomé ruční posouzení. Hodnota se tu ZÁMĚRNĚ nepočítá podruhé,
                // aby nemohla utéct od sazby stravného, ze které plyne.
                'benefit_exemption.meal.per_shift' => PayrollRuleValue::manualReview(
                    'Příspěvek na stravování je osvobozený do 70 % horní hranice stravného za '
                    . 'pracovní cestu 5 až 12 hodin, a to za každou směnu zvlášť. Roční limit '
                    . 'mzdové složky takový strop nevyjádří a aplikace ho proto netvrdí.',
                ),
                // § 6 odst. 9 písm. d) ZDP — nepeněžní plnění zaměstnanci a jeho
                // rodinnému příslušníkovi. Od 1. 1. 2025 má dva samostatné roční
                // ÚHRNNÉ limity odvozené z průměrné mzdy za zdaňovací období
                // (§ 21g ZDP, 2026 = 48 967 Kč):
                //   bod 1 — zdravotnické služby a zdravotnické prostředky … průměrná mzda
                //   bod 2 — rekreace a zájezd, sport, kultura, tisk, použití
                //           vzdělávacích a předškolních zařízení … polovina průměrné mzdy
                // Limit je úhrn za celé písmeno (resp. bod), ne za jednu mzdovou
                // složku. Složkový `annual_limit_minor` je proto jen strop JEDNÉ
                // složky — nutná, ne postačující podmínka; viz PayrollComponentDefaults.
                'benefit_exemption.non_cash_health.yearly' => PayrollRuleValue::moneyMinor(4_896_700),
                'benefit_exemption.non_cash_leisure.yearly' => PayrollRuleValue::moneyMinor(2_448_350),
                // § 6 odst. 9 písm. p) ZDP — příspěvek zaměstnavatele na daňově
                // podporované produkty spoření na stáří a na pojištění dlouhodobé
                // péče, osvobozený v úhrnu nejvýše 50 000 Kč ročně. Částku píše
                // zákon číslem, z průměrné mzdy se neodvozuje.
                'benefit_exemption.old_age_savings.yearly' => PayrollRuleValue::moneyMinor(5_000_000),
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
                // ROZHODNÁ ČÁSTKA, ne „nejvyšší ještě sražená odměna“. § 6 odst. 4
                // ZDP ve znění zák. č. 470/2024 Sb. (od 1. 1. 2025) říká „NEDOSÁHNE
                // částky rozhodné pro účast … na nemocenském pojištění“ — test je
                // tedy OSTRÝ (`<`) a hodnota je sama rozhodná částka, ne o korunu
                // nižší číslo. Do 31. 12. 2024 stálo v zákoně „nepřesáhne 10 000 Kč“,
                // tedy hranice včetně; proto se ta stará mez nedá vyjádřit týmž
                // klíčem a v tomhle rulesetu ani není.
                //
                // Klíče se jmenovaly `*.maximum` a nesly 11 999 / 4 499 Kč, což byl
                // přepis populárního výkladu „limit je 11 999" do `<=`. Pro celé
                // koruny to vychází stejně, pro odměnu s haléři ne: 11 999,50 Kč
                // rozhodné částky NEDOSÁHNE, a měla by tedy jít srážkou — se starým
                // zápisem šla zálohou. Přejmenování je záměrné: uložený override na
                // starý klíč se po obratu operátoru NESMÍ tiše použít dál, jinak by
                // posunul hranici o korunu níž.
                //
                // 2026: 25 % průměrné mzdy 48 967 = 12 241,75 → dolů na celých 500
                // (§ 7a odst. 2 z. č. 187/2006 Sb.) = 12 000 Kč.
                'dpp.withholding.threshold' => PayrollRuleValue::moneyMinor(1_200_000),
                // § 6 odst. 4 písm. b) ZDP — ostatní příjmy ze závislé činnosti;
                // rozhodná částka pro účast na nemocenském pojištění podle § 6 odst. 1
                // písm. a) z. č. 187/2006 Sb. je 1/10 průměrné mzdy zaokrouhlená dolů
                // na celých 500 Kč: 48 967 / 10 = 4 896,7 → 4 500 Kč.
                'other.withholding.threshold' => PayrollRuleValue::moneyMinor(450_000),
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
                    'Nárok na slevu závisí na zákonných podmínkách sezónní zemědělské činnosti '
                    . 'a musí ho posoudit člověk.',
                ),
                'employee.discount.working_pensioner' => PayrollRuleValue::rate('0.065'),
                'employee.rate.ordinary' => PayrollRuleValue::rate('0.071'),
                'employer.discount.part_time' => PayrollRuleValue::rate('0.05'),
                'employer.rate.ordinary' => PayrollRuleValue::rate('0.248'),
                'employer.rate.rescue_and_company_fire_service' => PayrollRuleValue::manualReview(
                    'Sazba 29,8 % je oficiální, ale zařazení zaměstnance k hasičskému záchrannému '
                    . 'sboru nebo mezi podnikové hasiče musí posoudit člověk.',
                ),
                'employer.rate.risk_employment' => PayrollRuleValue::manualReview(
                    'Sazba 27,8 % je oficiální, ale zařazení práce mezi rizikové musí posoudit člověk.',
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
                    'Nárok na náhradu, úplnost rozvrhu směn, souběh s dávkami a přerušené směny '
                    . 'musí posoudit mzdová účetní.',
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
                'Zahraniční pracovní cesty (zahraniční stravné, kapesné, přepočet měn) tenhle '
                . 'ruleset neřeší a vyúčtují se ručně podle vyhlášky pro daný stát.',
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
            PayrollRulesetLifecycle::Active,
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
                    'Lhůty závisí na agendě, události, kanálu podání a přechodných ustanoveních; '
                    . 'aplikace je neodvozuje a termín u konkrétního hlášení ukazuje stránka Podání.',
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
                    'Provozní číselníky se nahrávají importem s vlastním datem vydání a kontrolním '
                    . 'součtem, ne zápisem hodnoty do téhle sady.',
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
                    'Verze schémat jsou evidované, ale samotné odeslání podání není součástí '
                    . 'mzdového výpočtu.',
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
            PayrollRulesetLifecycle::Active,
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
