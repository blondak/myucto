<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Forma úhrady dokladu — jediný zdroj pravdy pro doménu hodnot (migrace 1128).
 *
 * Stejná sada platí pro vydané (`invoices.payment_method`) i přijaté faktury
 * (`purchase_invoices.payment_method`), ať se obě strany a exportní mapování
 * nerozcházejí.
 *
 * Klíčová hodnota je `direct_debit` (inkaso / SIPO): peníze si strhne DODAVATEL sám,
 * takže se na takovou fakturu NESMÍ vystavit platební příkaz — jinak zaplatíme dvakrát.
 * Poznat to z dokladu nejde: inkasní faktura má číslo účtu, VS i KS úplně stejně jako
 * převodní, proto je forma úhrady samostatný atribut, ne odvozenina.
 *
 * `payment_method_source` říká, KDO hodnotu nastavil, a řídí, kdo ji smí přepsat:
 *
 *     manual (3)  >  ai (2)  >  vendor (1)  >  default (0)
 *
 * Automatika (AI extrakce, předvolba dodavatele) tedy NIKDY nepřepíše rozhodnutí
 * účetní. Stejná úroveň se přepsat smí (re-extrakce AI, změna předvolby dodavatele).
 */
final class PaymentMethods
{
    public const DEFAULT = 'bank_transfer';

    /** @var list<string> */
    public const ALL = [
        'bank_transfer',
        'direct_debit',
        'card',
        'cash',
        'cash_on_delivery',
        'offset',
        'other',
    ];

    /** @var list<string> */
    public const SOURCES = ['default', 'vendor', 'ai', 'manual'];

    /** Vyšší číslo = silnější zdroj, smí přepsat slabší i sobě rovný. */
    private const SOURCE_RANK = ['default' => 0, 'vendor' => 1, 'ai' => 2, 'manual' => 3];

    /** Whitelist + fallback na bankovní převod u neznámé/prázdné hodnoty. */
    public static function normalize(mixed $value, string $fallback = self::DEFAULT): string
    {
        $v = is_string($value) ? trim($value) : '';
        return in_array($v, self::ALL, true) ? $v : $fallback;
    }

    /** Whitelist bez fallbacku — null, když hodnota není v doméně (pro nullable sloupce). */
    public static function normalizeNullable(mixed $value): ?string
    {
        $v = is_string($value) ? trim($value) : '';
        return in_array($v, self::ALL, true) ? $v : null;
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ALL, true);
    }

    public static function normalizeSource(mixed $value): string
    {
        $v = is_string($value) ? trim($value) : '';
        return in_array($v, self::SOURCES, true) ? $v : 'default';
    }

    /**
     * Smí `$newSource` přepsat hodnotu nastavenou `$existingSource`?
     * Viz priorita v docblocku třídy — AI ani dodavatel nepřepíšou 'manual'.
     */
    public static function canOverride(mixed $existingSource, mixed $newSource): bool
    {
        $old = self::SOURCE_RANK[self::normalizeSource($existingSource)];
        $new = self::SOURCE_RANK[self::normalizeSource($newSource)];
        return $new >= $old;
    }

    /** Platí se převodem → patří do platebního příkazu. */
    public static function isBankTransfer(mixed $value): bool
    {
        return self::normalize($value) === self::DEFAULT;
    }
}
