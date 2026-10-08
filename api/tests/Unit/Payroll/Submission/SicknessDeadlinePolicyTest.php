<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessChannelCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use PHPUnit\Framework\TestCase;

/**
 * Lhůty NEMPRI a HZUPN a fail-closed cesty agendy.
 */
final class SicknessDeadlinePolicyTest extends TestCase
{
    private SicknessDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SicknessDeadlinePolicy(CzechPayrollRulesets::provider());
    }

    /**
     * § 97 odst. 2 věta druhá: neprodleně PO UPLYNUTÍ prvních 14 dnů trvání
     * dočasné pracovní neschopnosti, tedy nejdřív 15. kalendářní den
     * (§ 26 odst. 1). Neschopnost od 1. 8. 2026 → 15. den je 15. 8. 2026,
     * což je sobota, takže termín padá na pondělí 17. 8.
     */
    public function testSicknessBenefitStartsOnFifteenthDayAndSkipsWeekend(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Nem,
            '2026-08-01',
        );

        self::assertSame('2026-08-15', $window->earliestNotificationOn);
        self::assertSame('2026-08-17', $window->dueOn);
        self::assertSame(
            SicknessDeadlinePolicy::SOURCE_DERIVED_IMMEDIACY,
            $window->sourceStatus,
        );
        self::assertStringContainsString('§ 97 odst. 2', $window->legalReference);
    }

    /**
     * § 26 odst. 1 zák. č. 187/2006 Sb.: nemocenské až od 15. dne. Neschopnost
     * 1. až 14. 8. celou kryje náhrada mzdy, NEMPRI nevzniká; 15. den už ano.
     * Převzaté dny téže neschopnosti se do trvání počítají, ostatní dávky
     * (ošetřovné) čekací dobu nemají.
     */
    public function testNempriIsRequiredOnlyWhenIncapacityExceedsFourteenDays(): void
    {
        self::assertFalse($this->policy->nempriRequired(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-14'));
        self::assertTrue($this->policy->nempriRequired(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-15'));
        self::assertTrue($this->policy->nempriRequired(SicknessBenefitKind::Nem, '2026-08-01', null));
        self::assertTrue($this->policy->nempriRequired(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-10', 5));
        self::assertFalse($this->policy->nempriRequired(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-10', 4));
        self::assertTrue($this->policy->nempriRequired(SicknessBenefitKind::Ose, '2026-08-01', '2026-08-03'));
    }

    /**
     * Padne-li 15. den na státní svátek podle zák. č. 245/2000 Sb., posouvá se
     * termín stejně jako o víkendu. Neschopnost od 20. 6. 2026 → 15. den je
     * sobota 4. 7. 2026, 5. 7. je neděle a zároveň svátek Cyrila a Metoděje,
     * 6. 7. je pondělní svátek Mistra Jana Husa, takže první pracovní den je
     * až úterý 7. 7.
     */
    public function testDeadlineSkipsPublicHolidays(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Nem,
            '2026-06-20',
        );

        self::assertSame('2026-07-04', $window->earliestNotificationOn);
        self::assertSame('2026-07-07', $window->dueOn);
    }

    /**
     * § 97 odst. 5: „nejpozději v následující pracovní den po dni, který je
     * určen pro výplatu mezd a platů". Jediná lhůta téhle agendy, kterou zákon
     * vyjadřuje dnem — proto `statute_verified`.
     */
    public function testCompensatoryAllowanceIsDueNextWorkingDayAfterPayday(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Vpm,
            '2026-08-01',
            null,
            '2026-08-14',
        );

        self::assertSame('2026-08-14', $window->earliestNotificationOn);
        // 14. 8. 2026 je pátek → následující pracovní den je pondělí 17. 8.
        self::assertSame('2026-08-17', $window->dueOn);
        self::assertSame(
            SicknessDeadlinePolicy::SOURCE_STATUTE_VERIFIED,
            $window->sourceStatus,
        );
    }

    public function testCompensatoryAllowanceFailsClosedWithoutPayday(): void
    {
        try {
            $this->policy->forNempri(SicknessBenefitKind::Vpm, '2026-08-01');
            self::fail('Bez výplatního dne nelze lhůtu podle § 97 odst. 5 spočítat.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'nempri_vpm_payment_date_missing',
                $exception->validationCode,
            );
        }
    }

    /**
     * § 97 odst. 1 věta čtvrtá ve spojení s § 38b odst. 1: podpůrčí doba
     * u otcovské činí 2 týdny.
     */
    public function testPaternityWaitsForSupportPeriod(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Opp,
            '2026-09-01',
        );

        self::assertSame('2026-09-15', $window->earliestNotificationOn);
    }

    /**
     * § 40 odst. 1 mluví o podpůrčí době „nejdéle 9 kalendářních dnů" — je to
     * horní mez. Skončila-li potřeba ošetřování dřív, běží lhůta od skutečného
     * skončení, ne od uplynutí devíti dnů. Oznámení se předává PO skončení
     * péče (§ 97 odst. 1 věta čtvrtá), tedy nejdřív den po posledním dni péče.
     */
    public function testCareBenefitUsesActualEndWhenShorterThanSupportPeriod(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Ose,
            '2026-09-01',
            '2026-09-04',
        );

        self::assertSame('2026-09-05', $window->earliestNotificationOn);
        // Sobota 5. 9. se posouvá na pondělí 7. 9.
        self::assertSame('2026-09-07', $window->dueOn);
    }

    /** DPN-09: péče skončila 10. 6., oznámení nejdřív 11. 6. */
    public function testCareBenefitEndedEarlyIsDueTheDayAfterCareEnded(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Ose,
            '2026-06-08',
            '2026-06-10',
        );

        self::assertSame('2026-06-11', $window->earliestNotificationOn);
        self::assertSame('2026-06-11', $window->dueOn);
    }

    /**
     * DPN-06, § 26 odst. 3: odpracoval-li zaměstnanec v den vzniku celou směnu,
     * je prvním dnem neschopnosti až následující den. DPN 8. až 22. 6. má pak
     * jen 14 dnů, nemocenské nenáleží a NEMPRI nevzniká.
     */
    public function testWorkedFirstDayShiftsIncapacityStartForNempriDuty(): void
    {
        self::assertTrue($this->policy->nempriRequired(
            SicknessBenefitKind::Nem,
            '2026-06-08',
            '2026-06-22',
        ));
        self::assertFalse($this->policy->nempriRequired(
            SicknessBenefitKind::Nem,
            '2026-06-08',
            '2026-06-22',
            workedFirstDay: true,
        ));
    }

    /** DPN-06: s odpracovaným prvním dnem začíná lhůta NEMPRI o den později. */
    public function testWorkedFirstDayShiftsNempriDeadlineByOneDay(): void
    {
        $plain = $this->policy->forNempri(SicknessBenefitKind::Nem, '2026-06-08', '2026-06-30');
        $worked = $this->policy->forNempri(
            SicknessBenefitKind::Nem,
            '2026-06-08',
            '2026-06-30',
            workedFirstDay: true,
        );

        self::assertSame('2026-06-22', $plain->earliestNotificationOn);
        self::assertSame('2026-06-23', $worked->earliestNotificationOn);
        self::assertSame('2026-06-23', $worked->dueOn);
    }

    /**
     * NEMPRI25-CR-11, § 40 odst. 1 věta druhá: vznikla-li potřeba ošetřování
     * v den už odpracované směny, podpůrčí doba ošetřovného počíná až
     * následujícím dnem. Péče od 1. 9. 2026: bez směny nejdřív 10. 9.,
     * s odpracovanou směnou 11. 9. U PPM se odpracovaný den nepromítá.
     */
    public function testWorkedFirstDayDefersCareSupportPeriod(): void
    {
        $plain = $this->policy->forNempri(SicknessBenefitKind::Ose, '2026-09-01');
        $worked = $this->policy->forNempri(SicknessBenefitKind::Ose, '2026-09-01', workedFirstDay: true);
        $ppm = $this->policy->forNempri(SicknessBenefitKind::Ppm, '2026-09-01', workedFirstDay: true);

        self::assertSame('2026-09-10', $plain->earliestNotificationOn);
        self::assertSame('2026-09-11', $worked->earliestNotificationOn);
        self::assertSame('2026-09-01', $ppm->earliestNotificationOn);
        self::assertTrue(SicknessDeadlinePolicy::firstDayShiftDefersSupport(SicknessBenefitKind::Ose));
        self::assertFalse(SicknessDeadlinePolicy::firstDayShiftDefersSupport(SicknessBenefitKind::Dlo));
    }

    /**
     * NEMPRI25-lhuta-8: u malého rozsahu a DPP se oznámení zasílá až po
     * zjištění započitatelného příjmu v měsíci události, tedy nejdřív první
     * den následujícího měsíce. Pozdější termín podle druhu dávky zůstává.
     */
    public function testSmallScopeAndAgreementWaitForEventMonthIncome(): void
    {
        $plain = $this->policy->forNempri(SicknessBenefitKind::Nem, '2026-08-03');
        $dpp = $this->policy->forNempri(SicknessBenefitKind::Nem, '2026-08-03', awaitsEventMonthIncome: true);
        $late = $this->policy->forNempri(SicknessBenefitKind::Nem, '2026-08-25', awaitsEventMonthIncome: true);
        $care = $this->policy->forNempri(SicknessBenefitKind::Ose, '2026-08-03', '2026-08-05', awaitsEventMonthIncome: true);

        self::assertSame('2026-08-17', $plain->earliestNotificationOn);
        self::assertSame('2026-09-01', $dpp->earliestNotificationOn);
        self::assertSame('2026-09-01', $dpp->dueOn);
        self::assertSame('2026-09-08', $late->earliestNotificationOn);
        self::assertSame('2026-09-01', $care->earliestNotificationOn);

        self::assertTrue(SicknessDeadlinePolicy::awaitsEventMonthIncome(['relation_type' => 'dpp'], []));
        self::assertTrue(SicknessDeadlinePolicy::awaitsEventMonthIncome(['relation_type' => 'hpp'], ['small_scope_income_minor' => 450000]));
        self::assertFalse(SicknessDeadlinePolicy::awaitsEventMonthIncome(['relation_type' => 'hpp'], ['small_scope_income_minor' => null]));
    }

    /**
     * HZUPN20-CRIT-WEB-1, APPLIC-2 a CRIT-LAW-5: HZUPN se zasílá jen
     * u nemocenského s DPN delší než 14 dnů a ne, když zaměstnání skončilo
     * v průběhu DPN nebo DPN vznikla až v ochranné lhůtě.
     */
    public function testEndOfIncapacityReportIsRequiredOnlyWhenCsszWantsIt(): void
    {
        $reason = fn (SicknessBenefitKind $kind, string $from, ?string $to, ?string $end, bool $worked = false): ?string
            => $this->policy->hzupnNotRequired($kind, $from, $to, $end, $worked)['code'] ?? null;

        self::assertSame('hzupn_not_for_benefit_kind', $reason(SicknessBenefitKind::Ose, '2026-08-01', '2026-08-30', null));
        self::assertSame('hzupn_within_wage_compensation_window', $reason(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-14', null));
        self::assertSame('hzupn_within_wage_compensation_window', $reason(SicknessBenefitKind::Nem, '2026-06-08', '2026-06-22', null, true));
        self::assertNull($reason(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-15', null));
        self::assertNull($reason(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-30', '2026-09-30'));
        self::assertSame('hzupn_employment_ended_during_incapacity', $reason(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-30', '2026-08-20'));
        self::assertSame('hzupn_employment_ended_during_incapacity', $reason(SicknessBenefitKind::Nem, '2026-08-01', '2026-08-30', '2026-08-30'));
        self::assertSame('hzupn_employment_ended_during_incapacity', $reason(SicknessBenefitKind::Nem, '2026-08-01', null, '2026-08-20'));
        self::assertSame('hzupn_incapacity_in_protection_period', $reason(SicknessBenefitKind::Nem, '2026-08-03', '2026-08-30', '2026-07-31'));
    }

    public function testLoneCarerGetsLongerCareSupportPeriod(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Ose,
            '2026-09-01',
            null,
            null,
            true,
        );

        self::assertSame('2026-09-17', $window->earliestNotificationOn);
    }

    /**
     * § 97 odst. 3: hlásit se dá teprve tehdy, když je co hlásit. Skončení
     * neschopnosti 2026-08-22 je sobota → termín pondělí 24. 8.
     */
    /**
     * HZUPN hlásí NÁSTUP do zaměstnání. Poslední den neschopnosti je sobota
     * 22. 8., nastoupit se dá nejdřív v neděli 23. 8. a lhůta připadne na
     * pondělí 24. 8.
     */
    public function testEndOfIncapacityReportRunsFromTheDayAfterTheLastDayOfIncapacity(): void
    {
        $window = $this->policy->forHzupn('2026-08-01', '2026-08-22');

        self::assertSame('2026-08-23', $window->earliestNotificationOn);
        self::assertSame('2026-08-24', $window->dueOn);
        self::assertStringContainsString('§ 97 odst. 3', $window->legalReference);
    }

    /**
     * Zapsaný den nástupu rozhoduje. Neschopnost skončila v pondělí 17. 8.,
     * zaměstnanec nastoupil až ve středu 26. 8. (dovolená) — lhůta běží od
     * nástupu, ne od konce neschopnosti. Dřív vycházela na 17. 8., tedy
     * na den, kdy ještě nebylo co hlásit.
     */
    public function testEndOfIncapacityReportRunsFromTheRecordedReturnDay(): void
    {
        $window = $this->policy->forHzupn('2026-08-01', '2026-08-17', '2026-08-26');

        self::assertSame('2026-08-26', $window->earliestNotificationOn);
        self::assertSame('2026-08-26', $window->dueOn);
    }

    public function testReturnDayBeforeIncapacityIsRefused(): void
    {
        try {
            $this->policy->forHzupn('2026-08-10', '2026-08-17', '2026-08-01');
            self::fail('Nástup před vznikem neschopnosti nedává smysl.');
        } catch (SicknessException $exception) {
            self::assertSame('hzupn_return_before_incapacity', $exception->validationCode);
        }
    }

    public function testEndOfIncapacityReportFailsClosedWhileIncapacityLasts(): void
    {
        try {
            $this->policy->forHzupn('2026-08-01', null);
            self::fail('Bez skončení neschopnosti povinnost podle § 97 odst. 3 nevzniká.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'hzupn_incapacity_end_missing',
                $exception->validationCode,
            );
        }
    }

    /**
     * Otisk pravidel se musí změnit, jakmile se změní kterýkoli parametr —
     * jinak by evidence povinností tvrdila, že termín spočítala stejná verze
     * pravidel jako dřív.
     */
    public function testRulesetHashIsStableAcrossCalls(): void
    {
        $first = $this->policy->forNempri(SicknessBenefitKind::Nem, '2026-08-01');
        $second = $this->policy->forHzupn('2026-08-01', '2026-08-22');

        self::assertSame($first->rulesetHash, $second->rulesetHash);
        self::assertSame(
            SicknessDeadlinePolicy::RULESET_ID,
            $first->rulesetId,
        );
    }

    /**
     * VREP/APEP ČSSZ pro obě agendy přijímá, ale identifikátor třídy podání
     * pro ně v připnutém Podávacím a dotazovacím protokolu v1.47 není. Kanál
     * proto zůstává zavřený s vlastním důvodovým kódem, ne obecným
     * „nepodporováno".
     */
    /**
     * Čekací doba NEMPRI se nesmí duplikovat vlastní konstantou — musí to být
     * TATÁŽ hodnota, kterou pro náhradu mzdy podle § 192 ZP nese
     * {@see AbsenceRuleset::sicknessWindowCalendarDays()}, jinak by se novela
     * lhůty promítla do výpočtu náhrady, ale ne do termínu tady.
     */
    public function testNemWaitingDaysComeFromTheSameRulesetKeyAsWageCompensation(): void
    {
        $absence = AbsenceRuleset::forDate(CzechPayrollRulesets2026::provider(), '2026-08-01');

        $window = $this->policy->forNempri(SicknessBenefitKind::Nem, '2026-08-01');
        $expected = (new \DateTimeImmutable('2026-08-01'))
            ->modify('+' . $absence->sicknessWindowCalendarDays() . ' days')
            ->format('Y-m-d');

        self::assertSame($expected, $window->earliestNotificationOn);
    }

    public function testVrepChannelStaysClosedWithNamedReason(): void
    {
        $catalog = new SicknessChannelCatalog();

        self::assertSame('isds', $catalog->dispatchChannel());
        $catalog->assertDispatchable('isds');

        try {
            $catalog->assertDispatchable('vrep_apep');
            self::fail('Nedoložený kanál se nesmí otevřít.');
        } catch (SicknessException $exception) {
            self::assertSame(
                SicknessChannelCatalog::REASON_VREP_CLASS_UNDOCUMENTED,
                $exception->validationCode,
            );
            self::assertStringContainsString('5ffu6xk', $exception->getMessage());
        }
    }

    public function testUnknownChannelIsRefusedToo(): void
    {
        $catalog = new SicknessChannelCatalog();

        try {
            $catalog->assertDispatchable('email');
            self::fail('Neznámý kanál se nesmí otevřít.');
        } catch (SicknessException $exception) {
            self::assertSame(
                SicknessChannelCatalog::REASON_CHANNEL_UNKNOWN,
                $exception->validationCode,
            );
        }
    }
}
