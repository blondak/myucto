<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Registrační a oznamovací evidence mezd ze zálohy PREMIER, přečtená po pracovních
 * vztazích (`PERSONAL.INTER`). Nic nezapisuje; do kanonické podoby převzatých mezd ji
 * přeloží {@see PremierPayrollTakeover}.
 *
 * Tabulky:
 *  - `MZ_JMHZ` / `MZ_JMHZ2` - podání JMHZ (hlavička s měsícem `X10010` a rokem `X10011`)
 *    a formulář za vztah (`INT_ZAM`): OIČ `X10051`, ID pracovněprávního vztahu `X10228`,
 *    místo výkonu práce `X10229` (obec), `X10230` (kód obce), `X10231` (stát), druh
 *    činnosti `X10239`, stanovená týdenní doba `X10261`, stav přijetí `XPRIJATO_Z`.
 *    Platí poslední formulář vztahu (nejpozdější měsíc, v něm přijatý před nepřijatým).
 *  - `MZ_ISPV` - údaje ISPV: `KZAM` je pětimístný kód CZ-ISCO (ověřeno agregovaně na
 *    reálné záloze: všech 45 vyplněných kódů je aktivních v číselníku CZ-ISCO aplikace;
 *    `ISCO` je prázdné a `CZICSE` je kategorie zaměstnance ISPV, ne ISCO).
 *  - `MZ_PRIZP` - oznámení zdravotní pojišťovně (`KOD` P přihláška, O odhláška, Q/M
 *    změna pojišťovny) s příznakem přijetí `PRIJATO` a dnem `PRIJ_DAT`.
 *  - `MZ_PRISO` - oznámení ČSSZ o nástupu (`KOD` 1) a skončení (2) s přijetím.
 *  - `MZ_ELDP` - evidenční listy důchodového pojištění (`ROK`, `PRIJATO`, `PRIJ_DAT`).
 *
 * Stav přijetí `XPRIJATO_Z` = 3 se bere jako „formulář přijala ČSSZ": je to jediná
 * nenulová hodnota u formulářů za vztah (0 = neodesláno nebo bez odpovědi) a nese ji
 * většina formulářů podání, které PREMIER vede jako podané. Jistota střední: číselník
 * stavů PREMIER v záloze není.
 */
final class PremierPayrollRegistry
{
    private const ACCEPTED = 3;

    /**
     * @return array<int,array<string,mixed>> INTER => evidence vztahu (klíče `jmhz`, `cz_isco`,
     *     `health_notices`, `social_notices`, `eldp`)
     */
    public static function read(PremierBackup $backup): array
    {
        $out = [];
        foreach (self::jmhz($backup) as $inter => $form) {
            $out[$inter]['jmhz'] = $form;
        }
        foreach ($backup->rows('MZ_ISPV') as $row) {
            $code = self::text($row['KZAM'] ?? '');
            if (preg_match('/^[0-9]{5}$/D', $code) === 1) {
                $out[(int) ($row['INTER'] ?? 0)]['cz_isco'] = $code;
            }
        }
        foreach ($backup->rows('MZ_PRIZP') as $row) {
            $out[(int) ($row['INTER'] ?? 0)]['health_notices'][] = [
                'kind' => strtoupper(self::text($row['KOD'] ?? '')),
                'date' => self::date($row['HLAS_OD'] ?? null),
                'accepted' => ($row['PRIJATO'] ?? false) === true,
                'accepted_on' => self::date($row['PRIJ_DAT'] ?? null),
            ];
        }
        foreach ($backup->rows('MZ_PRISO') as $row) {
            $out[(int) ($row['INTER'] ?? 0)]['social_notices'][] = [
                'kind' => self::text($row['KOD'] ?? ''),
                'accepted' => ($row['PRIJATO'] ?? false) === true,
                'accepted_on' => self::date($row['PRIJ_DAT'] ?? null),
            ];
        }
        foreach ($backup->rows('MZ_ELDP') as $row) {
            $year = (int) self::text($row['ROK'] ?? '');
            if ($year < 1990 || ($row['PRIJATO'] ?? false) !== true) {
                continue;
            }
            $inter = (int) ($row['INTER'] ?? 0);
            if (($out[$inter]['eldp']['year'] ?? 0) <= $year) {
                $out[$inter]['eldp'] = ['year' => $year, 'accepted_on' => self::date($row['PRIJ_DAT'] ?? null)];
            }
        }
        return $out;
    }

    /**
     * Poslední formulář JMHZ každého vztahu.
     *
     * @return array<int,array{period:string,accepted:bool,oic:?string,id_ppv:?string,municipality:?string,municipality_code:?string,
     *     country:?string,activity:?string,weekly_hours:?float}>
     */
    private static function jmhz(PremierBackup $backup): array
    {
        $periods = [];
        foreach ($backup->rows('MZ_JMHZ') as $row) {
            $year = (int) ($row['X10011'] ?? 0);
            $month = (int) ($row['X10010'] ?? 0);
            if ($year >= 2000 && $month >= 1 && $month <= 12) {
                $periods[self::text($row['ID'] ?? '')] = sprintf('%04d-%02d', $year, $month);
            }
        }
        $best = [];
        foreach ($backup->rows('MZ_JMHZ2') as $row) {
            $inter = (int) ($row['INT_ZAM'] ?? 0);
            $period = $periods[self::text($row['ID_JMHZ'] ?? '')] ?? null;
            if ($inter <= 0 || $period === null) {
                continue;
            }
            $accepted = (int) ($row['XPRIJATO_Z'] ?? 0) === self::ACCEPTED;
            $rank = [$period, $accepted ? 1 : 0, self::text($row['TS'] ?? '')];
            if (isset($best[$inter]) && $best[$inter]['rank'] > $rank) {
                continue;
            }
            $oic = self::digits($row['X10051'] ?? null);
            $municipalityCode = self::text($row['X10230'] ?? '');
            $country = strtoupper(self::text($row['X10231'] ?? ''));
            $weekly = (float) ($row['X10261'] ?? 0);
            $best[$inter] = ['rank' => $rank, 'form' => [
                'period' => $period,
                'accepted' => $accepted,
                // OIČ je číslo (N(12)): úvodní nuly se ztratily, kontrolní číslice je na konci.
                'oic' => $oic === null ? null : str_pad($oic, 10, '0', STR_PAD_LEFT),
                'id_ppv' => self::digits($row['X10228'] ?? null),
                'municipality' => self::text($row['X10229'] ?? '') ?: null,
                'municipality_code' => preg_match('/^[0-9]{6}$/D', $municipalityCode) === 1 ? $municipalityCode : null,
                'country' => preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : null,
                'activity' => strtoupper(self::text($row['X10239'] ?? '')) ?: null,
                'weekly_hours' => $weekly > 0 && $weekly <= 168 ? round($weekly, 2) : null,
            ]];
        }
        return array_map(static fn (array $b): array => $b['form'], $best);
    }

    private static function digits(mixed $value): ?string
    {
        $text = is_float($value) ? sprintf('%.0f', $value) : self::text($value);
        $text = (string) preg_replace('/\s+/', '', $text);
        return preg_match('/^[0-9]{1,22}$/D', $text) === 1 && ltrim($text, '0') !== '' ? $text : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    private static function date(mixed $value): ?string
    {
        $v = self::text($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && (int) substr($v, 0, 4) >= 1901 ? $v : null;
    }
}
