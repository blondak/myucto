<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;

/**
 * Případy dávek nemocenského pojištění a náhrady mzdy z `91_mzdy.xml` (POHODA Mzdy / PAMICA).
 *
 * Nepřítomnosti samotné převádí už {@see PohodaPayrollPeople}: hodiny nesou měsíční sešity
 * a doba nepřítomnosti vzniká jako `payroll_absences`. Co tou cestou spolehlivě NEPROJDE,
 * je NÁVAZNOST rozpracované neschopnosti - PAMICA ji zná od skutečného počátku, kdežto
 * MyÚčto dostane jen tu část, která spadá do převáděného roku, případně až od měsíce,
 * kterým začíná vedení mezd. Okno náhrady mzdy podle § 192 ZP se přitom počítá z
 * `payroll_absences.date_from` ({@see \MyInvoice\Service\Payroll\Absence\AbsenceRuleset::sicknessWindowEnd()}),
 * takže bez údaje „kolik dnů okna už padlo jinde“ by se náhrada vyplatila znovu od začátku.
 *
 * Třída jen čte, normalizuje a počítá; nic nezapisuje ({@see PohodaPayrollSicknessWriter}).
 *
 * **Co export nese a co ne.** Druh nepřítomnosti se bere z ČÍSLA složky číselníku
 * `sMZneprit`, stejně jako v {@see PohodaPayrollPeople}; složka, kterou číselník označuje
 * za nemocenskou dávku (`JeNemDav`) a evidence ji nezná, jde do protokolu, ne do ticha.
 * Náhrada mzdy má v PAMICA vlastní tabulku `MZnahr`, jenže ta v exportu nemusí být vůbec
 * (viděn export, kde je jen zálohová kopie `zalMZnahr`). Pak je jediným zdrojem částky
 * `MZneprit.KcNahr`, a protokol řekne, odkud se vzala. Dávky nemocenského (`MZdavky`) se
 * nepřevádějí: od roku 2009 je nevyplácí zaměstnavatel, ale ČSSZ, takže v MyÚčtu nemají
 * kam - jejich výskyt se jen spočítá.
 *
 * **Dny se nedopočítávají.** Kalendářní i pracovní dny nepřítomnosti nese `MZneprit`
 * (`DnyKal`, `DnyPrac`); odvozovat je z hodin dělením úvazkem je zakázané, protože
 * výsledek vypadá jako údaj z exportu a přitom je to odhad.
 */
final class PohodaPayrollSickness
{
    /** Tabulky, které se čtou celé do paměti (jednotky až stovky řádků). */
    private const TABLES = ['ZAM', 'ZAMpomer', 'sMZneprit'];

    /**
     * Druh nepřítomnosti MyÚčta podle ČÍSLA složky `sMZneprit` - jen nemocenská oblast.
     * Seznam je podmnožinou {@see PohodaPayrollPeople} (tam se řeší i dovolená, překážky
     * a neplacené volno); rodičovská dovolená tu není, protože není dávkou nemocenského
     * pojištění a žádné okno náhrady mzdy neotvírá.
     */
    private const SICKNESS_CODES = [
        'N01' => 'dpn', 'H01' => 'dpn', 'N03' => 'dpn', 'H03' => 'dpn', 'N04' => 'dpn', 'H04' => 'dpn',
        'H11' => 'dpn', 'H12' => 'dpn', 'H13' => 'dpn', 'H14' => 'dpn',
        'N02' => 'quarantine', 'H02' => 'quarantine',
        'N05' => 'ocr', 'N06' => 'ocr', 'H05' => 'ocr', 'H06' => 'ocr', 'H17' => 'ocr',
        'H16' => 'long_term_care',
        'N07' => 'ppm', 'H07' => 'ppm',
        'H15' => 'paternity',
    ];

    /**
     * Druhy, u kterých zaměstnavatel poskytuje náhradu mzdy v okně § 192 ZP. Jen u nich
     * dává smysl vyčerpané dny okna evidovat; u ošetřovného, PPM ani otcovské plátce
     * mzdy nic neposkytuje, dávku platí ČSSZ od prvního dne.
     */
    public const WAGE_COMPENSATION_TYPES = ['dpn', 'quarantine'];

