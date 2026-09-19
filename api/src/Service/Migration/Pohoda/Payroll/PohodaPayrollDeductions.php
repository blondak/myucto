<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;

/**
 * Trvalé srážky, exekuce a insolvence z `91_mzdy.xml` (POHODA Mzdy / PAMICA).
 *
 * Měsíční sešity převodu ({@see PohodaPayrollConverter}) berou ze srážek jen částku
 * složky `S07`, kterou pak import docházky založí jako dohodu o srážkách za daný měsíc.
 * Celá evidence za tou částkou - exekuční příkaz, jeho pořadí, počet vyživovaných osob,
 * zbývající jistina a příjemce - je v `ZAMsrazky` na kartě zaměstnance a v `MZsrazky`
 * u jednotlivých mezd; ta se sem čte.
 *
 * Třída jen čte, normalizuje a klasifikuje, nic nezapisuje
 * ({@see PohodaPayrollDeductionsWriter}).
 *
 * **Klasifikace jde z číselníku `sMZsrazky`, ne z kódů konkrétní instalace.** Číselník
 * nese dva příznaky, na kterých rozhodnutí stojí:
 *
 * - `JeZak` = zákonná srážka (exekuce, insolvence, přikázání jiné peněžité pohledávky) -
 *   patří do exekučního případu ({@see PohodaPayrollDeductionsWriter}),
 * - `JeDepon` = deponovaná částka zákonné srážky, tedy stav téže srážky, ne vlastní titul.
 *
 * Insolvenci od exekuce číselník žádným příznakem NEODLIŠUJE (obojí je `JeZak`), takže
 * jediné vodítko je název položky - proto se pro ni hledá slovo „insolven“ nebo „oddluž“
 * v čísle i názvu. Kódy `S01a` / `S01b` / `S07` viděné na jedné instalaci se jako kontrakt
 * neberou: číslo složky si uživatel v PAMICA přidává a přepisuje.
 *
 * @phpstan-type Recipient array{name:string,reference:?string,ico:?string,account:?string,bank_code:?string,variable_symbol:?string,specific_symbol:?string,constant_symbol:?string}
 */
final class PohodaPayrollDeductions
{
    /** Tabulky, které se čtou celé do paměti (jednotky až stovky řádků). */
    private const TABLES = ['ZAM', 'ZAMpomer', 'sMZsrazky', 'ZAMsrazky'];

    /** Insolvence v názvu druhu srážky; číselník pro ni vlastní příznak nemá. */
    private const INSOLVENCY = '/insolven|oddluz/';

    /** Druh pohledávky `RelDrSra` => kategorie pohledávky MyÚčta. */
    private const PRIORITY_KIND = [
        '3' => 'other_priority',
        '4' => 'non_priority',
    ];

    /**
     * Titul dobrovolné srážky podle názvu druhu (bez diakritiky, malá písmena).
     * Pořadí rozhoduje: první shoda vyhrává, takže „záloha na obědy“ je oběd.
     */
    private const VOLUNTARY_KINDS = [
        '/obed|strav/' => 'meal',
        '/penzij|pripojist|zivotni pojist|dlouhodobe pece|\bdip\b|\bdps\b|\bpdp\b/' => 'contribution',
        '/zaloh/' => 'advance',
        '/skod/' => 'damage',
    ];

