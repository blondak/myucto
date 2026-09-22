<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Údaje karty osoby ze zálohy PREMIER, které nese mzdový modul vedle osoby a vztahu:
 * děti a daňové zvýhodnění, pobírání důchodu a historie výplatního účtu. Nic nezapisuje;
 * do kanonické podoby převzatých mezd je přeloží {@see PremierPayrollTakeover}.
 *
 * ── Děti (`PER_DETI`, `MZ_DETI`) ──────────────────────────────────────────────
 * `PER_DETI` je dítě (`ID`, jméno, rodné číslo `BRC_1` + `BRC_2`, narození `BDNAR`),
 * `MZ_DETI` měsíční uplatnění zvýhodnění (`DITE_ID`, `DITE_POR` pořadí, `DI_SLE` částka,
 * `DITE_ROK`/`DITE_MES`, osoba `ZAM_SUPID` = `PER_MAIN.ID`). Nárok a jeho pořadí se berou
 * ze skutečného uplatnění: první a poslední měsíc s kladnou částkou.
 *
 * Vazba dítěte na osobu je `PER_DETI.INTER` = `PER_MAIN.SUP_INTER` (ověřeno agregovaně na
 * reálné záloze: u všech dětí s uplatněním sedí na osobu z `MZ_DETI`, `PERSONAL.INTER`
 * jen u poloviny). U uplatněného dítěte rozhoduje osoba z `MZ_DETI`.
 *
 * Význam příznaků karty dítěte odvozený z dat (číselník PREMIER v záloze není):
 *  - `BABY_POR` N = bez zvýhodnění, 1/2/3 = pořadí; souhlasí s `MZ_DETI.DITE_POR`.
 *  - `BABY_SLE` = „uplatňuje zvýhodnění" v daném období, NE průkaz ZTP/P: v měsících,
 *    kdy platí (historie `PER_DETH`), je uplatněná částka přesně zákonné zvýhodnění
 *    daného pořadí (u ZTP/P by byla dvojnásobná), kde neplatí, je částka nulová.
 *    Jistota vysoká.
 *  - `BVYZI` = nejspíš „dítě vyživuje i jiná osoba"; zvýhodnění se v jeho platnosti
 *    uplatňuje dál v plné výši, takže nárok neruší. Formulář JMHZ (`X10453`) mu ale
 *    neodpovídá. Jistota nízká: převod ho nezapisuje a protokol ho hlásí k ověření.
 *
 * ── Důchod (`MZ_DUCHOD`) ───────────────────────────────────────────────────────
 * Pobírání důchodu vztahu (`INTER`) od `DAT_PR_OD`, druh `D_KATE2` (8 = starobní podle
 * kategorií ČSSZ). Je to evidence pobírání, ne žádost o slevu na pojistném; uplatněnou
 * slevu nese měsíc mzdy (`MZDY.SLEVA_SOC`).
 *
 * ── Historie účtu (`MZ_PERH`) ──────────────────────────────────────────────────
 * Změny atributů vztahu (`MZ_HINTER` = `INTER`) s platností `PLATN_OD_R`/`PLATN_OD_M`;
 * výplatní účet nesou údaje `banka_ucet` a `banka_kod`.
 */
final class PremierPayrollPersonCard
{
    /**
     * @return array{
     *     children: array<string,list<array<string,mixed>>>,
     *     pensions: array<int,array{from:?string,kind:string}>,
     *     accounts: array<int,list<array{account:string,bank_code:string,from:string}>>
     * }
     */
    public static function read(PremierBackup $backup): array
    {
        return [
            'children' => self::children($backup),
            'pensions' => self::pensions($backup),
            'accounts' => self::accountHistory($backup),
        ];
    }

