<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Lhůty evidenčního listu důchodového pojištění.
 *
 * ## Zákonný rámec
 *
 * Zákon č. 582/1991 Sb., o organizaci a provádění sociálního zabezpečení,
 * **§ 38** (vedení evidenčních listů) a **§ 39** (jejich předkládání):
 *
 * - evidenční list vede zaměstnavatel pro každého občana účastného
 *   důchodového pojištění, a to **za kalendářní rok**;
 * - vyhotovuje se **po zúčtování mezd za prosinec, nejpozději do 30. dubna**
 *   následujícího kalendářního roku;
 * - skončí-li účast na důchodovém pojištění v průběhu roku, vyhotovuje se
 *   **do jednoho měsíce po konečném vyúčtování příjmů, nejpozději do
 *   31. ledna** následujícího kalendářního roku;
 * - **předkládá se ČSSZ do 30 dnů ode dne zápisu údajů** do evidenčního
 *   listu; na výzvu OSSZ do 8 dnů, při zániku zaměstnavatele do 30 dnů,
 *   při úmrtí pojištěnce do 3 měsíců;
 * - jeden stejnopis zaměstnavatel zakládá do své evidence, druhý vydává
 *   zaměstnanci.
 *
 * Modul registruje jako termín povinnosti **vnější zákonnou mez pro
 * vyhotovení** (30. 4., resp. 31. 1. / vyúčtování + 1 měsíc), ne až mez pro
 * předložení (vyhotovení + 30 dnů). Je to vědomě přísnější: lhůta na
 * předložení běží od zápisu, který v aplikaci vzniká právě přípravou podání,
 * takže pozdější datum by povinnost jen falešně prodlužovalo.
 *
 * ⚠️ **Míra jistoty.** Věcný obsah lhůt je ověřený proti informační stránce
 * ČSSZ *Evidenční listy důchodového pojištění*. Členění na konkrétní
 * **odstavce** § 38 a § 39 se ověřit nepodařilo (plné znění zákona nebylo
 * z prostředí dostupné), proto se v katalogu zdrojů cituje jen úroveň
 * paragrafu. Doba uchování stejnopisu se z tohoto důvodu do rulesetu
 * nepromítá vůbec — viz `EldpAnnualStatementBuilder`.
 *
 * ## Přechodná pravidla od JMHZ
 *
 * Podle *Pravidel podání JMHZ a souvisejících procesů* verze 1.4.4, kap. 4
 * sestavuje od roku 2026 ELDP sama ČSSZ z údajů měsíčního hlášení. Samostatný
 * evidenční list vede a předkládá zaměstnavatel jen:
 *
 * - za roky **před 1. 1. 2026** stávajícím způsobem,
 * - při **skončení účasti zaměstnance před 1. 4. 2026**,
 * - **na výzvu ČSSZ/ÚSSZ** za celý rok 2026 nebo když z nahlášených údajů
 *   ELDP sestavit nelze,
 * - u příslušníků ozbrojených sil (ti se předkládají MV/MO, ne ČSSZ —
 *   tuhle větev modul nepodporuje).
 */
final class EldpDeadlinePolicy
{
    public const ANNUAL_RULESET = 'cz-eldp-deadlines.annual.v1';
    public const TERMINATION_RULESET = 'cz-eldp-deadlines.termination.v1';

    private const SOURCES = [
        'law' => '582/1991 Sb., § 38 a § 39',
        'pension_law' => '155/1995 Sb., § 16 odst. 4 písm. a) a j)',
        'cssz_document' => 'ČSSZ — Evidenční listy důchodového pojištění',
        'jmhz_rules' =>
            'Pravidla podání JMHZ a související procesy, verze 1.4.4, kapitola 4',
        'paragraph_detail_verified' => 'no',
    ];

    /**
     * Řádný roční evidenční list za skončený kalendářní rok.
     */
    public function forYear(int $year): EldpDeadlineWindow
    {
        self::assertYear($year);

        return $this->window(
            sprintf('%04d-01-01', $year + 1),
            sprintf('%04d-04-30', $year + 1),
            self::ANNUAL_RULESET,
            'annual_by_30_april_following_year',
            'annual',
            'Zákon č. 582/1991 Sb., § 38 a § 39 — evidenční list za kalendářní rok '
                . 'se vyhotovuje nejpozději do 30. dubna následujícího roku.',
        );
    }