    /**
     * Srážky převedeného roku, v pořadí osobních čísel.
     *
     * Vrací i to, co se zapsat nedá (`target` = `null`), aby to protokol mohl vypsat -
     * tiché zahození srážky je horší než řádek k ručnímu dořešení.
     *
     * @return array{deductions:list<array<string,mixed>>,protected_amount_inputs:int}
     */
    public static function read(string $file, int $year): array
    {
        $byId = [];
        foreach (self::TABLES as $table) {
            foreach (PohodaXml::records($file, $table) as $row) {
                $byId[$table][PohodaXml::text($row, 'ID')] = $row;
            }
        }
        $relationCount = [];
        foreach ($byId['ZAMpomer'] ?? [] as $relation) {
            $person = PohodaXml::text($relation, 'RefZAM');
            $relationCount[$person] = ($relationCount[$person] ?? 0) + 1;
        }
        /** @var array<string,list<string>> $personalNumbers osoba => osobní čísla jejích vztahů */
        $personalNumbers = [];
        foreach ($byId['ZAMpomer'] ?? [] as $relation) {
            $personId = PohodaXml::text($relation, 'RefZAM');
            $person = $byId['ZAM'][$personId] ?? null;
            if ($person === null) {
                continue;
            }
            $personalNumbers[$personId][] = PohodaPayrollPeople::personalNumber($person, $relation, $relationCount[$personId] ?? 1);
        }

        /** @var array<string,array{person:string,period:string}> $payslips mzda => osoba a období */
        $payslips = [];
        $firstPeriod = null;
        foreach (PohodaXml::records($file, 'MZ') as $mz) {
            $month = (int) PohodaXml::text($mz, 'RelMes');
            if ((int) PohodaXml::text($mz, 'Rok') !== $year || $month < 1 || $month > 12) {
                continue;
            }
            $period = sprintf('%04d-%02d', $year, $month);
            $firstPeriod = $firstPeriod === null || $period < $firstPeriod ? $period : $firstPeriod;
            $payslips[PohodaXml::text($mz, 'ID')] = ['person' => PohodaXml::text($mz, 'RefZAM'), 'period' => $period];
        }

        /** @var array<string,array{withheld:float,periods:array<string,bool>,ids:list<string>}> $fromPayslips karta => sraženo ve mzdách */
        $fromPayslips = [];
        /** @var array<string,array{row:array<string,mixed>,person:string,withheld:float,periods:array<string,bool>,ids:list<string>}> $orphans */
        $orphans = [];
        foreach (PohodaXml::records($file, 'MZsrazky') as $row) {
            $payslip = $payslips[PohodaXml::text($row, 'RefAg')] ?? null;
            if ($payslip === null) {
                continue;
            }
            $id = PohodaXml::text($row, 'ID');
            $withheld = PohodaXml::num($row, 'KcSrazeno');
            $card = PohodaXml::text($row, 'RefZAMsrazky');
            if ($card !== '' && isset($byId['ZAMsrazky'][$card])) {
                $entry = $fromPayslips[$card] ?? ['withheld' => 0.0, 'periods' => [], 'ids' => []];
                $entry['withheld'] += $withheld;
                $entry['periods'][$payslip['period']] = true;
                $entry['ids'][] = $id;
                $fromPayslips[$card] = $entry;
                continue;
            }
            // Srážka jen ve mzdě, bez trvalé srážky na kartě. Vlastní záznam z ní vznikne
            // až níž a jen tam, kde by se jinak ztratila; seskupuje se podle osoby, druhu
            // a čísla rozhodnutí, aby z dvanácti měsíců nevzniklo dvanáct případů.
            $key = implode('|', [
                $payslip['person'],
                PohodaXml::text($row, 'RefSlozka'),
                PohodaXml::text($row, 'PlRozhod'),
                PohodaXml::text($row, 'DatPoradi'),
            ]);
            $entry = $orphans[$key] ?? ['row' => $row, 'person' => $payslip['person'], 'withheld' => 0.0, 'periods' => [], 'ids' => []];
            $entry['withheld'] += $withheld;
            $entry['periods'][$payslip['period']] = true;
            $entry['ids'][] = $id;
            $orphans[$key] = $entry;
        }

        $records = [];
        foreach ($byId['ZAMsrazky'] ?? [] as $id => $row) {
            $personId = PohodaXml::text($row, 'RefAg');
            $monthly = $fromPayslips[(string) $id] ?? ['withheld' => 0.0, 'periods' => [], 'ids' => []];
            $evidence = ['ZAMsrazky:' . $id];
            foreach ($monthly['ids'] as $monthlyId) {
                $evidence[] = 'MZsrazky:' . $monthlyId;
            }
            $records[] = self::record(
                $row,
                $byId['sMZsrazky'][PohodaXml::text($row, 'RefSlozka')] ?? [],
                $personId,
                $personalNumbers[$personId] ?? [],
                'pamica:zamsrazky:' . $id,
                $evidence,
                PohodaXml::num($row, 'KcSrazeno') + $monthly['withheld'],
                array_keys($monthly['periods']),
                $firstPeriod,
            );
        }
        foreach ($orphans as $key => $entry) {
            $catalog = $byId['sMZsrazky'][PohodaXml::text($entry['row'], 'RefSlozka')] ?? [];
            $evidence = [];
            foreach ($entry['ids'] as $monthlyId) {
                $evidence[] = 'MZsrazky:' . $monthlyId;
            }
            $records[] = self::record(
                $entry['row'],
                $catalog,
                $entry['person'],
                $personalNumbers[$entry['person']] ?? [],
                'pamica:mzsrazky:' . hash('sha256', $key),
                $evidence,
                $entry['withheld'],
                array_keys($entry['periods']),
                $firstPeriod,
            );
        }
        usort($records, static function (array $a, array $b): int {
            return [$a['personal_numbers'][0] ?? '', $a['reference']] <=> [$b['personal_numbers'][0] ?? '', $b['reference']];
        });

        return [
            'deductions' => $records,
            // Podklady pro nezabavitelnou částku (`rpZAMprijemSraz`) se jen počítají:
            // MyÚčto vede u měsíce jen přebití nezabavitelné částky, ne jiné příjmy
            // povinného, ze kterých ji PAMICA počítá. Odvozovat jedno z druhého by bylo
            // dopočítání cizího výpočtu, ne převod údaje.
            'protected_amount_inputs' => self::protectedAmountInputs($file),
        ];
    }

