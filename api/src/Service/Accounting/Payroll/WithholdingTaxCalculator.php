<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Payroll;

/**
 * Srážková daň ze samostatného základu — § 6 odst. 4, § 7 odst. 6 a § 36 ZDP.
 *
 * Mzdový modul znal jen zálohovou daň ze závislé činnosti, takže firma se zaměstnanci
 * na dohodu o provedení práce musela mzdy počítat mimo systém. Audit to vedl mezi
 * vysokými riziky.
 *
 * ── Proč samostatná třída, a ne větev v PayrollCalculator ───────────────────────────
 * Srážková daň není varianta zálohové daně, ale SAMOSTATNÝ ZÁKLAD DANĚ. Neuplatňují se
 * na ni slevy (§ 35ba), nevstupuje do ročního zúčtování ani do přiznání a nesnižuje se
 * o pojistné. Vměstnat ji do {@see PayrollCalculator} jako příznak by znamenalo protáhnout
 * celým výpočtem větve, které se navzájem vylučují — a hlavně by hrozilo, že se na
 * srážkový základ omylem uplatní sleva, což zákon nedovoluje.
 *
 * ── Kdy se použije ──────────────────────────────────────────────────────────────────
 *   § 6/4  DPP do 10 000 Kč měsíčně od JEDNOHO zaměstnavatele BEZ podepsaného
 *          prohlášení k dani. Tatáž hranice je od 1. 1. 2024 i limitem pro odvody
 *          na sociální a zdravotní pojištění z DPP — do limitu se neodvádí.
 *   § 7/6  autorský honorář do 10 000 Kč měsíčně od jednoho plátce.
 *   § 36   příjmy nerezidentů (sazba se řídí smlouvou o zamezení dvojího zdanění;
 *          tu systém nezná, proto se bere zákonná sazba a hlásí se to).
 *
 * Překročení limitu NENÍ „o kolik víc" — daní se běžným režimem CELÁ částka, ne jen
 * část nad limitem. Proto {@see applies()} vrací bool, ne poměr.
 */
final class WithholdingTaxCalculator
{
    /** Důvody srážky — určují limit i to, co se hlásí. */
    public const REASON_DPP = 'dpp';
    public const REASON_AUTHOR_FEE = 'author_fee';
    public const REASON_NON_RESIDENT = 'non_resident';

    /**
     * Důvod srážky plynoucí ze samotného typu pracovněprávního vztahu, nebo `null`,
     * když se z tohohle titulu srážková daň neuplatní vůbec.
     *
     * ── Proč to je pojmenované pravidlo, a ne podmínka v jednom volajícím ───────────
     * Výčet `payroll_employees.employment_type` roste (1156 hpp/dpp/dpc, 1302
     * statutory_body) a jediná hodnota, která srážku zakládá, je DPP. Odměna člena
     * statutárního orgánu je příjem podle § 6 odst. 1 písm. c) ZDP ze smlouvy o výkonu
     * funkce — NE příjem z dohody o provedení práce — a daní se VŽDY zálohou, i když
     * je nízká; § 6 odst. 4 na ni nedopadá. Kdyby byla podmínka psaná negací
     * („všechno kromě pracovního poměru je dohoda"), spadl by výkon funkce pod limitem
     * do srážky a poplatník by přišel o slevy i o roční zúčtování.
     *
     * Proto whitelist s jednou položkou: nový typ vztahu se do srážkového režimu
     * nedostane, dokud ho sem někdo vědomě nedopíše.
     */
    public static function reasonForEmploymentType(string $employmentType): ?string
    {
        return $employmentType === 'dpp' ? self::REASON_DPP : null;
    }

    /**
     * Posoudí, jestli se na odměnu srážková daň vůbec vztahuje.
     *
     * @param array<string,mixed> $c roční daňové konstanty
     */
    public static function applies(
        string $reason,
        float $amount,
        array $c,
        bool $taxDeclarationSigned = false,
    ): bool {
        if ($amount <= 0) {
            return false;
        }

        return match ($reason) {
            // Podepsané prohlášení sráta vylučuje — příjem jde do zálohové daně, i když
            // je pod limitem. Bez téhle podmínky by se zaměstnanci s prohlášením upřely
            // slevy na dani.
            self::REASON_DPP => !$taxDeclarationSigned
                && $amount <= self::limit($c, 'dpp_withholding_limit'),
            self::REASON_AUTHOR_FEE => $amount <= self::limit($c, 'author_fee_withholding_limit'),
            // U nerezidenta limit není — rozhoduje daňová rezidence, ne výše příjmu.
            self::REASON_NON_RESIDENT => true,
            default => throw new \InvalidArgumentException('Neznámý důvod srážky: ' . $reason),
        };
    }