    /**
     * Mimořádný evidenční list při skončení účasti na důchodovém pojištění.
     *
     * @param string $participationEndOn poslední den účasti (Y-m-d)
     * @param string $finalSettlementOn  den konečného vyúčtování příjmů (Y-m-d)
     */
    public function forTermination(
        int $year,
        string $participationEndOn,
        string $finalSettlementOn,
    ): EldpDeadlineWindow {
        self::assertYear($year);
        $end = self::date($participationEndOn, 'Poslední den účasti');
        $settlement = self::date($finalSettlementOn, 'Konečné vyúčtování příjmů');
        if ($end->format('Y') !== (string) $year) {
            throw new \InvalidArgumentException(
                'Poslední den účasti musí ležet ve vykazovaném roce.',
            );
        }
        if ($settlement < $end) {
            throw new \InvalidArgumentException(
                'Konečné vyúčtování příjmů nemůže předcházet konci účasti.',
            );
        }
        $oneMonthAfter = self::addMonthClamped($settlement)->format('Y-m-d');
        $outerBound = sprintf('%04d-01-31', $year + 1);

        return $this->window(
            $settlement->format('Y-m-d'),
            min($oneMonthAfter, $outerBound),
            self::TERMINATION_RULESET,
            'termination_one_month_after_settlement_capped_31_january',
            'termination',
            'Zákon č. 582/1991 Sb., § 38 a § 39 — skončí-li účast na důchodovém '
                . 'pojištění v průběhu roku, vyhotovuje se evidenční list do jednoho '
                . 'měsíce po konečném vyúčtování příjmů, nejpozději do 31. ledna '
                . 'následujícího roku.',
        );
    }

    /**
     * Přípustnost samostatného evidenčního listu za daný rok.
     *
     * @return array{allowed:bool,reason:string,rule:string}
     */
    public static function standaloneStatementAllowed(
        int $year,
        ?string $participationEndOn,
        bool $requestedByAuthority,
    ): array {
        if ($year < 2026) {
            return [
                'allowed' => true,
                'reason' => 'Za roky před 1. 1. 2026 vede a předkládá evidenční list zaměstnavatel.',
                'rule' => 'transitional_before_2026',
            ];
        }
        if ($requestedByAuthority) {
            return [
                'allowed' => true,
                'reason' => 'Evidenční list se sestavuje na výzvu ČSSZ/ÚSSZ.',
                'rule' => 'on_authority_request',
            ];
        }
        if ($year === 2026
            && $participationEndOn !== null
            && $participationEndOn < '2026-04-01'
        ) {
            return [
                'allowed' => true,
                'reason' => 'Účast na důchodovém pojištění skončila před 1. 4. 2026.',
                'rule' => 'transitional_participation_ended_before_april_2026',
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'Od roku 2026 sestavuje evidenční list ČSSZ z údajů měsíčního hlášení; '
                . 'samostatný evidenční list se vede jen při skončení účasti před 1. 4. 2026 '
                . 'nebo na výzvu ČSSZ/ÚSSZ.',
            'rule' => 'assembled_by_cssz_from_monthly_report',
        ];
    }

    /**
     * @param 'annual'|'termination' $statementKind
     */
    private function window(
        string $earliestSubmissionOn,
        string $dueOn,
        string $rulesetId,
        string $rule,
        string $statementKind,
        string $legalBasis,
    ): EldpDeadlineWindow {
        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if (!$dueDate instanceof \DateTimeImmutable) {
            throw new \LogicException('Termín ELDP není platné datum.');
        }
        $shiftedDueOn = CzechWorkingDays::shiftToWorkingDay($dueDate)
            ->format('Y-m-d');
        $rulesetHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'eldp-deadline-policy.v1',
            'ruleset_id' => $rulesetId,
            'rule' => $rule,
            'statement_kind' => $statementKind,
            'due_shift' => 'next_czech_working_day',
            'calendar_basis' => 'business_days',
            'sources' => self::SOURCES,
        ]));

        return new EldpDeadlineWindow(
            $earliestSubmissionOn,
            $shiftedDueOn,
            'business_days',
            $rulesetId,
            $rulesetHash,
            $statementKind,
            $legalBasis,
        );
    }

    /**
     * Lhůta určená podle měsíců končí dnem, který se pojmenováním shoduje se
     * dnem, kdy začala; není-li takový den v měsíci, končí posledním dnem
     * měsíce. Prosté `+1 month` v PHP místo toho přeteče do dalšího měsíce
     * (31. 8. → 1. 10.), a to by lhůtu prodloužilo o den.
     */
    private static function addMonthClamped(
        \DateTimeImmutable $date,
    ): \DateTimeImmutable {
        $shifted = $date->modify('+1 month');
        if ($shifted->format('d') !== $date->format('d')) {
            return $date->modify('first day of next month')
                ->modify('last day of this month');
        }

        return $shifted;
    }

    private static function assertYear(int $year): void
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException(
                'Rok evidenčního listu musí být v rozsahu 2000 až 2100.',
            );
        }
    }

    private static function date(string $value, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException("{$label} není platné datum.");
        }

        return $date;
    }
}