    /**
     * Děti po osobách (`PER_MAIN.ID`).
     *
     * @return array<string,list<array{id:string,given_name:?string,family_name:?string,birth_number:?string,birth_date:?string,
     *     periods:array<string,int>,other_caregiver:bool}>>
     */
    private static function children(PremierBackup $backup): array
    {
        $bySupInter = [];
        foreach ($backup->rows('PER_MAIN') as $row) {
            $supInter = (int) ($row['SUP_INTER'] ?? 0);
            if ($supInter > 0) {
                $bySupInter[$supInter] = self::text($row['ID'] ?? '');
            }
        }
        $byInter = [];
        foreach ($backup->rows('PERSONAL') as $row) {
            $byInter[(int) ($row['INTER'] ?? 0)] = self::text($row['SUP_ID'] ?? '');
        }
        /** @var array<string,array{person:string,periods:array<string,int>}> $applied */
        $applied = [];
        foreach ($backup->rows('MZ_DETI') as $row) {
            $year = (int) ($row['DITE_ROK'] ?? 0);
            $month = (int) ($row['DITE_MES'] ?? 0);
            $order = (int) self::text($row['DITE_POR'] ?? '');
            if ($year < 1990 || $month < 1 || $month > 12 || $order < 1 || $order > 3 || (float) ($row['DI_SLE'] ?? 0) <= 0) {
                continue;
            }
            $id = self::text($row['DITE_ID'] ?? '');
            $applied[$id]['person'] = self::text($row['ZAM_SUPID'] ?? '');
            $applied[$id]['periods'][sprintf('%04d-%02d', $year, $month)] = $order;
        }
        $out = [];
        foreach ($backup->rows('PER_DETI') as $row) {
            $id = self::text($row['ID'] ?? '');
            if ($id === '') {
                continue;
            }
            $inter = (int) ($row['INTER'] ?? 0);
            $person = $applied[$id]['person'] ?? null;
            $person = $person !== null && $person !== '' ? $person : ($bySupInter[$inter] ?? $byInter[$inter] ?? null);
            if ($person === null || $person === '') {
                continue;
            }
            $periods = $applied[$id]['periods'] ?? [];
            ksort($periods);
            $first = self::text($row['BRC_1'] ?? '');
            $second = self::text($row['BRC_2'] ?? '');
            $out[$person][] = [
                'id' => $id,
                'given_name' => self::limited($row['BJMENO'] ?? '', 96),
                'family_name' => self::limited($row['BPRIJMENI'] ?? '', 96),
                'birth_number' => preg_match('/^[0-9]{6}$/D', $first) === 1 && preg_match('/^[0-9]{3,4}$/D', $second) === 1 ? $first . '/' . $second : null,
                'birth_date' => self::date($row['BDNAR'] ?? null),
                'periods' => $periods,
                'other_caregiver' => ($row['BVYZI'] ?? false) === true,
            ];
        }
        return $out;
    }

    /** @return array<int,array{from:?string,kind:string}> */
    private static function pensions(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('MZ_DUCHOD') as $row) {
            $out[(int) ($row['INTER'] ?? 0)] = ['from' => self::date($row['DAT_PR_OD'] ?? null), 'kind' => self::text($row['D_KATE2'] ?? '')];
        }
        return $out;
    }

    /**
     * Výplatní účty vztahu podle historie změn, v pořadí platnosti.
     *
     * @return array<int,list<array{account:string,bank_code:string,from:string}>>
     */
    private static function accountHistory(PremierBackup $backup): array
    {
        $parts = [];
        foreach ($backup->rows('MZ_PERH') as $row) {
            $key = self::text($row['UDAJ'] ?? '');
            if (!in_array($key, ['banka_ucet', 'banka_kod'], true)) {
                continue;
            }
            $inter = (int) ($row['MZ_HINTER'] ?? 0);
            $year = (int) ($row['PLATN_OD_R'] ?? 0);
            $month = (int) ($row['PLATN_OD_M'] ?? 0);
            if ($inter <= 0 || $year < 1990 || $month < 1 || $month > 12) {
                continue;
            }
            $parts[$inter][sprintf('%04d-%02d-01', $year, $month)][$key] = self::text($row['HODNOTAC'] ?? '');
        }
        $out = [];
        foreach ($parts as $inter => $versions) {
            ksort($versions);
            $account = null;
            $bank = null;
            foreach ($versions as $from => $values) {
                $account = $values['banka_ucet'] ?? $account;
                $bank = $values['banka_kod'] ?? $bank;
                $digits = (string) preg_replace('/[\s-]/', '', (string) $account);
                if (preg_match('/^[0-9]{1,22}$/D', $digits) !== 1 || preg_match('/^[0-9]{4}$/D', (string) $bank) !== 1) {
                    continue;
                }
                $list = $out[$inter] ?? [];
                $last = $list === [] ? null : $list[array_key_last($list)];
                if ($last !== null && $last['account'] === $account && $last['bank_code'] === $bank) {
                    continue;
                }
                $out[$inter][] = ['account' => (string) $account, 'bank_code' => (string) $bank, 'from' => $from];
            }
        }
        return $out;
    }

    private static function limited(mixed $value, int $max): ?string
    {
        $value = self::text($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
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
