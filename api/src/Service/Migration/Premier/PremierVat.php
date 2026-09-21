<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Kódy DPH z PREMIER (`KODY_DPH`) převedené na zařazení dokladu v MyÚčtu.
 *
 * Kódy si účetní jednotka v PREMIER upravuje i zakládá (čísla `15`, `36` jsou jen výchozí
 * nastavení), proto se nepárují podle čísla ani názvu, ale podle toho, co kód v číselníku
 * nese: **řádky přiznání DPH** ve sloupcích aktuálního tiskopisu (`R17`, `R17B`, `R17C` -
 * PREMIER přidává sadu sloupců s každou novou verzí DPHDP3 a platí ta s nejvyšším číslem),
 * směr (`FA_IN`/`FA_OUT`), přenesení daňové povinnosti (`IS_REVERS`), krácený odpočet
 * (`IS_KRACENY`) a třídu sazby (`SAZBA` 1 = snížená, 2 = základní, 3 = `JINA_SAZBA`).
 *
 *   uskutečněná plnění   ř. 1 / 2 tuzemsko (kód podle sazby), + ř. 51 prodej majetku mimo
 *                        koeficient, 20 dodání zboží do EU, 21 služba do EU, 22 vývoz,
 *                        23 nový dopravní prostředek, 24 zasílání zboží, 25 tuzemský přenos
 *                        (dodavatel), 26 plnění s místem mimo tuzemsko, 31 třístranný obchod,
 *                        50 osvobozené bez nároku, 50 + 51 osvobozené mimo koeficient
 *   přijatá plnění       ř. 40 / 41 tuzemský odpočet, + ř. 47 pořízení majetku;
 *                        samovyměření = výstupní řádek + odpočet ř. 43 / 44: 3/4 zboží z EU,
 *                        5/6 služba z EU, 7/8 dovoz zboží, 10/11 tuzemský přenos (příjemce),
 *                        12/13 ostatní plnění ze zahraničí; bez ř. 43/44 = bez nároku na odpočet
 *   bez řádku            mimo přiznání (vydaný doklad s daní posoudí politika OSS)
 *
 * **Samovyměření se odvozuje jen z kódu, ne z deníku.** Jestli účetní daň na výstupu
 * a odpočet zaúčtovala na 343 (a jakou analytikou), nebo je nechala jen v evidenci DPH,
 * na zařazení nemá vliv - evidence DPH MyÚčta obojí dopočte z kódu zařazení a základu.
 *
 * Kódy jiných řádků (dovoz celním úřadem ř. 42, opravy § 44 a § 74, vypořádání
 * koeficientu, korekce odpočtu…) převod nezařazuje a doklad nechá jako koncept
 * k ruční kontrole.
 */
final class PremierVat
{
    public const RATE_BASE = 'base';
    public const RATE_REDUCED = 'reduced';

    /** Výstupní řádky samovyměření (sudý = snížená sazba) → kód zařazení přijatého dokladu. */
    private const SELF_ASSESSMENT = [3 => '23', 4 => '23', 5 => '24e', 6 => '24e', 7 => '25', 8 => '25', 10 => '5', 11 => '5', 12 => '24', 13 => '24'];

    /** Řádky přiznání v základní a ve snížené sazbě (výstup, samovyměření, odpočet). */
    private const BASE_LINES = [1, 3, 5, 7, 10, 12, 40, 43];
    private const REDUCED_LINES = [2, 4, 6, 8, 11, 13, 41, 44];

    /** Řádky přiznání, které převod u vydaného dokladu zná (bez tuzemských 1, 2, 51). */
    private const SALE_CODES = [20 => '20', 21 => '22', 22 => '26', 23 => '23n', 24 => '24z', 25 => '25s', 26 => '26s', 31 => '31', 50 => '3'];

    /** @param array<string,array{lines:list<int>,name:string,purchase:bool,sale:bool,reverse:bool,reduced:bool,rate_class:?string,other_rate:float,kh:string,kh_small:string}> $codes */
    private function __construct(private readonly array $codes) {}

    public static function fromBackup(PremierBackup $backup): self
    {
        $rows = $backup->all('KODY_DPH');
        return self::fromRows($rows);
    }

