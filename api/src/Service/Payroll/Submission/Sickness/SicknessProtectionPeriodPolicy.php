<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Ochranná lhůta podle § 15 zákona č. 187/2006 Sb., o nemocenském pojištění.
 *
 * ## Doslovné znění, ze kterého se počítá
 *
 * **§ 15 odst. 1** — „Nemocenské náleží též, jestliže ke vzniku dočasné
 * pracovní neschopnosti (§ 57) nebo k nařízení karantény (§ 105) došlo po
 * zániku pojištění v ochranné lhůtě. Ochranná lhůta činí 7 kalendářních dnů
 * ode dne zániku pojištění; pokud však pojištění trvalo kratší dobu, činí
 * ochranná lhůta jen tolik kalendářních dnů, kolik dnů pojištění trvalo."
 *
 * **§ 15 odst. 2** — peněžitá pomoc v mateřství náleží i při nástupu v ochranné
 * lhůtě; u ženy, jejíž pojištění zaniklo v době těhotenství, činí 180
 * kalendářních dnů, jinak 7 dnů (obojí nejvýš tolik, kolik trvalo pojištění).
 *
 * **§ 15 odst. 4** — ochranná lhůta mimo jiné neplyne ze zaměstnání malého
 * rozsahu (písm. c), ze zaměstnání žáka nebo studenta výlučně v době prázdnin
 * (písm. d) a z dohody o provedení práce (písm. f).
 *
 * Jiné dávky (ošetřovné, dlouhodobé ošetřovné, otcovská, vyrovnávací příspěvek)
 * ochrannou lhůtu nemají — vznikne-li jejich sociální událost až po skončení
 * zaměstnání, zaměstnavatel žádost nepředává, protože nárok z jeho pojištění
 * nevznikl.
 *
 * ## Co politika vědomě nerozhoduje
 *
 * Těhotenství v den skončení zaměstnání a pobírání starobního či invalidního
 * důchodu třetího stupně (§ 15 odst. 4 písm. a) aplikace nedrží jako ověřený
 * fakt. U PPM se proto připouští celých 180 dnů a obrazovka říká, že delší
 * lhůta platí jen u ženy těhotné při skončení; důchod se u případu vyplňuje
 * pro NEMPRI a jeho druh se tu neposuzuje.
 */
final class SicknessProtectionPeriodPolicy
{
    public const STATUS_DURING_EMPLOYMENT = 'during_employment';
    public const STATUS_PROTECTION_PERIOD = 'protection_period';

    public const NEM_DAYS = 7;
    public const PPM_PREGNANCY_DAYS = 180;

    public const LEGAL_REFERENCE = '§ 15 zákona č. 187/2006 Sb.';

