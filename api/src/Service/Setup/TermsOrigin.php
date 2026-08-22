<?php

declare(strict_types=1);

namespace MyInvoice\Service\Setup;

/**
 * H-33 — původ souhlasu s licenčním ujednáním a obchodními podmínkami.
 *
 * `terms_accepted` zůstává povinné. Když ale setup voláme my ze serveru,
 * odklikáváme souhlas za zákazníka a v auditním logu instance to pak vypadá,
 * že u toho byl. Volitelný blok `terms_origin` proto do logu doplní, odkud se
 * souhlas vzal — číslo objednávky, čas a IP, ze které přišel.
 */
final class TermsOrigin
{
    public const REQUEST_FIELD = 'terms_origin';

    /** Ochrana auditního logu před nafouknutím cizím vstupem. */
    private const MAX_LENGTH = 190;

    private const FIELDS = ['order_number', 'accepted_at', 'ip'];

    /**
     * @return array<string,string>|null null = blok nebyl poslán (nebo je prázdný)
     */
    public static function normalize(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $raw[$field] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $out[$field] = mb_substr($value, 0, self::MAX_LENGTH);
        }

        return $out === [] ? null : $out;
    }
}
