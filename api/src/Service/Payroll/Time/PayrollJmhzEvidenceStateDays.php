<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

/**
 * Počet dní v evidenčním stavu zaměstnanců (atribut 10265 měsíčního hlášení).
 *
 * Pokyny MPSV k vyplnění MH 1.4.13 mluví o dnech, „ve kterých byl zaměstnanec
 * v daném pracovním … poměru v evidenčním stavu zaměstnanců". Co evidenční
 * stav je, pokyny nedefinují; oba vlastníci atributu (MPSV a ČSÚ) to na dotazy
 * vyložili shodně:
 * - helpdesk MPSV: „Atribut počet dní v evidenčním stavu se řídí platnou
 *   metodikou ČSÚ pro evidenční počet zaměstnanců", a k zaměstnankyni celý
 *   měsíc na rodičovské: „protože není v evidenčním stavu zaměstnanců, tak tam
 *   se dny MD, RD a OD odečítají" (10265 = 0, fondy 10259 a 10260 plné);
 * - ČSÚ: MD, RD a OD se v 10259 a 10260 neodečítají, „evidenční stav se
 *   v JMHZ promítá do atributu 10265"; metodika evidenčního počtu se týká
 *   jen zaměstnanců v pracovním poměru.
 *
 * Odečítají se proto kalendářní dny mateřské (v aplikaci `ppm`), rodičovské
 * a otcovské, a jen u pracovního poměru. Neplacené volno ani nemoc evidenční
 * stav nemění. Fondy 10259 a 10260 se počítají dál z celého trvání vztahu.
 *
 * Jediné místo pravidla: návrh pracovního souhrnu i kontrola v ELDP builderu
 * volají tuhle třídu, aby se návrh a jeho ověření nemohly rozejít.
 */
final class PayrollJmhzEvidenceStateDays
{
    /** Druhy nepřítomnosti, po které zaměstnanec není v evidenčním stavu. */
    public const OUTSIDE_EVIDENCE_STATE_TYPES = ['ppm', 'parental', 'paternity'];

    /**
     * Dny intervalu `[$from, $to]` (včetně obou), po které zaměstnanec byl
     * v evidenčním stavu. Překrývající se nepřítomnosti se počítají jednou.
     *
     * @param list<array<string,mixed>> $absences
     */
    public static function days(mixed $relationType, string $from, string $to, array $absences): int
    {
        return self::inclusiveDays($from, $to)
            - self::outsideDays($relationType, $from, $to, $absences);
    }

    /**
     * Dny intervalu, po které pracovní poměr v evidenčním stavu nebyl.
     *
     * @param list<array<string,mixed>> $absences
     */
    public static function outsideDays(mixed $relationType, string $from, string $to, array $absences): int
    {
        if ($relationType !== 'employment' || $to < $from) {
            return 0;
        }
        $outside = [];
        foreach ($absences as $absence) {
            if (!in_array($absence['absence_type'] ?? null, self::OUTSIDE_EVIDENCE_STATE_TYPES, true)) {
                continue;
            }
            $absenceFrom = self::date($absence['date_from'] ?? null);
            $absenceTo = self::date($absence['date_to'] ?? null);
            if ($absenceFrom === null || $absenceTo === null) {
                continue;
            }
            $cursor = new \DateTimeImmutable(max($absenceFrom, $from));
            $last = min($absenceTo, $to);
            while ($cursor->format('Y-m-d') <= $last) {
                $outside[$cursor->format('Y-m-d')] = true;
                $cursor = $cursor->modify('+1 day');
            }
        }

        return count($outside);
    }

    private static function inclusiveDays(string $from, string $to): int
    {
        if ($to < $from) {
            return 0;
        }

        return (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
