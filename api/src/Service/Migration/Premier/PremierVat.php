<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier;

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
 *
 * Význam řádků drží {@see VatReturnLineClassifier}; tady je jen čtení číselníku PREMIER.
 */
final class PremierVat
{
    public const RATE_BASE = VatReturnLineClassifier::RATE_BASE;
    public const RATE_REDUCED = VatReturnLineClassifier::RATE_REDUCED;

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
        return VatReturnLineClassifier::rateClass($c['lines']);
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
        return $lines === null ? null : VatReturnLineClassifier::saleFromLineSet($lines);
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
        return $c === null ? null : VatReturnLineClassifier::purchaseFromLineSet($c['lines'], $c['reduced']);
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
        return VatReturnLineClassifier::domesticRate($class, $date);
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
