<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Pravidla čtení účetního deníku Money (`UcDenik.DAT`) na jednom místě — používá je
 * náhled agendy, import deníku i rekonciliace. Kdyby si každý z nich určoval rok
 * nebo zdroj dokladu po svém, rekonciliace by kontrolovala sama sebe.
 */
final class Ms3Journal
{
    /** Zdroj řádku deníku, kterým Money označuje počáteční stavy. */
    public const OPENING_SOURCE = 'XP';

    /** Zdroj řádku deníku, kterým Money označuje uzávěrkové zápisy roku (převod na 702/710). */
    public const YEAR_END_CLOSING_SOURCE = 'XZ';

    /**
     * Pole deníku, se kterými pracují pravidla této třídy, převod deníku, dimenze
     * a rekonciliace. Deník se čte jen s nimi ({@see Ms3Table::rows()}); kdo potřebuje
     * další pole, musí ho sem doplnit.
     */
    public const FIELDS = ['Zdroj', 'Doklad', 'Datum', 'DatPlnDPH', 'Popis', 'Castka', 'UcMD', 'UcD', 'Stred', 'Zakazka'];

    /** @param array<string,mixed> $row */
    public static function isOpening(array $row): bool
    {
        return trim((string) ($row['Zdroj'] ?? '')) === self::OPENING_SOURCE;
    }

    /**
     * Uzávěrka roku v Money: konečné stavy rozvahových účtů na 702 a výsledkové účty přes
     * 710. Převod ji nepřebírá — rok uzavře průvodce uzávěrkou MyÚčta ({@see HistoricalYearCloser})
     * proti počátečním stavům dalšího roku z Money. Převzatá by konečné stavy vynulovala
     * a uzávěrka MyÚčta by pak nesouhlasila s počátečními stavy ani nešla provést.
     *
     * @param array<string,mixed> $row
     */
    public static function isYearEndClosing(array $row): bool
    {
        return strtoupper(trim((string) ($row['Zdroj'] ?? ''))) === self::YEAR_END_CLOSING_SOURCE;
    }

    /**
     * Klíč dokladu v deníku: skupina řádků se stejným zdrojem, číslem a datem.
     * Počáteční stavy roku tvoří jediný otevírací zápis.
     *
     * @param array<string,mixed> $row
     */
    public static function groupKey(array $row): string
    {
        if (self::isOpening($row)) {
            return self::OPENING_SOURCE;
        }
        return trim((string) ($row['Zdroj'] ?? '')) . '|' . trim((string) ($row['Doklad'] ?? '')) . '|' . (string) ($row['Datum'] ?? '');
    }

    /**
     * Středisko řádku (`Stred`) — v MyÚčtu kód střediska na řádku deníku.
     *
     * @param array<string,mixed> $row
     */
    public static function costCenter(array $row): ?string
    {
        return mb_substr(trim((string) ($row['Stred'] ?? '')), 0, 50) ?: null;
    }

    /**
     * Zakázka řádku (`Zakazka`) — v MyÚčtu zakázka (`projects`). Registrační značka
     * vozidla, kterou účetní do zakázky píšou v různých tvarech (`5E6 7890`, `5E67890`,
     * `5E6 7890.`), se sjednotí na jeden tvar, ať z ní nevzniknou tři zakázky.
     *
     * @param array<string,mixed> $row
     */
    public static function jobCode(array $row): ?string
    {
        $code = trim((string) ($row['Zakazka'] ?? ''));
        if ($code === '') {
            return null;
        }
        return self::vehiclePlate($code) ?? mb_substr($code, 0, 50);
    }

