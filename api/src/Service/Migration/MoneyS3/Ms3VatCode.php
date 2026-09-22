<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier as Lines;

/**
 * Členění DPH z Money S3 (`KodDPH` u faktur, `Cleneni` v pokladně) převedené na zařazení
 * dokladu v MyÚčtu.
 *
 * Kód nese rok vzoru přiznání, řádky přiznání a příponu. Přípony odpovídají výchozímu
 * číselníku členění Money (`UcClnDPH`, sloupec `Kolonka` PT = odpočet v plné výši,
 * KT = krácený odpočet):
 *   - `19Ř40,41` tuzemský odpočet v plné výši, `M`/`P` totéž u pořízení majetku,
 *     `_S` splátky, `K`/`MK`/`PK` krácený odpočet podle § 76,
 *   - `MR`/`PR` poměrný nárok (§ 75) převod neodhaduje — procento v záloze není,
 *   - `19Ř01,02` tuzemské plnění, `_C`/`_P` zvláštní režim cestovní služby a použitého
 *     zboží (daň z přirážky) převod neodhaduje,
 *   - `19Ř20` dodání zboží do EU, `19Ř21` služba do EU, `19Ř22` vývoz, `19Ř26` ostatní
 *     plnění s nárokem, `19Ř50` osvobozené bez nároku, `19Ř51BN` osvobozené plnění
 *     mimo koeficient, `19Ř25` přenesení daňové povinnosti u odpadu (v číselníku Money
 *     předmět plnění 5), `19Ř25_S` u stavebních prací (předmět plnění 4),
 *   - `19Ř00P`/`19Ř00U` doklad mimo přiznání.
 * Ostatní členění (přenesená povinnost na vstupu, pořízení z EU, dovoz…) převod
 * nezařazuje a doklad nechá k ruční kontrole.
 *
 * Význam řádků drží {@see Lines}; tady je jen gramatika kódu Money a význam přípon.
 */
final class Ms3VatCode
{
    /**
     * Uskutečněná plnění mimo tuzemský řádek 1/2, která převod zná: řádek + přípona → kód
     * předmětu plnění (jen ř. 25). Výchozí číselník Money vede `19Ř25` u odpadu (předmět
     * plnění 5) a `19Ř25_S` u stavebních prací (4). Řádky 23, 24 a 31 Money členění
     * převod nezařazuje.
     */
    private const SALE_ROWS = [
        '20|' => null,
        '21|' => null,
        '22|' => null,
        '25|' => '5',
        '25|_S' => '4',
        '26|' => null,
        '50|' => null,
    ];

    /** `19Ř51BN` - osvobozené plnění mimo koeficient (Money ho vede jen ř. 51, bez ř. 50). */
    private const EXEMPT_OUTSIDE_COEFFICIENT = '51|BN';

    /**
     * @return array{in_return:bool, code:?string, deduction:'full'|'reduced'|'none'}|null
     *   `code` null = tuzemské plnění, kód se odvodí ze sazby; null celé = členění převod
     *   nezná
     */
    public static function resolve(string $code, bool $issued): ?array
    {
        if (preg_match('/^\d{2}Ř\s*([0-9][0-9 ,]*?)\s*([A-Z_]*)$/u', trim($code), $m) !== 1) {
            return null;
        }
        $rows = array_map('intval', preg_split('/[\s,]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $suffix = $m[2];
        if ($rows === [0]) {
            return ['in_return' => false, 'code' => null, 'deduction' => 'none'];
        }
        if (!$issued) {
            if ($rows !== [40, 41]) {
                return null;
            }
            return match ($suffix) {
                '', 'M', 'P', '_S' => ['in_return' => true, 'code' => null, 'deduction' => 'full'],
                'K', 'MK', 'PK' => ['in_return' => true, 'code' => null, 'deduction' => 'reduced'],
                default => null,
            };
        }
        if ($rows === [1, 2]) {
            return in_array($suffix, ['', '_S'], true) ? ['in_return' => true, 'code' => null, 'deduction' => 'full'] : null;
        }
        $saleCode = count($rows) === 1 ? self::saleCode($rows[0], $suffix) : null;
        return $saleCode === null ? null : ['in_return' => true, 'code' => $saleCode, 'deduction' => 'full'];
    }

    /** Řádek samovyměření na výstupu (ř. 3–13) na interním dokladu Money. */
    public static function isReverseChargeOutput(string $code): bool
    {
        return preg_match('/^\d{2}Ř\s*(' . self::outputPairsPattern() . ')/u', trim($code)) === 1;
    }

    /**
     * Samovyměření z interního dokladu Money: výstupní řádek (ř. 3/5/7/10/12) určí kód
     * zařazení, zrcadlový řádek odpočtu (ř. 43,44 s příponou jako u ř. 40,41) nárok.
     * Tuzemský přenos (ř. 10) rozliší předmět plnění z řádku dokladu (4 stavební práce,
     * 5 odpad, 3 nemovitost). Bez zrcadlového řádku odpočet nebyl ('none').
     *
     * @return array{code:string, deduction:'full'|'reduced'|'none'}|null null = převod nezařadí
     */
    public static function reverseCharge(string $output, ?string $mirror, string $subject = ''): ?array
    {
        if (preg_match('/^\d{2}Ř\s*(' . self::outputPairsPattern() . ')(_S)?\s*$/u', trim($output), $m) !== 1) {
            return null;
        }
        $code = Lines::selfAssessmentCode((int) substr($m[1], 0, 2), trim($subject));
        if ($code === null) {
            return null;
        }
        if ($mirror === null || trim($mirror) === '') {
            return ['code' => $code, 'deduction' => 'none'];
        }
        if (preg_match('/^\d{2}Ř\s*43,44\s*([A-Z]*)$/u', trim($mirror), $mm) !== 1) {
            return null;
        }
        $deduction = match ($mm[1]) {
            '', 'M', 'P' => 'full',
            'K', 'MK', 'PK' => 'reduced',
            default => null,
        };
        return $deduction === null ? null : ['code' => $code, 'deduction' => $deduction];
    }

    private static function saleCode(int $row, string $suffix): ?string
    {
        $key = $row . '|' . $suffix;
        if ($key === self::EXEMPT_OUTSIDE_COEFFICIENT) {
            return Lines::EXEMPT_OUTSIDE_COEFFICIENT_CODE;
        }
        return array_key_exists($key, self::SALE_ROWS) ? Lines::saleCode($row, self::SALE_ROWS[$key]) : null;
    }

    /** Výstupní dvojice samovyměření tak, jak je Money píše v kódu (`03,04|05,06|…`). */
    private static function outputPairsPattern(): string
    {
        return implode('|', array_map(static fn (array $p): string => sprintf('%02d,%02d', $p[0], $p[1]), Lines::SELF_ASSESSMENT_PAIRS));
    }
}