    /**
     * Výpočet srážky. Základ se podle § 36 odst. 3 zaokrouhluje na celé koruny dolů,
     * daň na celé koruny dolů.
     *
     * @param array<string,mixed> $c roční daňové konstanty
     * @return array{
     *   reason:string, gross:int, base:int, rate:float, tax:int, net:int,
     *   insurance_applies:bool, warnings:list<string>
     * }
     */
    public static function compute(string $reason, float $amount, array $c, bool $taxDeclarationSigned = false): array
    {
        if (!self::applies($reason, $amount, $c, $taxDeclarationSigned)) {
            throw new \InvalidArgumentException(
                'Na tuto odměnu se srážková daň nevztahuje — použij zálohovou daň (PayrollCalculator).'
            );
        }

        $rate = (float) ($c['withholding_rate'] ?? 0.15);
        if ($rate <= 0) {
            throw new \InvalidArgumentException('Roční konstanty neobsahují sazbu srážkové daně.');
        }

        $base = (int) floor($amount);
        $tax = (int) floor($base * $rate);
        $warnings = [];

        if ($reason === self::REASON_NON_RESIDENT) {
            $warnings[] = 'Sazba u nerezidenta se může řídit smlouvou o zamezení dvojího zdanění '
                . '(často 5 nebo 10 % místo zákonných ' . rtrim(rtrim(number_format($rate * 100, 2, ',', ' '), '0'), ',')
                . ' %). Systém smlouvy nezná — ověřte sazbu podle státu rezidence příjemce.';
        }
        if ($reason === self::REASON_DPP) {
            // § 6 odst. 4 písm. a) mluví o ÚHRNNÉ výši u téhož plátce za kalendářní měsíc.
            // Systém drží jeden mzdový záznam na zaměstnance a měsíc, takže úhrn musí
            // zadat uživatel — dvě odměny po 8 000 Kč jsou 16 000 Kč a srážková daň se
            // neuplatní, ačkoli ani jedna sama limit nepřekročí. Původní znění varovalo
            // jen na souběh u JINÝCH zaměstnavatelů, tedy na jedinou mezeru, kterou
            // systém zavřít nemůže, a mlčelo o té, kterou zavřít lze.
            $warnings[] = 'Limit ' . number_format(self::limit($c, 'dpp_withholding_limit'), 0, ',', ' ')
                . ' Kč platí na ÚHRN odměn od jednoho zaměstnavatele za kalendářní měsíc '
                . '(§ 6 odst. 4 ZDP). Vyplácíte-li v témž měsíci víc dohod, zadejte jejich '
                . 'součet — systém drží jeden mzdový záznam na měsíc. Souběh u JINÉHO '
                . 'zaměstnavatele posuďte sami, ten systém nevidí.';
        }

        return [
            'reason' => $reason,
            'gross'  => (int) round($amount),
            'base'   => $base,
            'rate'   => $rate,
            'tax'    => $tax,
            'net'    => $base - $tax,
            // Do limitu se z DPP neodvádí pojistné (od 1. 1. 2024); u honoráře
            // a nerezidenta se pojistné z titulu srážky neodvádí vůbec.
            'insurance_applies' => false,
            'warnings' => $warnings,
        ];
    }

    /**
     * Odměna, která limit PŘEKROČILA, se celá daní běžným režimem — ne jen část nad
     * limitem. Tahle metoda existuje kvůli srozumitelnému hlášení, ne kvůli výpočtu.
     *
     * @param array<string,mixed> $c
     */
    public static function overLimitReason(string $reason, float $amount, array $c): ?string
    {
        $key = match ($reason) {
            self::REASON_DPP => 'dpp_withholding_limit',
            self::REASON_AUTHOR_FEE => 'author_fee_withholding_limit',
            default => null,
        };
        if ($key === null) {
            return null;
        }
        $limit = self::limit($c, $key);
        if ($amount <= $limit) {
            return null;
        }

        return sprintf(
            'Odměna %s Kč přesahuje limit %s Kč — srážkovou daní se nedaní ani část do limitu, '
                . 'zdaňuje se celá částka běžným režimem.',
            number_format($amount, 2, ',', ' '),
            number_format($limit, 0, ',', ' '),
        );
    }

    /** @param array<string,mixed> $c */
    private static function limit(array $c, string $key): float
    {
        $v = $c[$key] ?? null;
        if ($v === null) {
            throw new \InvalidArgumentException('Roční konstanty neobsahují limit `' . $key . '`.');
        }

        return (float) $v;
    }
}
