<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

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
 */
final class Ms3VatCode
{
    /** Uskutečněná plnění mimo tuzemský řádek 1/2: řádky + přípona → kód zařazení MyÚčta. */
    private const SALE_CODES = [
        '20|' => '20',
        '21|' => '22',
        '22|' => '26',
        '25|' => '25s5',
        '25|_S' => '25s',
        '26|' => '26s',
        '50|' => '3',
        '51|BN' => '3m',
    ];

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
        $saleCode = count($rows) === 1 ? (self::SALE_CODES[$rows[0] . '|' . $suffix] ?? null) : null;
        return $saleCode === null ? null : ['in_return' => true, 'code' => $saleCode, 'deduction' => 'full'];
    }

    /** Řádek samovyměření na výstupu (ř. 3–13) na interním dokladu Money. */
    public static function isReverseChargeOutput(string $code): bool
    {
        return preg_match('/^\d{2}Ř\s*(03,04|05,06|07,08|10,11|12,13)/u', trim($code)) === 1;
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
        if (preg_match('/^\d{2}Ř\s*(03,04|05,06|07,08|10,11|12,13)(_S)?\s*$/u', trim($output), $m) !== 1) {
            return null;
        }
        $code = match ($m[1]) {
            '03,04' => '23',
            '05,06' => '24e',
            '07,08' => '25',
            '12,13' => '24',
            default => match (trim($subject)) {
                '', '4' => '5',
                '5' => '5c',
                '3' => '5d',
                default => null,
            },
        };
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
}
