<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Číslo rozhodnutí o dočasné pracovní neschopnosti a o potřebě ošetřování
 * (NEMPRI `cisloRozhodnuti`, HZUPN `cisloPotvrzeni`).
 *
 * Elektronické číslo je pořadové číslo PČ (10 znaků, `RRMMDDNNNN`, u ePortálu
 * `6ppppppppp`, u ostatních dávek s písmenem druhu dávky na konci), případně
 * s předsazeným IČPE lékaře (8 číslic, dohromady 18 znaků). Papírové číslo
 * ČPN je písmeno a 6 až 7 číslic.
 *
 * Tvar podle druhu dávky v NEMPRI drží {@see SicknessBenefitKind}; tady žije
 * to, co sdílí obě věty: IČPE s kontrolou 8. číslice (Luhn, chyba DIS 22,
 * kontrola 17 LK) a formát „od r. 2020" pro HZUPN (kontrola 33 LK).
 */
final class SicknessDecisionNumber
{
    private const ICPE_LENGTH = 8;
    private const SERIAL_LENGTH = 10;

    /** První pořadové číslo formátu od r. 2020 (kontrola 33 LK). */
    private const FIRST_SERIAL_SINCE_2020 = 2001010000;

    /** IČPE, je-li číslo ve tvaru IČPE + PČ (18 znaků), jinak `null`. */
    public static function icpe(string $number): ?string
    {
        if (strlen($number) !== self::ICPE_LENGTH + self::SERIAL_LENGTH) {
            return null;
        }
        $icpe = substr($number, 0, self::ICPE_LENGTH);

        return ctype_digit($icpe) ? $icpe : null;
    }

    /** Kontrola 8. číslice IČPE Luhnovým algoritmem (LK kontrola 17). */
    public static function icpeChecksumValid(string $icpe): bool
    {
        if (strlen($icpe) !== self::ICPE_LENGTH || !ctype_digit($icpe)) {
            return false;
        }
        $sum = 0;
        foreach (array_reverse(str_split($icpe)) as $position => $digit) {
            $value = (int) $digit;
            if ($position % 2 === 1) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }
            $sum += $value;
        }

        return $sum % 10 === 0;
    }

    /**
     * Elektronické číslo rozhodnutí u nemocenského: PČ, případně s IČPE.
     * Jen s ním je u NEM platební spojení povinné.
     */
    public static function isElectronicSickness(string $number): bool
    {
        return preg_match('/^(?:\d{' . self::ICPE_LENGTH . '})?\d{' . self::SERIAL_LENGTH . '}$/D', $number) === 1;
    }

    /**
     * Co je špatně s číslem rozhodnutí v HZUPN podle kontroly 33 LK: HZUPN
     * (typ dokumentu 128) musí nést formát od r. 2020, tedy ČPN s písmenem
     * E až Z kromě K, nebo PČ >= 2001010000 (i s IČPE). Formát do r. 2020
     * (písmeno A až D, PČ < 2001010000) ČSSZ odmítne. `null` = v pořádku.
     *
     * @return array{code:string,message:string}|null
     */
    public static function hzupnProblem(string $number): ?array
    {
        if (preg_match('/^[E-JL-Z]\d{6,7}$/D', $number) === 1) {
            return null;
        }
        if (self::isElectronicSickness($number)) {
            $serial = (int) substr($number, -self::SERIAL_LENGTH);
            if ($serial < self::FIRST_SERIAL_SINCE_2020) {
                return self::formatProblem();
            }

            return self::icpeProblem($number, 'hzupn_confirmation_number_icpe_invalid');
        }

        return self::formatProblem();
    }

    /**
     * IČPE předsazené číslu rozhodnutí neprošlo kontrolou 8. číslice.
     *
     * @return array{code:string,message:string}|null
     */
    public static function icpeProblem(string $number, string $code): ?array
    {
        $icpe = self::icpe($number);
        if ($icpe === null || self::icpeChecksumValid($icpe)) {
            return null;
        }

        return [
            'code' => $code,
            'message' => 'IČPE lékaře na začátku čísla rozhodnutí (' . $icpe . ') neprošlo kontrolou '
                . '8. číslice (Luhnův algoritmus), ČSSZ by podání odmítla chybou 22. Opište číslo '
                . 'rozhodnutí z eNeschopenky znovu.',
        ];
    }

    /** @return array{code:string,message:string} */
    private static function formatProblem(): array
    {
        return [
            'code' => 'hzupn_confirmation_number_format_invalid',
            'message' => 'Číslo rozhodnutí v hlášení HZUPN musí mít formát platný od roku 2020: '
                . 'písmeno E až Z (kromě K) a 6 až 7 číslic, nebo elektronické pořadové číslo '
                . '(10 číslic od 2001010000, případně s předsazeným osmimístným IČPE). Starší '
                . 'formát ČSSZ u HZUPN odmítne (kontrola 33).',
        ];
    }
}