    /**
     * Jeden normalizovaný záznam srážky. `$row` je řádek `ZAMsrazky` nebo `MZsrazky` -
     * obě tabulky mají pro srážku shodné sloupce.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $catalog
     * @param list<string> $personalNumbers
     * @param list<string> $evidence
     * @param list<string> $periods
     * @return array<string,mixed>
     */
    private static function record(
        array $row,
        array $catalog,
        string $personId,
        array $personalNumbers,
        string $reference,
        array $evidence,
        float $withheld,
        array $periods,
        ?string $firstPeriod,
    ): array {
        sort($periods);
        $class = self::classify($catalog);
        $total = PohodaXml::num($row, 'KcCelkem');
        $maintenance = PohodaXml::num($row, 'KcVyzivPuv');
        $validFrom = self::realDate(PohodaXml::date($row, 'DatOd'))
            ?? ($periods !== [] ? $periods[0] . '-01' : ($firstPeriod === null ? null : $firstPeriod . '-01'));
        $statutory = $class['target'] === 'enforcement' || $class['target'] === 'insolvency';

        return [
            'person_key' => $personId,
            'personal_numbers' => $personalNumbers,
            'reference' => $reference,
            'target' => $class['target'],
            'target_reason' => $class['reason'],
            'code' => $class['code'],
            'name' => $class['name'],
            'title' => self::limited(trim($class['code'] . ' ' . $class['name']) ?: 'Srážka z PAMICA', 190),
            'valid_from' => $validFrom,
            'valid_to' => self::realDate(PohodaXml::date($row, 'DatDo')),
            // Pořadí exekuce: `DatPoradi` je den, kterým PAMICA pořadí určuje, a MyÚčto
            // z něj odvozuje `priority_date` (§ 280 odst. 3 o. s. ř.). `Poradi` je jen
            // pořadové číslo srážky na kartě, do exekučního pořadí nevstupuje.
            'priority_date' => self::realDate(PohodaXml::date($row, 'DatPoradi')),
            'priority_no' => (int) PohodaXml::text($row, 'Poradi'),
            'category' => $statutory ? self::category($row, $maintenance, $class['target']) : null,
            'maintenance_weight_minor' => $maintenance > 0 ? self::minor($maintenance) : null,
            'total_minor' => self::minor($total),
            'withheld_minor' => self::minor($withheld),
            // Zbývající jistina: celková pohledávka snížená o už sražené. Nula znamená
            // buď doplacenou, nebo otevřenou pohledávku bez stanovené celkové výše
            // (typicky běžné výživné) - obojí projde, MyÚčto nulu připouští.
            'outstanding_minor' => max(0, self::minor($total) - self::minor($withheld)),
            'monthly_minor' => self::minor(PohodaXml::num($row, 'KcMesic')),
            'basis_points' => PohodaXml::num($row, 'Proc') > 0 ? (int) round(PohodaXml::num($row, 'Proc') * 100) : null,
            'dependants' => max(0, (int) PohodaXml::text($row, 'PocOsob')),
            'joint_discharge' => self::bool(PohodaXml::text($row, 'SpolecOddluzeni')),
            // Deponování se bere z číselníku (`JeDepon`), ne ze sloupce `RelDepon` řádku:
            // ten má v exportu hodnotu 1 i u srážek, které deponované nejsou, takže
            // žádný signál nenese.
            'deferred' => $class['deferred'],
            'deduction_kind' => $class['target'] === 'voluntary' ? $class['kind'] : null,
            /*
             * Dobrovolná srážka, kterou nese měsíční sešit převodu: import docházky z ní
             * dělá dohodu o srážkách za každý převedený měsíc, takže druhý zápis by ji
             * z čisté mzdy strhl dvakrát. Rozhoduje {@see PohodaPayrollCatalog::deduction()},
             * aby pravidlo mělo jediný zdroj. Podmínka „byla ve mzdě“ tam patří: trvalá
             * srážka, která se v převedených měsících nesrážela, v sešitech není a bez
             * záznamu tady by se ztratila.
             */
            'carried_by_attendance' => $class['target'] === 'voluntary' && $periods !== []
                && PohodaPayrollCatalog::deduction($class['code'])['meaning'] !== 'ignore',
            'recipient' => self::recipient($row),
            'periods' => $periods,
            'evidence' => $evidence,
        ];
    }

