<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetOrigin;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\VendorRulesetApprover;
use MyInvoice\Service\Payroll\Ruleset\VendorRulesetManifest;
use PHPUnit\Framework\TestCase;

final class CzechPayrollRulesets2026Test extends TestCase
{
    /**
     * Pin se posunul s opravou hranice srážkové daně z DPP: `dpp.withholding.maximum`
     * 11 999 Kč → `dpp.withholding.threshold` 12 000 Kč a `other.withholding.maximum`
     * 4 499 Kč → `other.withholding.threshold` 4 500 Kč. Hodnota je nově sama ROZHODNÁ
     * ČÁSTKA (§ 7a z. č. 187/2006 Sb.) a poměřuje se ostře, protože § 6 odst. 4 písm. a)
     * ZDP zní od 1. 1. 2025 „nedosáhne" — viz {@see \MyInvoice\Tests\Unit\Payroll\IncomeTax\DppWithholdingBoundaryParityTest}.
     *
     * Podruhé se posunul s počeštěním textů určených uživateli: důvody ručního
     * posouzení a popis technické kontroly jsou součástí kanonického snapshotu,
     * takže překlad do češtiny je z pohledu otisku obsahová změna. Hodnoty
     * parametrů se přitom nezměnily — hlídá to matice níže.
     *
     * Potřetí se posunul s doplněním limitů osvobození zaměstnaneckých benefitů
     * (§ 6 odst. 9 písm. b), d) a p) ZDP). Doména daně z příjmů je nesla jen
     * v hlavách účetních, takže výchozí mzdové složky měly `annual_limit_minor`
     * NULL a roční strop se nehlídal vůbec — viz
     * {@see \MyInvoice\Service\Payroll\Component\PayrollComponentDefaults}.
     *
     * Popáté doplněním limitů přesčasové práce podle § 93 zákoníku práce do domény
     * hranic zaměstnání, aby byly administrovatelné a ne zadrátované ve službě.
     *
     * Počtvrté překlopením dodané sady z `reviewed` na `active`. Lifecycle je
     * součástí PLNÉHO snapshotu, ale ne otisku OBSAHU — hodnoty ani
     * `VendorRulesetManifest::CONTENT_HASHES` se tím tedy nezměnily.
     *
     * Pošesté doplněním podpisu provozovatele ({@see VendorRulesetApprover}) a
     * ročních klíčů daně z příjmů (§ 38ch odst. 5, § 35c odst. 3, § 35bb). Podpis
     * hýbe JEN tímhle pinem — otisk obsahu je bez schválení, takže se
     * `CONTENT_HASHES` posunul výhradně u domény daně z příjmů, a to kvůli novým
     * parametrům, ne kvůli schvalovateli.
     *
     * POZOR: tenhle pin je nad PLNÝM snapshotem, tedy včetně jména schvalovatele.
     * Platí proto pro VÝCHOZÍHO schvalovatele; instalace s jiným provozovatelem má
     * legitimně jiné číslo. Test si default proto vynutí sám.
     */
    private const EXPECTED_MANIFEST_SHA256 = '4f13afff7f9b5dcd9fc69bf65a69e05627fd0245fd40d3faa24ff1be6727057a';

    protected function setUp(): void
    {
        VendorRulesetApprover::configure(VendorRulesetApprover::DEFAULT_NAME);
    }

    protected function tearDown(): void
    {
        VendorRulesetApprover::reset();
    }

    public function testCanonicalManifestIsByteStable(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        $first = $provider->canonicalManifestJson();
        $second = CzechPayrollRulesets2026::provider()->canonicalManifestJson();

        self::assertSame($first, $second);
        self::assertSame(self::EXPECTED_MANIFEST_SHA256, hash('sha256', $first));
    }

