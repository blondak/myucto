<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;

/**
 * Význam mzdové složky, nepřítomnosti a srážky POHODA Mzdy / PAMICA pro import
 * docházky a mezd MyÚčta. Klasifikace se řídí číslem složky (skupina podle první
 * číslice katalogu) a u víceúčelových složek (O01, J03) i jejím názvem.
 *
 * Co import MyÚčta nepřebírá (základní mzdu počítá ze sjednané mzdy vztahu, náhrady
 * z hodin a průměru, odstupné a zákonné položky jinak), vrací význam `ignore` - takový
 * sloupec do sešitu pro import vůbec nejde.
 */
final class PohodaPayrollCatalog
{
    /** Standardní složka příplatku za noční práci (JMHZ 10334), stejná jako ve vzoru GIRITON. */
    public const NIGHT_PREMIUM = 'PRIPLATEK_NOCNI';

    /**
     * Zdanitelná část závodního stravování: složka výchozího číselníku se zařazením
     * do JMHZ 10328. Vlastní `PAM_*` by pro totéž plnění vyrobila druhou složku bez
     * zařazení, rozdělila by úhrn v hlášení a zmrazení podání by na ní spadlo.
     */
    public const TAXABLE_MEAL = 'STRAVOVANI_ZDANITELNE';

    /** Odměna za kontejnery, tatáž složka jako ve vzoru GIRITON (druh `bonus`, JMHZ 10331). */
    public const CONTAINER_BONUS = 'ODMENA_KONTEJNERY';

    /**
     * @return array{meaning:string,kind:?string,code:?string,header:string}
     */
    public static function component(string $number, string $name, bool $sharedNumber): array
    {
        $number = strtoupper(trim($number));
        $label = trim("{$number} {$name}");
        $code = 'PAM_' . preg_replace('/[^A-Z0-9]/', '', $number) . ($sharedNumber ? '_' . self::slug($name, 20) : '');
        $normalized = AttendanceText::normalize($name);
        $ignore = ['meaning' => 'ignore', 'kind' => null, 'code' => null, 'header' => $label];
        $component = static fn (string $kind): array => ['meaning' => 'component', 'kind' => $kind, 'code' => $code, 'header' => "{$label} (Kč)"];

        if (in_array($number, ['M01', 'M09'], true)) {
            return $ignore;
        }
        if ($number === 'C01') {
            return $component('hourly_wage');
        }
        if (in_array($number, ['C02', 'U01', 'U02', 'U03', 'U04'], true)) {
            return $component('task_wage');
        }
        // Mzda za odpracovaný přesčas, ne příplatek za něj: ten vede PAMICA zvlášť pod P01.
        // Druh složky proto nese tarifní mzdu, ze které plyne zařazení do JMHZ 10329.
        if (in_array($number, ['M02', 'C08'], true)) {
            return $component('hourly_wage');
        }
        if ($number === 'U05') {
            return $component('task_wage');
        }
        // Příplatek za noční práci (P07 i pojmenovaná O01) jde na standardní složku
        // PRIPLATEK_NOCNI (JMHZ 10334), stejně jako u vzoru GIRITON. Jeden sloupec, aby
        // se oba zdroje v měsíci sečetly.
        if ($number === 'P07' || (str_starts_with($number, 'O') && preg_match('/nocni|\bnoc\b/', $normalized) === 1)) {
            return ['meaning' => 'component', 'kind' => 'premium', 'code' => self::NIGHT_PREMIUM, 'header' => 'Příplatek za noční práci (Kč)'];
        }
        if (str_starts_with($number, 'P')) {
            return $component('premium');
        }
        if (str_starts_with($number, 'O')) {
            // Plnění, která už v číselníku svou složku mají, jdou na ni: jedna složka
            // pro jedno plnění, a se zařazením do JMHZ rovnou od založení.
            if (preg_match('/obed|strav/', $normalized) === 1) {
                return ['meaning' => 'component', 'kind' => 'other', 'code' => self::TAXABLE_MEAL, 'header' => 'Zdanitelná část stravování (Kč)'];
            }
            if (str_contains($normalized, 'kontejner')) {
                return ['meaning' => 'component', 'kind' => 'bonus', 'code' => self::CONTAINER_BONUS, 'header' => 'Odměna za kontejnery (Kč)'];
            }
            // Doplatek, dorovnání i placená doba školení jsou mzda za práci (JMHZ 10329),
            // ne odměna ani příplatek. Příspěvek sem nepatří: z názvu nejde poznat, jestli
            // je to mzdové plnění, nebo benefit, a zařazení zůstává na účetní.
            if (preg_match('/doplatek|dorovnani|skoleni/', $normalized) === 1) {
                return $component('hourly_wage');
            }
            if (preg_match('/odmen|bonus|premi/', $normalized) === 1) {
                return $component('bonus');
            }
            if (preg_match('/priplat|pripl|bozp/', $normalized) === 1) {
                return $component('premium');
            }
            return $component('other');
        }
        if ($number === 'J11') {
            return $component('compensation');
        }
        if ($number === 'J03' && str_contains($normalized, 'obed')) {
            return ['meaning' => 'meal', 'kind' => null, 'code' => null, 'header' => 'Obědy - srážka ze mzdy (Kč)'];
        }
        return $ignore;
    }