    /**
     * Případy nemocenské z převáděného roku.
     *
     * `$startPeriod` je první měsíc, který vede MyÚčto (`payroll_module_state.start_period`
     * ve tvaru `RRRR-MM`). Podle něj se pozná rozpracovaný případ - ten, který u předchozího
     * programu začal a u nás pokračuje. Bez něj se rozpracované případy nedají rozlišit
     * a vrací se všechny s `in_progress = false`, aby si zápis nic nedomýšlel.
     *
     * @return array{
     *     start_period:?string,
     *     cases:list<array<string,mixed>>,
     *     wage_compensation_rows:int,
     *     benefit_rows:int,
     *     benefit_claims:int,
     *     unclassified:array<string,int>
     * }
     */
    public static function read(string $file, int $year, ?string $startPeriod = null): array
    {
        $boundary = self::periodStart($startPeriod);
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

        /** @var array<string,array{person:string,relation:string,period:string}> $payslips */
        $payslips = [];
        foreach (PohodaXml::records($file, 'MZ') as $mz) {
            $month = (int) PohodaXml::text($mz, 'RelMes');
            if ((int) PohodaXml::text($mz, 'Rok') !== $year || $month < 1 || $month > 12) {
                continue;
            }
            $payslips[PohodaXml::text($mz, 'ID')] = [
                'person' => PohodaXml::text($mz, 'RefZAM'),
                'relation' => PohodaXml::text($mz, 'RefPomer'),
                'period' => sprintf('%04d-%02d', $year, $month),
            ];
        }

        /*
         * Náhrady mzdy z vlastní tabulky. Chybějící tabulka není chyba exportu: generátor
         * ji vynechá, když je prázdná, a viděn byl i export, kde zůstala jen zálohová
         * kopie `zalMZnahr`. Proudové čtení pak jen nevrátí nic a krok pokračuje dál.
         */
        $compensations = [];
        foreach (PohodaXml::records($file, 'MZnahr') as $row) {
            $payslip = $payslips[PohodaXml::text($row, 'RefAg')] ?? null;
            if ($payslip === null) {
                continue;
            }
            $compensations[] = [
                'relation' => $payslip['relation'],
                'from' => self::realDate(PohodaXml::date($row, 'DatZac')),
                'to' => self::realDate(PohodaXml::date($row, 'DatKon')),
                'minor' => self::minor(PohodaXml::num($row, 'Kc')),
                'id' => PohodaXml::text($row, 'ID'),
            ];
        }

        // Dávky nemocenského se nepřevádějí (vyplácí je ČSSZ), jen se počítají do protokolu.
        $benefitRows = 0;
        foreach (PohodaXml::records($file, 'MZdavky') as $row) {
            if (($payslips[PohodaXml::text($row, 'RefAg')] ?? null) !== null) {
                $benefitRows++;
            }
        }
        $benefitClaims = 0;
        foreach (PohodaXml::records($file, 'NEMPRIpol') as $row) {
            if ((int) PohodaXml::text($row, 'RokMZ') === $year) {
                $benefitClaims++;
            }
        }

        /** @var array<string,list<array<string,mixed>>> $groups vztah|druh => nepřítomnosti */
        $groups = [];
        /** @var array<string,int> $unclassified nemocenská složka, kterou evidence nezná */
        $unclassified = [];
        foreach (PohodaXml::records($file, 'MZneprit') as $row) {
            $payslip = $payslips[PohodaXml::text($row, 'RefAg')] ?? null;
            if ($payslip === null) {
                continue;
            }
            $catalog = $byId['sMZneprit'][PohodaXml::text($row, 'RefSlozka')] ?? [];
            $code = strtoupper(trim(PohodaXml::text($catalog, 'Cislo')));
            $type = self::SICKNESS_CODES[$code] ?? null;
            if ($type === null) {
                // Ostatní nepřítomnosti (dovolená, překážky) patří jinému kroku převodu;
                // sem se hlásí jen to, co číselník sám označuje za nemocenskou dávku.
                if (self::bool(PohodaXml::text($catalog, 'JeNemDav'))) {
                    $label = trim($code . ' ' . PohodaXml::text($catalog, 'Nazev'));
                    $unclassified[$label] = ($unclassified[$label] ?? 0) + 1;
                }
                continue;
            }
            $from = self::realDate(PohodaXml::date($row, 'DatZac'));
            $to = self::realDate(PohodaXml::date($row, 'DatKon'));
            if ($from === null || $to === null || $to < $from) {
                continue;
            }
            $groups[$payslip['relation'] . '|' . $type][] = [
                'from' => $from,
                'to' => $to,
                'person' => $payslip['person'],
                'calendar_days' => PohodaXml::num($row, 'DnyKal'),
                'work_days' => PohodaXml::num($row, 'DnyPrac'),
                'hours' => PohodaXml::num($row, 'HodPrac'),
                'compensation_minor' => self::minor(PohodaXml::num($row, 'KcNahr')),
                'childbirth' => self::realDate(PohodaXml::date($row, 'DatPorod')),
                'id' => PohodaXml::text($row, 'ID'),
            ];
        }

        $cases = [];
        foreach ($groups as $key => $rows) {
            [$relationId, $type] = explode('|', $key, 2);
            $relation = $byId['ZAMpomer'][$relationId] ?? [];
            $personId = PohodaXml::text($relation, 'RefZAM');
            $person = $byId['ZAM'][$personId] ?? [];
            $personalNumber = $person === [] || $relation === []
                ? null
                : PohodaPayrollPeople::personalNumber($person, $relation, $relationCount[$personId] ?? 1);
            foreach (self::merge($rows) as $case) {
                $cases[] = self::record(
                    $case,
                    $type,
                    $personId,
                    $personalNumber,
                    $relationId,
                    $compensations,
                    $boundary,
                );
            }
        }
        usort($cases, static fn (array $a, array $b): int
            => [$a['personal_number'] ?? '', $a['date_from']] <=> [$b['personal_number'] ?? '', $b['date_from']]);

        return [
            'start_period' => $startPeriod,
            'cases' => $cases,
            'wage_compensation_rows' => count($compensations),
            'benefit_rows' => $benefitRows,
            'benefit_claims' => $benefitClaims,
            'unclassified' => $unclassified,
        ];
    }