    /**
     * Posoudí, zda sociální událost vznikla za trvání zaměstnání, nebo
     * v ochranné lhůtě. Mimo obojí případ neexistuje a politika to odmítne
     * s konkrétním důvodem.
     *
     * @param array<string,mixed> $context fakta vztahu (start_date,
     *        actual_start_date, end_date, relation_type)
     * @param array<string,mixed> $row řádek případu (small_scope_income_minor,
     *        is_student, within_school_holidays); u zakládání vstup formuláře
     * @return array{status:string,employment_end:?string,protection_until:?string,legal_reference:string}
     */
    public function assess(
        SicknessBenefitKind $kind,
        string $eventFrom,
        array $context,
        array $row = [],
    ): array {
        $end = self::nullableText($context['end_date'] ?? null);
        if ($end === null || $eventFrom <= $end) {
            return [
                'status' => self::STATUS_DURING_EMPLOYMENT,
                'employment_end' => $end,
                'protection_until' => null,
                'legal_reference' => self::LEGAL_REFERENCE,
            ];
        }

        $baseDays = match ($kind) {
            SicknessBenefitKind::Nem => self::NEM_DAYS,
            SicknessBenefitKind::Ppm => self::PPM_PREGNANCY_DAYS,
            default => null,
        };
        if ($baseDays === null) {
            throw new SicknessException(
                'sickness_event_after_employment',
                sprintf(
                    'Sociální událost vznikla %s, tedy po skončení zaměstnání %s. %s ochrannou '
                    . 'lhůtu nemá (§ 15 zák. č. 187/2006 Sb. ji zná jen u nemocenského a peněžité '
                    . 'pomoci v mateřství), takže z tohoto vztahu nárok nevznikl a žádost se '
                    . 'nepředává. Zkontrolujte den vzniku události nebo den skončení vztahu.',
                    $eventFrom,
                    $end,
                    self::kindLabel($kind),
                ),
            );
        }

        $exclusion = $this->exclusion($context, $row);
        if ($exclusion !== null) {
            throw new SicknessException(
                'sickness_protection_period_excluded',
                sprintf(
                    'Sociální událost vznikla %s, po skončení zaměstnání %s, a z tohoto vztahu '
                    . 'ochranná lhůta neplyne: %s (§ 15 odst. 4 zák. č. 187/2006 Sb.). '
                    . 'Nárok z tohoto zaměstnání nevznikl.',
                    $eventFrom,
                    $end,
                    $exclusion,
                ),
            );
        }

        $start = self::nullableText($context['actual_start_date'] ?? null)
            ?? self::nullableText($context['start_date'] ?? null);
        $days = $baseDays;
        if ($start !== null && $start <= $end) {
            $insuredDays = (int) (new \DateTimeImmutable($start))
                ->diff(new \DateTimeImmutable($end))
                ->days + 1;
            $days = min($days, $insuredDays);
        }
        // „ode dne zániku pojištění“ — pojištění zaniká dnem skončení
        // zaměstnání, lhůta tedy běží ode dne následujícího.
        $until = (new \DateTimeImmutable($end))
            ->modify('+' . $days . ' days')
            ->format('Y-m-d');
        if ($eventFrom > $until) {
            throw new SicknessException(
                'sickness_event_outside_protection_period',
                sprintf(
                    'Sociální událost vznikla %s, ale ochranná lhůta po skončení zaměstnání %s '
                    . 'trvala jen do %s (%d kalendářních dnů podle § 15 zák. č. 187/2006 Sb.). '
                    . 'Nárok z tohoto zaměstnání nevznikl, takže se nic nepředává. Zkontrolujte '
                    . 'den vzniku události.',
                    $eventFrom,
                    $end,
                    $until,
                    $days,
                ),
            );
        }

        return [
            'status' => self::STATUS_PROTECTION_PERIOD,
            'employment_end' => $end,
            'protection_until' => $until,
            'legal_reference' => self::LEGAL_REFERENCE,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $row
     */
    private function exclusion(array $context, array $row): ?string
    {
        if (($context['relation_type'] ?? null) === 'dpp') {
            return 'jde o dohodu o provedení práce (písm. f)';
        }
        $smallScope = $row['small_scope_income_minor'] ?? null;
        if ($smallScope !== null && $smallScope !== '') {
            return 'jde o zaměstnání malého rozsahu (písm. c)';
        }
        if (self::truthy($row['is_student'] ?? null)
            && self::truthy($row['within_school_holidays'] ?? null)
        ) {
            return 'jde o zaměstnání studenta výlučně v době prázdnin (písm. d)';
        }

        return null;
    }

    private static function kindLabel(SicknessBenefitKind $kind): string
    {
        return match ($kind) {
            SicknessBenefitKind::Ose => 'Ošetřovné',
            SicknessBenefitKind::Dlo => 'Dlouhodobé ošetřovné',
            SicknessBenefitKind::Opp => 'Otcovská',
            SicknessBenefitKind::Vpm => 'Vyrovnávací příspěvek',
            SicknessBenefitKind::Nem => 'Nemocenské',
            SicknessBenefitKind::Ppm => 'Peněžitá pomoc v mateřství',
        };
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function nullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