    /**
     * Registrační značka v jednotném tvaru (`1AB 2345`, `EL 456 CD`), nebo null, když
     * kód značkou není. Běžná značka je číslice, písmeno, znak a čtyři číslice, starší
     * šestimístná číslice, písmeno a čtyři číslice, elektromobil EL, tři číslice a dvě písmena.
     */
    public static function vehiclePlate(string $code): ?string
    {
        $plain = strtoupper((string) preg_replace('/[\s.\-]+/u', '', $code));
        if (preg_match('/^(\d[A-Z][A-Z0-9]?)(\d{4})$/', $plain, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }
        if (preg_match('/^EL(\d{3})([A-Z]{2})$/', $plain, $m) === 1) {
            return 'EL ' . $m[1] . ' ' . $m[2];
        }
        return null;
    }

    /**
     * Zkratka agendy do popisu zápisu v deníku. Zrcadlí zkratky, kterými doklady
     * pojmenovává {@see \MyInvoice\Service\Accounting\JournalDescriptionBuilder}, ať
     * se převzatý zápis čte stejně jako zápis vzniklý v MyÚčtu. Neznámý zdroj si
     * ponechá svůj dvoupísmenný kód z Money (ID, KZ, KP…).
     */
    public static function shortLabel(string $moneySource): string
    {
        $code = strtoupper(trim($moneySource));

        return match ($code) {
            'FV' => 'FV',
            'FP' => 'PF',
            'BK' => 'Banka',
            'PK' => 'Pokladna',
            default => $code,
        };
    }

    /** Zdroj dokladu v Money → `journal_entries.source_type`. */
    public static function sourceType(string $moneySource): string
    {
        return match (strtoupper(trim($moneySource))) {
            'BK' => 'bank',
            'PK' => 'cash',
            'FP' => 'purchase_invoice',
            'FV' => 'invoice',
            default => 'manual', // ID interní doklady, KZ závazky, KP pohledávky, ostatní
        };
    }

    /**
     * Účetní rok adresáře ROK.nnn. Nejčastější rok mezi reálnými zápisy, ne první
     * nalezený: jediný netypický řádek by jinak posunul celé období. Rok, který má jen
     * počáteční stavy, se vezme z jejich popisu („Počáteční stav roku 2026").
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function fiscalYear(array $rows): ?int
    {
        return self::summarize($rows)['fiscal_year'];
    }

    /**
     * Souhrn deníku roku v jednom průchodu - deník se tak nemusí držet v paměti celý:
     * počet řádků a počátečních stavů, účetní rok ({@see fiscalYear()}), kalendářní rok
     * ({@see isCalendarYear()} pro ten rok), první a poslední datum mimo počáteční stavy
     * a první datum zápisu v účetním roce.
     *
     * @param iterable<array<string,mixed>> $rows
     * @return array{rows:int,opening_rows:int,fiscal_year:?int,calendar:bool,first_date:?string,last_date:?string,first_entry:?string}
     */
    public static function summarize(iterable $rows): array
    {
        $count = 0;
        $opening = 0;
        $years = [];
        $fromText = null;
        $firstAnyDate = null;
        $dated = 0;
        $datedByYear = [];
        $minByYear = [];
        $firstDate = null;
        $lastDate = null;
        foreach ($rows as $r) {
            $count++;
            $isOpening = self::isOpening($r);
            $date = (string) ($r['Datum'] ?? '');
            if ($isOpening) {
                $opening++;
            } else {
                if ($date !== '') {
                    $y = (int) substr($date, 0, 4);
                    if ($y >= 1990 && $y <= 2100) {
                        $years[$y] = ($years[$y] ?? 0) + 1;
                    }
                    $dated++;
                    $datedByYear[$y] = ($datedByYear[$y] ?? 0) + 1;
                    $prefix = substr($date, 0, 4);
                    if (!isset($minByYear[$prefix]) || $date < $minByYear[$prefix]) {
                        $minByYear[$prefix] = $date;
                    }
                }
                if (($r['Datum'] ?? null) !== null) {
                    $raw = (string) $r['Datum'];
                    if ($firstDate === null || $raw < $firstDate) {
                        $firstDate = $raw;
                    }
                    if ($lastDate === null || $raw > $lastDate) {
                        $lastDate = $raw;
                    }
                }
            }
            if ($fromText === null && $isOpening && preg_match('/\b(19|20)(\d{2})\b/', (string) ($r['Popis'] ?? ''), $m) === 1) {
                $fromText = (int) ($m[1] . $m[2]);
            }
            if ($firstAnyDate === null && $date !== '') {
                $firstAnyDate = $date;
            }
        }
        if ($years !== []) {
            arsort($years);
            $year = (int) array_key_first($years);
        } elseif ($fromText !== null) {
            $year = $fromText;
        } else {
            $year = $firstAnyDate !== null ? (int) substr($firstAnyDate, 0, 4) : null;
        }
        return [
            'rows' => $count,
            'opening_rows' => $opening,
            'fiscal_year' => $year,
            'calendar' => $year === null || self::calendarShare($dated, $dated - ($datedByYear[$year] ?? 0)),
            'first_date' => $firstDate,
            'last_date' => $lastDate,
            'first_entry' => $year !== null ? ($minByYear[(string) $year] ?? null) : null,
        ];
    }

    /** Podíl zápisů mimo kalendářní rok, nad kterým agenda vede hospodářský rok. */
    private const NON_CALENDAR_SHARE = 0.1;

    private static function calendarShare(int $total, int $outside): bool
    {
        return $total === 0 || $outside / $total <= self::NON_CALENDAR_SHARE;
    }

    /**
     * Vede agenda kalendářní účetní rok? Převod jiný nezná (období jsou 1. 1. – 31. 12.).
     * Pár zápisů mimo rok je běžná chyba dokladu a převod je ohlásí po jednom; hospodářský
     * rok (třeba červenec–červen) má mimo kalendářní rok velkou část deníku.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function isCalendarYear(array $rows, int $year): bool
    {
        $total = 0;
        $outside = 0;
        foreach ($rows as $r) {
            $date = (string) ($r['Datum'] ?? '');
            if (self::isOpening($r) || $date === '') {
                continue;
            }
            $total++;
            $outside += (int) substr($date, 0, 4) !== $year ? 1 : 0;
        }
        return self::calendarShare($total, $outside);
    }

    /**
     * Čistý účetní účinek řádku deníku: kladná částka jde na MD `UcMD` a D `UcD`,
     * záporná obráceně. `null` = řádek nemá účinek (nulová částka, chybějící účet
     * nebo stejný účet na obou stranách — Money je v počátečních stavech běžně má).
     *
     * @param array<string,mixed> $row
     * @return array{debit:string,credit:string,amount:float}|null
     */
    public static function effect(array $row): ?array
    {
        $amount = round((float) ($row['Castka'] ?? 0), 2);
        $md = trim((string) ($row['UcMD'] ?? ''));
        $d = trim((string) ($row['UcD'] ?? ''));
        if ($amount === 0.0 || $md === '' || $d === '' || $md === $d) {
            return null;
        }
        if ($amount < 0) {
            return ['debit' => $d, 'credit' => $md, 'amount' => -$amount];
        }
        return ['debit' => $md, 'credit' => $d, 'amount' => $amount];
    }
}