    /** @param list<array<string,mixed>> $rows */
    public static function fromRows(array $rows): self
    {
        $columns = self::lineColumns($rows);
        $codes = [];
        foreach ($rows as $r) {
            $code = trim((string) ($r['KOD_DPH'] ?? ''));
            if ($code === '' || isset($codes[$code])) {
                continue;
            }
            $lines = [];
            foreach ($columns as $column) {
                $line = (int) ($r[$column] ?? 0);
                if ($line > 0) {
                    $lines[] = $line;
                }
            }
            $lines = array_values(array_unique($lines));
            sort($lines);
            $class = (int) ($r['SAZBA'] ?? 0);
            $codes[$code] = [
                'lines' => $lines,
                'name' => trim((string) ($r['TEXT'] ?? '')),
                'purchase' => (bool) ($r['FA_IN'] ?? false),
                'sale' => (bool) ($r['FA_OUT'] ?? false),
                'reverse' => (bool) ($r['IS_REVERS'] ?? false),
                'reduced' => (bool) ($r['IS_KRACENY'] ?? false),
                'rate_class' => match ($class) { 1 => self::RATE_REDUCED, 2 => self::RATE_BASE, default => null },
                'other_rate' => $class === 3 ? (float) ($r['JINA_SAZBA'] ?? 0) : 0.0,
                'kh' => self::section((string) ($r['TAB_FA'] ?? '')),
                'kh_small' => self::section((string) ($r['TAB_FANE'] ?? '')),
            ];
        }
        return new self($codes);
    }

    public function known(string $code): bool
    {
        return isset($this->codes[$code]);
    }

    public function name(string $code): string
    {
        $name = $this->codes[$code]['name'] ?? '';
        return $name !== '' ? $name : $code;
    }

    /** @return list<int> */
    public function lines(string $code): array
    {
        return $this->codes[$code]['lines'] ?? [];
    }

    /**
     * Je kód kódem přijatého plnění? Rozhoduje příznak v číselníku; kód bez příznaku
     * (vypořádání, korekce) podle odpočtových řádků 40-47.
     */
    public function isPurchase(string $code): ?bool
    {
        $c = $this->codes[$code] ?? null;
        if ($c === null) {
            return null;
        }
        if ($c['purchase'] !== $c['sale']) {
            return $c['purchase'];
        }
        foreach ($c['lines'] as $line) {
            if ($line >= 40 && $line <= 47) {
                return true;
            }
        }
        return $c['lines'] !== [] ? false : null;
    }

    /** Třída sazby, kterou kód nese (u samovyměření podle řádku 43 = základní, 44 = snížená). */
    public function rateClass(string $code): ?string
    {
        $c = $this->codes[$code] ?? null;
        if ($c === null) {
            return null;
        }
        if ($c['rate_class'] !== null) {
            return $c['rate_class'];
        }
        foreach ($c['lines'] as $line) {
            if (in_array($line, self::BASE_LINES, true)) {
                return self::RATE_BASE;
            }
            if (in_array($line, self::REDUCED_LINES, true)) {
                return self::RATE_REDUCED;
            }
        }
        return null;
    }

    /** Jiná (historická) sazba kódu v %, 0 = kód ji neurčuje. */
    public function otherRate(string $code): float
    {
        return (float) ($this->codes[$code]['other_rate'] ?? 0.0);
    }

    /**
     * Vydaný doklad.
     *
     * @return array{in_return:bool,code:?string,domestic:bool,asset_sale:bool}|null null = převod kód nezařadí;
     *         `code` null u tuzemského plnění = kód podle sazby (1 / 2)
     */
    public function sale(string $code): ?array
    {
        $lines = $this->codes[$code]['lines'] ?? null;
        if ($lines === null) {
            return null;
        }
        if ($lines === []) {
            return ['in_return' => false, 'code' => null, 'domestic' => false, 'asset_sale' => false];
        }
        $domestic = array_values(array_intersect($lines, [1, 2]));
        $rest = array_values(array_diff($lines, [1, 2, 51]));
        if ($domestic !== [] && $rest === []) {
            return ['in_return' => true, 'code' => null, 'domestic' => true, 'asset_sale' => in_array(51, $lines, true)];
        }
        if ($lines === [50, 51]) {
            return ['in_return' => true, 'code' => '3m', 'domestic' => false, 'asset_sale' => false];
        }
        if (count($lines) === 1 && isset(self::SALE_CODES[$lines[0]])) {
            return ['in_return' => true, 'code' => self::SALE_CODES[$lines[0]], 'domestic' => false, 'asset_sale' => false];
        }
        return null;
    }

