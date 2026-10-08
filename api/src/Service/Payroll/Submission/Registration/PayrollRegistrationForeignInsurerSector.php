<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Sektor cizozemského nositele pojištění (`forin/@sec`, ID 10101).
 *
 * EDV 1.4.0.6, list CIS_Sektor (EESSI): hodnota mimo číselník se zamítá.
 * Platí pro přihlášku A1 i pro oznámení A6 a A7, proto ho drží jedna třída.
 */
final class PayrollRegistrationForeignInsurerSector
{
    /** @var array<string,string> kód → název (EDV, číselník Sektor EESSI) */
    public const CODES = [
        '01' => 'Pracovní úrazy a nemoci z povolání',
        '02' => 'Rodinné dávky',
        '03' => 'Vše',
        '04' => 'Důchody',
        '05' => 'Vymáhání a zápočty',
        '06' => 'Nemoc',
        '07' => 'Dávky v nezaměstnanosti',
        '08' => 'Jiné',
    ];

    public static function isKnown(string $code): bool
    {
        return array_key_exists($code, self::CODES);
    }
}