    /**
     * Kolik kalendářních dnů okna náhrady mzdy padlo PŘED dnem, od kterého případ vede
     * MyÚčto. Den, kterým okno začíná, se počítá, den převzetí už ne - okno je souvislá
     * řada kalendářních dnů od vzniku neschopnosti, ne od nástupu k plátci.
     *
     * Jediná cesta, jak tohle číslo spočítat: používá ji čtení převodu (proti prvnímu
     * měsíci vedení mezd) i jeho zápis (proti skutečnému `date_from` nepřítomnosti).
     * `$windowCalendarDays` je délka okna z rulesetu; bez ní se vrací neomezený počet
     * uplynulých dnů, protože zákonné číslo si tahle třída vymýšlet nesmí.
     */
    public static function windowUsedCalendarDays(
        string $caseFrom,
        string $carriedFrom,
        ?int $windowCalendarDays = null,
    ): int {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $caseFrom);
        $taken = \DateTimeImmutable::createFromFormat('!Y-m-d', $carriedFrom);
        if (!$start instanceof \DateTimeImmutable || !$taken instanceof \DateTimeImmutable) {
            return 0;
        }
        $used = (int) $start->diff($taken)->format('%r%a');
        if ($used <= 0) {
            return 0;
        }

        return $windowCalendarDays === null ? $used : min($used, $windowCalendarDays);
    }

    /**
     * Jeden případ z už sloučených řádků `MZneprit`.
     *
     * @param array<string,mixed> $case
     * @param list<array<string,mixed>> $compensations
     * @return array<string,mixed>
     */
    private static function record(
        array $case,
        string $type,
        string $personId,
        ?string $personalNumber,
        string $relationId,
        array $compensations,
        ?string $boundary,
    ): array {
        $from = (string) $case['from'];
        $to = (string) $case['to'];
        $paid = null;
        foreach ($compensations as $row) {
            if ($row['relation'] !== $relationId) {
                continue;
            }
            // Náhrada bez data se přiřadit nedá; zůstane nevyplněná a protokol ji vypíše.
            if (!is_string($row['from']) || !is_string($row['to']) || $row['from'] > $to || $row['to'] < $from) {
                continue;
            }
            $paid = ($paid ?? 0) + (int) $row['minor'];
        }
        $source = $paid === null ? null : 'mznahr';
        if ($paid === null && $case['compensation_minor'] > 0) {
            $paid = (int) $case['compensation_minor'];
            $source = 'mzneprit_kcnahr';
        }
        $carried = $boundary !== null && in_array($type, self::WAGE_COMPENSATION_TYPES, true)
            ? self::windowUsedCalendarDays($from, $boundary)
            : null;

        return [
            'person_key' => $personId,
            'relation_key' => $relationId,
            'personal_number' => $personalNumber,
            'type' => $type,
            'date_from' => $from,
            'date_to' => $to,
            'childbirth' => $case['childbirth'],
            // Dny z exportu, ne z hodin: `null` znamená „PAMICA je nevede“.
            'calendar_days' => $case['calendar_days'] > 0 ? (int) round((float) $case['calendar_days']) : null,
            'work_days' => $case['work_days'] > 0 ? (float) $case['work_days'] : null,
            'hours' => $case['hours'] > 0 ? (float) $case['hours'] : null,
            'compensation_minor' => $paid,
            'compensation_source' => $source,
            /*
             * Uplynulé dny okna § 192 ZP ke dni převzetí. Zastropuje je až zápis podle
             * délky okna z rulesetu - zákonné číslo tahle třída nezná a hádat ho nebude.
             */
            'window_used_calendar_days' => $carried,
            // Rozpracovaný případ: u předchozího programu začal a do MyÚčta pokračuje.
            'in_progress' => $boundary !== null && $to >= $boundary,
            'crosses_start_period' => $boundary !== null && $from < $boundary && $to >= $boundary,
            'evidence' => $case['evidence'],
        ];
    }

    /**
     * Sloučení řádků téže nepřítomnosti. PAMICA vede `MZneprit` u každé mzdy, takže jeden
     * případ přes víc měsíců je víc řádků - buď se shodným rozsahem případu, nebo
     * rozkrájený po měsících. Slučuje se proto překryv i navazující den; mezera mezi dvěma
     * rozsahy znamená dva případy a dvě okna náhrady.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function merge(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);
        $merged = [];
        foreach ($rows as $row) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];
            $next = $last === null
                ? null
                : (\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $last['to']) ?: null)?->modify('+1 day')->format('Y-m-d');
            if ($last === null || $next === null || $row['from'] > $next) {
                $merged[] = [
                    'from' => $row['from'],
                    'to' => $row['to'],
                    'calendar_days' => (float) $row['calendar_days'],
                    'work_days' => (float) $row['work_days'],
                    'hours' => (float) $row['hours'],
                    'compensation_minor' => (int) $row['compensation_minor'],
                    'childbirth' => $row['childbirth'],
                    'evidence' => ['MZneprit:' . $row['id']],
                ];
                continue;
            }
            $index = count($merged) - 1;
            $merged[$index]['to'] = max((string) $last['to'], (string) $row['to']);
            /*
             * Dny a hodiny se sčítají jen u řádků rozkrájených po měsících. Řádek se
             * shodným rozsahem je táž evidence viděná z jiné mzdy, takže by se počítala
             * dvakrát; pozná se podle toho, že nový rozsah nic nepřidal.
             */
            if ((string) $row['to'] > (string) $last['to']) {
                $merged[$index]['calendar_days'] += (float) $row['calendar_days'];
                $merged[$index]['work_days'] += (float) $row['work_days'];
                $merged[$index]['hours'] += (float) $row['hours'];
            }
            // Náhrada se sčítá vždy: je to částka konkrétní mzdy, ne opis rozsahu případu.
            $merged[$index]['compensation_minor'] += (int) $row['compensation_minor'];
            $merged[$index]['childbirth'] ??= $row['childbirth'];
            $merged[$index]['evidence'][] = 'MZneprit:' . $row['id'];
        }

        return $merged;
    }

    /** První den měsíce, kterým začíná vedení mezd v MyÚčtu. */
    private static function periodStart(?string $period): ?string
    {
        return $period !== null && preg_match('/^\d{4}-\d{2}$/D', $period) === 1 ? $period . '-01' : null;
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