    /**
     * Přijatý doklad.
     *
     * @return array{in_return:bool,deduction:'full'|'reduced'|'none',reverse:bool,code:?string,fixed_asset:bool}|null
     *         `code` null u tuzemského odpočtu = kód podle sazby (40 / 41)
     */
    public function purchase(string $code): ?array
    {
        $c = $this->codes[$code] ?? null;
        if ($c === null) {
            return null;
        }
        $lines = $c['lines'];
        if ($lines === []) {
            return ['in_return' => false, 'deduction' => 'none', 'reverse' => false, 'code' => null, 'fixed_asset' => false];
        }
        $asset = in_array(47, $lines, true);
        $deduction = $c['reduced'] ? 'reduced' : 'full';
        $rest = array_values(array_diff($lines, [47]));
        $output = array_values(array_filter($rest, static fn (int $l): bool => isset(self::SELF_ASSESSMENT[$l])));
        $claim = array_values(array_intersect($rest, [43, 44]));
        $domestic = array_values(array_intersect($rest, [40, 41]));
        $unknown = array_values(array_diff($rest, $output, $claim, $domestic));
        if ($unknown !== []) {
            return null;
        }
        if ($output !== []) {
            $codes = array_unique(array_map(static fn (int $l): string => self::SELF_ASSESSMENT[$l], $output));
            if (count($codes) !== 1 || $domestic !== []) {
                return null;
            }
            return [
                'in_return' => true,
                'deduction' => $claim !== [] ? $deduction : 'none',
                'reverse' => true,
                'code' => $codes[0],
                'fixed_asset' => $asset,
            ];
        }
        if ($claim !== []) {
            // Odpočet ze samovyměření bez výstupního řádku - PREMIER to umí jen u kódu,
            // který daň na výstupu vede jinde. Převod to neodhaduje.
            return null;
        }
        if ($domestic !== []) {
            return ['in_return' => true, 'deduction' => $deduction, 'reverse' => false, 'code' => null, 'fixed_asset' => $asset];
        }
        return null;
    }

    /**
     * Kód, který PREMIER vykazuje v KH vždy v A.5 / B.3 (souhrnně), bez ohledu na částku
     * dokladu - MyÚčto rozhoduje podle DIČ ve snapshotu dokladu a limitu 10 000 Kč.
     */
    public function forcesSummaryKh(string $code): bool
    {
        return in_array($this->codes[$code]['kh'] ?? '', ['A.5', 'B.3'], true);
    }

    /**
     * Tuzemská sazba třídy k datu plnění (%). Pro doklady, u kterých ji nejde spočítat
     * z daně (samovyměření, nulová daň) ani ji nenese položka.
     */
    public static function rateFor(string $class, string $date): float
    {
        if ($class === self::RATE_BASE) {
            return match (true) {
                $date >= '2013-01-01' => 21.0,
                $date >= '2010-01-01' => 20.0,
                default => 19.0,
            };
        }
        return match (true) {
            $date >= '2024-01-01' => 12.0,
            $date >= '2013-01-01' => 15.0,
            $date >= '2012-01-01' => 14.0,
            $date >= '2010-01-01' => 10.0,
            $date >= '2008-01-01' => 9.0,
            default => 5.0,
        };
    }

    /**
     * Sloupce řádků přiznání nejnovějšího tiskopisu v číselníku (`R17`, `R17B`, `R17C`).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private static function lineColumns(array $rows): array
    {
        $names = $rows !== [] ? array_keys($rows[0]) : [];
        $best = null;
        foreach ($names as $name) {
            if (preg_match('/^R(\d{2})$/', (string) $name, $m) === 1) {
                $best = max($best ?? 0, (int) $m[1]);
            }
        }
        if ($best === null) {
            return [];
        }
        $prefix = sprintf('R%02d', $best);
        return array_values(array_filter($names, static fn (string $n): bool => preg_match('/^' . $prefix . '[A-Z]?$/', $n) === 1));
    }

    private static function section(string $value): string
    {
        return rtrim(strtoupper(trim($value)), '. ');
    }
}
