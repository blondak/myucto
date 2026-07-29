<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use DateTimeImmutable;

/**
 * Číselník ČINNOSTI (zkratka OKEC) z Daňového portálu MFČR — proti němu EPO
 * runtime validuje atribut `c_okec` (propustná chyba 30 „Hlavní ekonomická
 * činnost by měla odpovídat nějaké hodnotě z číselníku").
 *
 * Zdroj dat: https://adisspr.mfcr.cz/mepo/pub/api/ciselniky/soubor/okec
 * (rozhraní číselníků: https://adisspr.mfcr.cz/pmd/dokumentace/ciselniky).
 * Snapshot v `api/resources/ciselniky/okec.txt` — pipe-delimited TXT přesně
 * tak, jak ho portál publikuje (`c_nace|d_pocpl|d_ukopl|naz_nace|`).
 * Aktualizace: `cmd/download-okec.{cmd,sh}` (stáhne gzip, ověří formát
 * a soubor přepíše); formát se nemění, jen přibývají revize číselníku.
 *
 * Klíčová fakta o číselníku (stav snapshotu 2026-07, aktualizace 9. 7. 2026):
 *   - Kódy jsou koncepčně 6místné: 4místná třída NACE × 100, u některých
 *     odvětví s národní podtřídou na 5.–6. pozici (621010, 649230, …).
 *   - Sloupec je číselného typu, takže kódy sekcí 01–09 jsou v číselníku
 *     uloženy BEZ vodicí nuly („14800" = 01.48.00). Porovnáváme proto
 *     numericky a emitujeme kanonickou podobu z číselníku.
 *   - Záznamy mají platnost od/do: k 1. 1. 2026 se číselník překlopil na
 *     NACE rev. 2.1 — všechny starší kódy mají d_ukopl 2025-12-31
 *     (např. 620200 „poradenství v IT" nahradilo 622000). Kód správné
 *     šířky tedy může být přesto odmítnut, protože EXPIROVAL — přesně
 *     to je pozadí issue #157.
 */
final class EpoOkecCodebook
{
    private const RESOURCE = __DIR__ . '/../../../resources/ciselniky/okec.txt';

    /** Výsledek: kód nalezen a platný k referenčnímu datu. */
    public const STATUS_ACTIVE = 'active';
    /** Kód v číselníku existuje, ale jeho platnost skončila (viz valid_to). */
    public const STATUS_EXPIRED = 'expired';
    /** Kód v číselníku není (snapshot může být i zastaralý — nikdy neblokovat). */
    public const STATUS_UNKNOWN = 'unknown';

    /** @var array<int, list<array{code: string, from: string, to: string, name: string}>>|null */
    private static ?array $index = null;

    /** @var list<array{code: string, from: string, to: string, name: string}>|null */
    private static ?array $rows = null;