    /**
     * Cíl srážky podle číselníku `sMZsrazky`.
     *
     * @param array<string,mixed> $catalog
     * @return array{target:?string,reason:string,code:string,name:string,kind:?string,deferred:bool}
     */
    private static function classify(array $catalog): array
    {
        $code = strtoupper(trim(PohodaXml::text($catalog, 'Cislo')));
        $name = PohodaXml::text($catalog, 'Nazev');
        if ($catalog === []) {
            return ['target' => null, 'reason' => 'catalog_missing', 'code' => $code, 'name' => $name, 'kind' => null, 'deferred' => false];
        }
        $text = AttendanceText::normalize($code . ' ' . $name);
        $statutory = self::bool(PohodaXml::text($catalog, 'JeZak'));
        $deferred = self::bool(PohodaXml::text($catalog, 'JeDepon'));
        $insolvency = preg_match(self::INSOLVENCY, $text) === 1;
        if ($statutory || $deferred || $insolvency) {
            return [
                'target' => $insolvency ? 'insolvency' : 'enforcement',
                // Deponovaná částka není vlastní titul, je to stav zákonné srážky: vede se
                // jako exekuční případ, který se sráží a zadržuje.
                'reason' => $statutory ? 'catalog_statutory' : ($deferred ? 'catalog_deferred' : 'name_insolvency'),
                'code' => $code,
                'name' => $name,
                'kind' => null,
                'deferred' => $deferred,
            ];
        }

        return [
            'target' => 'voluntary',
            'reason' => 'catalog_voluntary',
            'code' => $code,
            'name' => $name,
            'kind' => self::voluntaryKind($text),
            'deferred' => false,
        ];
    }