    /** @return array{meaning:string,header:string} */
    public static function absence(string $number, string $name): array
    {
        $number = strtoupper(trim($number));
        $normalized = AttendanceText::normalize($name);

        return match (true) {
            $number === 'V01' => ['meaning' => 'vacation_hours', 'header' => 'Dovolená (h)'],
            $number === 'V02' => ['meaning' => 'holiday_hours', 'header' => 'Svátek (h)'],
            $number === 'V03' && str_contains($normalized, 'lekar') => ['meaning' => 'doctor_hours', 'header' => 'Lékař (h)'],
            $number === 'V03' => ['meaning' => 'obstacle_employee_hours', 'header' => 'Placené volno (h)'],
            $number === 'V04' => ['meaning' => 'unpaid_leave_hours', 'header' => 'Neplacené volno (h)'],
            $number === 'V05' => ['meaning' => 'unexcused_hours', 'header' => 'Neomluvená absence (h)'],
            str_starts_with($number, 'V06') => ['meaning' => 'obstacle_employer_hours', 'header' => 'Překážka na straně zaměstnavatele (h)'],
            in_array($number, ['H01', 'H02', 'H03', 'H04'], true) => ['meaning' => 'sick_hours', 'header' => 'Nemoc (h)'],
            in_array($number, ['H05', 'H06'], true) => ['meaning' => 'care_hours', 'header' => 'OČR (h)'],
            $number === 'H15' => ['meaning' => 'paternity_hours', 'header' => 'Otcovská (h)'],
            default => ['meaning' => 'ignore', 'header' => trim("Nepřítomnost {$number}")],
        };
    }

    /** @return array{meaning:string,header:string} */
    public static function deduction(string $number): array
    {
        return strtoupper(trim($number)) === 'S07'
            ? ['meaning' => 'net_other_deduction', 'header' => 'Srážka ze mzdy (Kč)']
            : ['meaning' => 'ignore', 'header' => 'Srážka ' . strtoupper(trim($number))];
    }

    /** @return array{meaning:string,header:string}|null hodiny ze složek, které MyÚčto vede jako druh práce */
    public static function workHours(string $number): ?array
    {
        return match (strtoupper(trim($number))) {
            'P01', 'P13' => ['meaning' => 'overtime_hours', 'header' => 'Přesčas (h)'],
            'P07' => ['meaning' => 'night_hours', 'header' => 'Noční práce (h)'],
            'P04' => ['meaning' => 'weekend_hours', 'header' => 'Práce o víkendu (h)'],
            'P03' => ['meaning' => 'holiday_work_hours', 'header' => 'Práce ve svátek (h)'],
            default => null,
        };
    }

    private static function slug(string $text, int $max): string
    {
        $ascii = strtoupper(AttendanceText::normalize($text));
        return substr(trim((string) preg_replace('/[^A-Z0-9]+/', '_', $ascii), '_'), 0, $max);
    }
}