    /**
     * Normalizuje vstup („73.11", „7311", „62020", „731100", „01480", …) na
     * kanonický kód číselníku a řekne, jak na tom je s platností.
     *
     * Kandidáti se zkoušejí NUMERICKY v pořadí: (1) doplnění nulami zprava na
     * 6 míst — hierarchický zápis ČSÚ, který posílá ARES i uživatelé (7311 →
     * 731100, 31000 = 31.00.0 → 310000, 01480 = 01.48.0 → 014800 → int 14800),
     * (2) vstup tak, jak je — kanonické hodnoty číselníku, u sekcí 01–09 bez
     * vodicí nuly („14800" = 01.48.00; doplnění zprava dá 148000, což
     * v číselníku není, takže se propadne sem).
     *
     * Na pořadí ZÁLEŽÍ: 5místná hodnota je dvojznačná — „31000" je současně
     * ČSÚ zápis třídy 31.00 (výroba nábytku → 310000) i kanonický kód sekce
     * 03.10.00 (rybolov → 31000). Vyhrává ČSÚ výklad, protože přesně ten
     * produkuje ARES prefill i ruční zápis; kdo myslí rybolov, píše kód
     * s vodicí nulou (03.10.0 → 031000 → int 31000), a ten se trefí taky.
     *
     * Preferuje se kandidát platný k $at; když žádný platný není, vrací se
     * existující expirovaný (s datem konce platnosti); když kód v číselníku
     * není vůbec, vrací se doplněná podoba se STATUS_UNKNOWN.
     *
     * @return array{code: string, status: string, name: ?string, valid_to: ?string}|null
     *         null = po odstranění nečíslic méně než 4 číslice (oddíl z ARES)
     *         → atribut c_okec se má vynechat / uložení odmítnout.
     */
    public static function normalize(string $raw, ?DateTimeImmutable $at = null): ?array
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) < 4) {
            return null;
        }
        if (strlen($digits) > 6) {
            $digits = substr($digits, 0, 6);
        }
        $at ??= new DateTimeImmutable('today');
        $atStr = $at->format('Y-m-d');

        $padded = str_pad($digits, 6, '0', STR_PAD_RIGHT);
        $candidates = array_unique([(int) $padded, (int) $digits]);

        $expired = null;
        foreach ($candidates as $value) {
            foreach (self::index()[$value] ?? [] as $entry) {
                // Expirovaný = má vyplněné d_ukopl v minulosti. Záznam, jehož
                // platnost teprve začne, za expirovaný NEpovažujeme — jinak by
                // se uživateli hlásilo prázdné datum konce platnosti.
                if ($entry['to'] !== '' && $entry['to'] < $atStr) {
                    if ($expired === null || $entry['to'] > $expired['valid_to']) {
                        $expired = [
                            'code' => $entry['code'],
                            'status' => self::STATUS_EXPIRED,
                            'name' => $entry['name'],
                            'valid_to' => $entry['to'],
                        ];
                    }
                    continue;
                }
                return [
                    'code' => $entry['code'],
                    'status' => self::STATUS_ACTIVE,
                    'name' => $entry['name'],
                    'valid_to' => $entry['to'] !== '' ? $entry['to'] : null,
                ];
            }
        }
        if ($expired !== null) {
            return $expired;
        }
        return ['code' => $padded, 'status' => self::STATUS_UNKNOWN, 'name' => null, 'valid_to' => null];
    }

    /**
     * Popis kódu uloženého u dodavatele pro UI — kanonická podoba, čitelný
     * název a stav platnosti. Na rozdíl od normalize() vrací i `display`
     * (`73.11.00`) a název už v čitelné podobě, ne v kapitálkách číselníku.
     *
     * @return array{code: string, display: string, name: ?string, status: string, valid_to: ?string}|null
     *         null = prázdný vstup nebo méně než 4 číslice (kód se neukládá)
     */
    public static function describe(?string $raw, ?DateTimeImmutable $at = null): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $resolved = self::normalize($raw, $at);
        if ($resolved === null) {
            return null;
        }
        return [
            'code'     => $resolved['code'],
            'display'  => self::display($resolved['code']),
            'name'     => $resolved['name'] !== null ? self::humanizeName($resolved['name']) : null,
            'status'   => $resolved['status'],
            'valid_to' => $resolved['valid_to'],
        ];
    }

    /**
     * Našeptávač aktivních kódů (Nastavení → CZ-NACE). Hledá se PODLE ČÍSEL
     * v dotazu jako prefix kódu, jinak podle názvu činnosti; vrací se jen
     * záznamy platné k $at, protože nabízet expirovaný kód by znamenalo
     * navádět uživatele na propustnou chybu 30.
     *
     * Prefix se porovnává proti kanonickému kódu i proti šestimístné podobě
     * s vodicí nulou: sekce 01–09 jsou v číselníku bez ní („14800"), ale
     * uživatel i ARES píšou „01.48". Název se hledá bez ohledu na velikost
     * písmen a diakritiku („ucetni" najde „ÚČETNICTVÍ").
     *
     * @return list<array{code: string, display: string, name: string, valid_from: string}>
     */
    public static function search(string $query, int $limit = 20, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('today');
        $atStr = $at->format('Y-m-d');
        $limit = max(1, min($limit, 100));

        $digits = preg_replace('/\D/', '', $query) ?? '';
        $needle = self::fold(trim($query));

        $out = [];
        foreach (self::rows() as $row) {
            if ($row['to'] !== '' && $row['to'] < $atStr) {
                continue; // expirovaný kód nenabízíme
            }
            if ($row['from'] !== '' && $row['from'] > $atStr) {
                continue; // platnost teprve začne
            }
            if ($digits !== '') {
                $padded = str_pad($row['code'], 6, '0', STR_PAD_LEFT);
                if (!str_starts_with($row['code'], $digits) && !str_starts_with($padded, $digits)) {
                    continue;
                }
            } elseif ($needle !== '' && !str_contains(self::fold($row['name']), $needle)) {
                continue;
            }
            $out[] = [
                'code'       => $row['code'],
                'display'    => self::display($row['code']),
                'name'       => self::humanizeName($row['name']),
                'valid_from' => $row['from'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Kód číselníku v čitelné podobě třídy NACE: `731100` → `73.11.00`, `14800` → `01.48.00`. */
    public static function display(string $code): string
    {
        $padded = str_pad($code, 6, '0', STR_PAD_LEFT);
        return substr($padded, 0, 2) . '.' . substr($padded, 2, 2) . '.' . substr($padded, 4, 2);
    }

    /** Číselník vede názvy v kapitálkách — pro UI je vrátíme jako běžnou větu. */
    public static function humanizeName(string $raw): string
    {
        $lower = mb_strtolower($raw, 'UTF-8');
        return mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lower, 1, null, 'UTF-8');
    }

    /** Porovnávací tvar pro hledání v názvu — bez diakritiky a velikosti písmen. */
    private static function fold(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
            'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y',
            'ž' => 'z',
        ]);
    }

    /** @return array<int, list<array{code: string, from: string, to: string, name: string}>> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $index = [];
        foreach (self::rows() as $row) {
            $index[(int) $row['code']][] = $row;
        }
        return self::$index = $index;
    }

    /**
     * Syrové řádky snapshotu v pořadí, v jakém je portál publikuje (vzestupně
     * podle kódu, starší revize číselníku před novou).
     *
     * @return list<array{code: string, from: string, to: string, name: string}>
     */
    private static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        $rows = [];
        $fh = @fopen(self::RESOURCE, 'rb');
        if ($fh !== false) {
            fgets($fh); // hlavička c_nace|d_pocpl|d_ukopl|naz_nace|
            while (($line = fgets($fh)) !== false) {
                $cols = explode('|', rtrim($line, "\r\n"));
                if (count($cols) < 4 || $cols[0] === '') {
                    continue;
                }
                $rows[] = [
                    'code' => $cols[0],
                    'from' => trim($cols[1]),
                    'to' => trim($cols[2]),
                    'name' => trim($cols[3]),
                ];
            }
            fclose($fh);
        }
        return self::$rows = $rows;
    }
}