    private static function voluntaryKind(string $text): string
    {
        foreach (self::VOLUNTARY_KINDS as $pattern => $kind) {
            if (preg_match($pattern, $text) === 1) {
                return $kind;
            }
        }

        return 'other';
    }

    /**
     * Kategorie pohledávky pro rozvrh srážky.
     *
     * Samostatná složka „výživné“ v PAMICA není - výživné se vede jako zákonná srážka
     * a pozná se podle vyplněné původní výše výživného (`KcVyzivPuv`). Ta je zároveň
     * poměrem, kterým se mezi výživná dělí první třetina, takže do MyÚčta jde jako váha.
     *
     * Insolvence se podle § 398 odst. 3 insolvenčního zákona sráží v rozsahu přednostní
     * pohledávky, proto přednostní i tehdy, když `RelDrSra` v exportu chybí.
     *
     * @param array<string,mixed> $row
     */
    private static function category(array $row, float $maintenance, ?string $target): string
    {
        if ($maintenance > 0) {
            return 'current_maintenance';
        }
        $kind = self::PRIORITY_KIND[PohodaXml::text($row, 'RelDrSra')] ?? null;
        if ($kind !== null) {
            return $kind;
        }

        return $target === 'insolvency' ? 'other_priority' : 'non_priority';
    }

    /**
     * Příjemce srážky z karty PAMICA. Bez jména příjemce nemá záznam co zapsat.
     *
     * @param array<string,mixed> $row
     * @return Recipient|null
     */
    private static function recipient(array $row): ?array
    {
        $name = self::limited(PohodaXml::text($row, 'PlFirma') !== ''
            ? PohodaXml::text($row, 'PlFirma')
            : trim(PohodaXml::text($row, 'PlTitul') . ' ' . PohodaXml::text($row, 'PlJmeno')), 190);
        if ($name === null) {
            return null;
        }
        $account = trim(PohodaXml::text($row, 'PlUcet'));
        $bankCode = trim(PohodaXml::text($row, 'PlKodBanky'));
        $valid = $account !== '' && preg_match('/^[0-9]{4}$/D', $bankCode) === 1;

        return [
            'name' => $name,
            // Číslo rozhodnutí (spisová značka) - jediný lidský identifikátor případu,
            // který PAMICA u srážky vede.
            'reference' => self::limited(PohodaXml::text($row, 'PlRozhod'), 128),
            'ico' => self::digits(PohodaXml::text($row, 'PlICO'), 12),
            'account' => $valid ? $account : null,
            'bank_code' => $valid ? $bankCode : null,
            'variable_symbol' => self::digits(PohodaXml::text($row, 'PlVarSym'), 10),
            'specific_symbol' => self::digits(PohodaXml::text($row, 'PlSpecSym'), 10),
            'constant_symbol' => self::constantSymbol(PohodaXml::text($row, 'PlKonstSym')),
        ];
    }

    /** Osoby, u kterých PAMICA vede vlastní podklady pro nezabavitelnou částku. */
    private static function protectedAmountInputs(string $file): int
    {
        $people = [];
        foreach (PohodaXml::records($file, 'rpZAMprijemSraz') as $row) {
            if (self::bool(PohodaXml::text($row, 'Pouzito'))) {
                $people[PohodaXml::text($row, 'RefZAM')] = true;
            }
        }

        return count($people);
    }

    private static function constantSymbol(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        return $digits === '' ? null : substr(str_pad($digits, 4, '0', STR_PAD_LEFT), -4);
    }

    private static function digits(string $value, int $max): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        return $digits === '' ? null : substr($digits, 0, $max);
    }

    private static function limited(string $value, int $max): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Nulové datum Accessu (před rokem 1901) = žádné datum. */
    private static function realDate(?string $date): ?string
    {
        return $date !== null && (int) substr($date, 0, 4) >= 1901 ? $date : null;
    }

    private static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', '-1', 'true'], true);
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
    }
}
