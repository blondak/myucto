<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Adresa cizozemského nositele pojištění (`forin`, ID 10094 až 10098).
 *
 * EDV 1.4.0.6: je-li uvedena jakákoli část adresy kromě státu, jsou povinné
 * číslo popisné, PSČ a obec. Pravidlo platí pro přihlášku A1 i pro oznámení
 * A6 a A7, proto ho drží jedna třída.
 */
final class PayrollRegistrationForeignInsurerAddress
{
    /** Části adresy, jejichž přítomnost zakládá povinnost. */
    public const PARTS = ['street', 'house_number', 'orientation_number', 'postal_code', 'city'];

    /** Povinné, je-li uvedena kterákoli část. */
    public const REQUIRED = ['house_number', 'postal_code', 'city'];

    /**
     * Povinné části, které chybí.
     *
     * @param array<string,mixed> $values
     * @return list<string>
     */
    public static function missing(array $values): array
    {
        $given = false;
        foreach (self::PARTS as $part) {
            $given = $given || self::filled($values[$part] ?? null);
        }
        if (!$given) {
            return [];
        }

        return array_values(array_filter(
            self::REQUIRED,
            static fn (string $part): bool => !self::filled($values[$part] ?? null),
        ));
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }
}
