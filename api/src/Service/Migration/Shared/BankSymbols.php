<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Platební symboly převzatých bankovních pohybů. Dvě pravidla podle toho, jak zdroj
 * symbol ukládá:
 *
 *  - {@see variableSymbolAndDescription()}: pole VS obsahuje buď symbol, nebo něco jiného
 *    (reference platby kartou o 13–15 číslicích, text). Platný VS jsou jen číslice,
 *    nejvýš {@see VariableSymbolNormalizer::MAX_LENGTH} platných (vodicí nuly se
 *    nepočítají, jako v GPC); cokoli jiného není VS a zůstane v popisu pohybu, jinak by
 *    výpis nešel vyexportovat do GPC a párování by dostalo nesmyslný symbol. (Money S3,
 *    POHODA.)
 *  - {@see digitsSymbol()}: pole nese symbol s oddělovači (homebanking PREMIER), bere se
 *    jen z číslic; delší než pole nebo samé nuly = bez symbolu.
 */
final class BankSymbols
{
    /**
     * @param bool $zeroIsEmpty VS ze samých nul = bez symbolu (POHODA); false = převezme se
     *        tak, jak je (Money S3)
     * @return array{0:?string,1:?string} [variabilní symbol, popis zkrácený na 255 znaků]
     */
    public static function variableSymbolAndDescription(string $raw, string $description, bool $zeroIsEmpty): array
    {
        $valid = self::isVariableSymbol($raw);
        if (!$valid) {
            $description = trim($description . ' (ref. ' . $raw . ')');
        }
        return [
            $valid && $raw !== '' && (!$zeroIsEmpty || ltrim($raw, '0') !== '') ? $raw : null,
            $description !== '' ? mb_substr($description, 0, 255) : null,
        ];
    }

    /** Prázdné pole, nebo jen číslice s nejvýš MAX_LENGTH platnými. */
    public static function isVariableSymbol(string $raw): bool
    {
        return $raw === '' || (ctype_digit($raw) && strlen(ltrim($raw, '0')) <= VariableSymbolNormalizer::MAX_LENGTH);
    }

    /**
     * Symbol platby jen z číslic hodnoty. Prázdný, samé nuly nebo s víc platnými číslicemi,
     * než pole pojme = bez symbolu; vodicí nuly zůstanou, pokud se vejdou.
     */
    public static function digitsSymbol(string $value, int $maxLength): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        $significant = ltrim($digits, '0');
        if ($significant === '' || strlen($significant) > $maxLength) {
            return null;
        }
        return strlen($digits) <= $maxLength ? $digits : $significant;
    }
}