    /**
     * Schvalovatel je vlastnost INSTALACE, ne produktu. Jiný provozovatel musí
     * dostat týž obsah pod svým jménem — a hlavně nesmí spadnout na integritním
     * pinu, který je proto vedený nad obsahem, ne nad plným snapshotem.
     */
    public function testApproverComesFromInstallationConfigurationAndDoesNotChangeContent(): void
    {
        $ours = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::EnforcementDeductions, '2026-08-03');
        self::assertNotNull($ours->approval);
        self::assertSame(VendorRulesetApprover::DEFAULT_NAME, $ours->approval->approvedBy);

        VendorRulesetApprover::configure('Jiný Provozovatel s.r.o.');
        $theirs = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::EnforcementDeductions, '2026-08-03');

        self::assertSame('Jiný Provozovatel s.r.o.', $theirs->approval?->approvedBy);
        self::assertSame($ours->contentHash, $theirs->contentHash);
        self::assertNotSame($ours->canonicalHash, $theirs->canonicalHash);
        self::assertSame(PayrollRulesetOrigin::Vendor, $theirs->origin);
        self::assertSame(PayrollRulesetLifecycle::Active, $theirs->lifecycle);
    }

    /**
     * Integritní pin nezabavitelných částek a manifest dodané sady nesou totéž
     * číslo. Dvě čísla pro jednu věc smí koexistovat jen potud, pokud se rozejít
     * nemůžou — tenhle test je ta podmínka.
     */
    public function testEnforcementPinIsTheDeliveredContentHash(): void
    {
        self::assertTrue(
            VendorRulesetManifest::contains(CzechPayrollRulesets2026::ENFORCEMENT_DEDUCTIONS_HASH),
        );
        self::assertSame(
            CzechPayrollRulesets2026::ENFORCEMENT_DEDUCTIONS_HASH,
            CzechPayrollRulesets2026::provider()
                ->forDate(PayrollRulesetDomain::EnforcementDeductions, '2026-08-03')
                ->contentHash,
        );
    }

    public function testAmountsUseMinorUnitsAndRatesUseCanonicalStrings(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        $health = $provider->forDate(PayrollRulesetDomain::HealthInsurance, '2026-08-03');
        $employment = $provider->forDate(PayrollRulesetDomain::EmploymentThresholds, '2026-08-03');

        self::assertSame(302_400, $health->parameter('minimum_contribution.monthly')->value);
        self::assertSame('0.135', $health->parameter('total.rate')->value);
        self::assertSame(2_240_000, $employment->parameter('minimum_wage.monthly_40h_week')->value);
        self::assertSame(13_440, $employment->parameter('minimum_wage.hourly_40h_week')->value);
    }

    public function testEverySourceHasRetrievalDateAndOfficialHttpsUrl(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        foreach (PayrollRulesetDomain::cases() as $domain) {
            $ruleset = $provider->forDate($domain, '2026-08-03');
            foreach ($ruleset->sources as $source) {
                self::assertSame(CzechPayrollRulesets2026::RETRIEVED_ON, $source->retrievedOn);
                self::assertStringStartsWith('https://', $source->url);
                self::assertMatchesRegularExpression(
                    '/\.(cssz|mpsv|gov|vzp|e-sbirka|justice)\.cz|^https:\/\/financnisprava\.gov\.cz/',
                    $source->url,
                );
            }
        }
    }

    public function testSnapshotCarriesIdentityLifecycleSourcesAndApproval(): void
    {
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-03');
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($ruleset->canonicalSnapshot, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('cz-payroll-2026.income-tax.v1', $snapshot['id']);
        self::assertSame('2026.1.0', $snapshot['version']);
        self::assertSame('active', $snapshot['lifecycle']);
        self::assertSame('2026-01-01', $snapshot['effective_from']);
        self::assertSame('2026-12-31', $snapshot['effective_to']);
        self::assertIsArray($snapshot['approval']);
        self::assertSame(
            VendorRulesetApprover::DEFAULT_NAME,
            $snapshot['approval']['approved_by'],
        );
        self::assertNotEmpty($snapshot['technical_review']);
        self::assertNotEmpty($snapshot['sources']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ruleset->canonicalHash);
    }

    /**
     * Podpis dodavatele pod dodanou sadou. Musí sedět PŘESNĚ — je to jediné,
     * co odlišuje sadu, za kterou ručíme my, od obsahu, který si upravil zákazník.
     */
    public function testVendorManifestPinsEveryDeliveredVersion(): void
    {
        $versions = CzechPayrollRulesets2026::provider()->versions();
        usort(
            $versions,
            static fn (PayrollRulesetVersion $left, PayrollRulesetVersion $right): int
                => $left->id <=> $right->id,
        );

        $actual = [];
        foreach ($versions as $version) {
            $actual[] = $version->contentHash;
        }

        self::assertSame(
            VendorRulesetManifest::CONTENT_HASHES,
            $actual,
            "Dodaná sada se změnila. Aktualizujte VendorRulesetManifest::CONTENT_HASHES na:\n"
            . implode("\n", array_map(
                static fn (PayrollRulesetVersion $version): string
                    => sprintf("        // %s\n        '%s',", $version->id, $version->contentHash),
                $versions,
            )),
        );
    }

    /**
     * Zákazník si po instalaci mzdy spočítá, aniž by cokoli odklikával — a domény
     * vedené jako RUČNÍ POSOUZENÍ zůstávají mimo výpočet i po překlopení lifecyclu.
     * Tuhle vlastnost drží `capability`, ne stav; překlopení ji nesmí přebít.
     */
    public function testDeliveredSetIsActiveAndManualReviewDomainsStayOutOfCalculation(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        $manualReviewDomains = [
            PayrollRulesetDomain::CompensationAverages,
            PayrollRulesetDomain::Deadlines,
            PayrollRulesetDomain::Codebooks,
            PayrollRulesetDomain::Submissions,
        ];

        foreach ($provider->versions() as $version) {
            self::assertSame(PayrollRulesetLifecycle::Active, $version->lifecycle, $version->id);
            self::assertSame(PayrollRulesetOrigin::Vendor, $version->origin, $version->id);
            self::assertNotNull($version->approval, $version->id);
            self::assertNotNull($version->technicalReview, $version->id);
        }

        foreach (PayrollRulesetDomain::cases() as $domain) {
            $date = $domain === PayrollRulesetDomain::TravelAllowances ? '2026-08-03' : '2026-08-03';
            if (in_array($domain, $manualReviewDomains, true)) {
                try {
                    $provider->forCalculation($domain, $date);
                    self::fail("Doména {$domain->value} je ruční posouzení a nesmí do výpočtu.");
                } catch (PayrollRulesetException $exception) {
                    self::assertStringContainsString('requires manual review', $exception->getMessage());
                }
                continue;
            }
            self::assertSame(
                PayrollRulesetCapability::Supported,
                $provider->forCalculation($domain, $date)->capability,
                $domain->value,
            );
        }
    }

    public function testCanonicalSupportedParameterMatrixAndManualReviewBoundary(): void
    {
        [$supported, $manualReview] = $this->canonicalParameterMatrix(
            CzechPayrollRulesets2026::provider(),
        );

        self::assertSame([
            'income_tax' => [
                'advance.high_rate' => ['decimal_rate', '0.23'],
                'advance.high_threshold.monthly' => ['money_minor', 14_690_100],
                'advance.low_rate' => ['decimal_rate', '0.15'],
                'advance.rounding.base_above_100_czk' => ['text', 'ceil-to-100-czk'],
                'advance.rounding.base_up_to_100_czk' => ['text', 'ceil-to-1-czk'],
                'advance.rounding.result' => ['text', 'ceil-to-1-czk'],
                // § 6 odst. 9 písm. d) ZDP, dva samostatné roční úhrnné limity
                // z průměrné mzdy 48 967 Kč (§ 21g ZDP): bod 1 zdravotnická plnění
                // celá průměrná mzda, bod 2 volnočasová plnění její polovina.
                'benefit_exemption.non_cash_health.yearly' => ['money_minor', 4_896_700],
                'benefit_exemption.non_cash_leisure.yearly' => ['money_minor', 2_448_350],
                // § 6 odst. 9 písm. p) ZDP — pevná částka ze zákona, ne odvozenina.
                'benefit_exemption.old_age_savings.yearly' => ['money_minor', 5_000_000],
                // § 35d odst. 4 „alespoň 50 Kč" versus § 35c odst. 3 „alespoň 100 Kč".
                // Roční hodnota NENÍ dvanáctinásobek měsíční — proto tu stojí obě.
                'bonus.minimum_amount.monthly' => ['money_minor', 5_000],
                'bonus.minimum_amount.yearly' => ['money_minor', 10_000],
                'bonus.minimum_income.monthly' => ['money_minor', 1_120_000],
                'bonus.minimum_income.yearly' => ['money_minor', 13_440_000],
                'credit.child.first.monthly' => ['money_minor', 126_700],
                'credit.child.second.monthly' => ['money_minor', 186_000],
                'credit.child.third_and_next.monthly' => ['money_minor', 232_000],
                'credit.disability.basic.monthly' => ['money_minor', 21_000],
                'credit.disability.extended.monthly' => ['money_minor', 42_000],
                // § 35bb: roční částka, zdvojnásobení u průkazu ZTP/P a limit
                // vlastního příjmu manžela. Měsíční protějšek nemají — § 38h odst. 6
                // k slevě na manžela při zálohách nepřihlíží.
                'credit.spouse.yearly' => ['money_minor', 2_484_000],
                'credit.spouse.ztp_p_multiplier' => ['integer', 2],
                'credit.taxpayer.monthly' => ['money_minor', 257_000],
                'credit.ztp_p.monthly' => ['money_minor', 134_500],
                'dpp.withholding.threshold' => ['money_minor', 1_200_000],
                'other.withholding.threshold' => ['money_minor', 450_000],
                // § 38ch odst. 5 / § 35d odst. 8 — „více než 50 Kč", tedy OSTŘE.
                'settlement.payout_threshold' => ['money_minor', 5_000],
                'spouse.income_limit' => ['money_minor', 6_800_000],
                'withholding.rate' => ['decimal_rate', '0.15'],
            ],
            'social_insurance' => [
                'employee.discount.working_pensioner' => ['decimal_rate', '0.065'],
                'employee.rate.ordinary' => ['decimal_rate', '0.071'],
                'employer.discount.part_time' => ['decimal_rate', '0.05'],
                'employer.rate.ordinary' => ['decimal_rate', '0.248'],
                'maximum_assessment_base.yearly' => ['money_minor', 235_041_600],
                'participation.dpp.minimum' => ['money_minor', 1_200_000],
                'participation.small_scale.minimum' => ['money_minor', 450_000],
            ],
            'health_insurance' => [
                'employee.rate' => ['decimal_rate', '0.045'],
                'employer.rate' => ['decimal_rate', '0.09'],
                'minimum_assessment_base.monthly' => ['money_minor', 2_240_000],
                'minimum_contribution.monthly' => ['money_minor', 302_400],
                'participation.dpc.minimum' => ['money_minor', 450_000],
                'participation.dpp.minimum' => ['money_minor', 1_200_000],
                'rounding.total' => ['text', 'ceil-to-1-czk'],
                'total.rate' => ['decimal_rate', '0.135'],
            ],
            'employment_thresholds' => [
                'average_wage.monthly' => ['money_minor', 4_896_700],
                'minimum_wage.hourly_40h_week' => ['money_minor', 13_440],
                'minimum_wage.monthly_40h_week' => ['money_minor', 2_240_000],
                'overtime.annual.early_warning_basis_points' => ['integer', 8_000],
                'overtime.averaging.max_weeks' => ['integer', 26],
                'overtime.averaging.weekly_average_max_minutes' => ['integer', 480],
                'overtime.ordered.weekly_max_minutes' => ['integer', 480],
                'overtime.ordered.yearly_max_minutes' => ['integer', 9_000],
                'participation.dpc.minimum' => ['money_minor', 450_000],
                'participation.dpp.minimum' => ['money_minor', 1_200_000],
                'participation.small_scale.minimum' => ['money_minor', 450_000],
            ],
        ], $supported, 'A supported 2026 payroll parameter changed or crossed the capability boundary.');

        self::assertSame([
            'income_tax' => [
                // Limit § 6 odst. 9 písm. b) ZDP je za směnu, ne za rok. Musí tu
                // být VIDĚT jako vědomé ruční posouzení — kdyby se tichým doplněním
                // částky stal „supported", roční strop mzdové složky by tvrdil
                // limit, který zákon takhle nestanoví.
                'benefit_exemption.meal.per_shift' =>
                    'Příspěvek na stravování je osvobozený do 70 % horní hranice stravného za '
                    . 'pracovní cestu 5 až 12 hodin, a to za každou směnu zvlášť. Roční limit '
                    . 'mzdové složky takový strop nevyjádří a aplikace ho proto netvrdí.',
                // Částky slevy na manžela v rulesetu JSOU, nárok na ni ale ne:
                // § 35bb odst. 2 písm. a) žádá od 2024 i vyživované dítě do 3 let
                // ve společně hospodařící domácnosti. Kdyby se tenhle klíč tiše
                // stal „supported", zúčtování by slevu přiznalo, aniž by kdokoli
                // ověřil podmínku, kterou modul v datech nemá.
                'credit.spouse.eligibility' =>
                    'Nárok na slevu na manžela závisí na společně hospodařící domácnosti, '
                    . 'na vyživovaném dítěti do 3 let věku, na vlastním příjmu manžela '
                    . 'a na doložení podle § 38l — musí ho posoudit mzdová účetní.',
            ],
            'social_insurance' => [
                'employee.discount.agriculture_dpp' =>
                    'Nárok na slevu závisí na zákonných podmínkách sezónní zemědělské činnosti '
                    . 'a musí ho posoudit člověk.',
                'employer.rate.rescue_and_company_fire_service' =>
                    'Sazba 29,8 % je oficiální, ale zařazení zaměstnance k hasičskému záchrannému '
                    . 'sboru nebo mezi podnikové hasiče musí posoudit člověk.',
                'employer.rate.risk_employment' =>
                    'Sazba 27,8 % je oficiální, ale zařazení práce mezi rizikové musí posoudit člověk.',
            ],
            'health_insurance' => [],
            'employment_thresholds' => [],
        ], $manualReview, 'A manual-review parameter changed or became silently calculation-ready.');
    }

    /**
     * @return array{
     *   array<string, array<string, array{string, bool|int|string}>>,
     *   array<string, array<string, string>>
     * }
     */
    private function canonicalParameterMatrix(PayrollRulesetProvider $provider): array
    {
        $supported = [];
        $manualReview = [];
        foreach ([
            PayrollRulesetDomain::IncomeTax,
            PayrollRulesetDomain::SocialInsurance,
            PayrollRulesetDomain::HealthInsurance,
            PayrollRulesetDomain::EmploymentThresholds,
        ] as $domain) {
            $ruleset = $provider->forDate($domain, '2026-08-03');
            $supported[$domain->value] = [];
            $manualReview[$domain->value] = [];
            foreach ($ruleset->parameters as $key => $parameter) {
                if ($parameter->capability === PayrollRulesetCapability::Supported) {
                    $supported[$domain->value][$key] = [$parameter->type, $parameter->value];
                    continue;
                }

                $manualReview[$domain->value][$key] = (string) $parameter->note;
            }
        }

        return [$supported, $manualReview];
    }
}
