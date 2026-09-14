<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Pohoda;

use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;

/**
 * Syntetický export „Tabulka agendy Personalistika" z POHODY: blok s názvem
 * agendy, firmou, IČ a rokem, hlavička na řádku 4 se sloupci rozházenými
 * s mezerami, data od řádku 6. Jména, rodná čísla i OIČ jsou vymyšlená.
 */
final class PohodaPersonnelFixture
{
    public const COMPANY_ICO = '12345678';

    /**
     * @param list<array{last:string,first:string,birth:string,personal:string,oic:string|int|null}> $people
     */
    public static function workbook(array $people, bool $withOicColumn = true): string
    {
        $rows = [
            1 => ['A' => 'Tabulka agendy Personalistika'],
            2 => ['A' => 'Syntetická firma s.r.o.', 'D' => 'IČ: ' . self::COMPANY_ICO, 'G' => 'Rok: 2026'],
            4 => array_filter([
                'A' => 'Příjmení',
                'C' => 'Jméno',
                'E' => 'Rodné číslo',
                'G' => 'Osobní číslo',
                'I' => $withOicColumn ? 'OIC' : null,
                'K' => 'Vzdělání (ISPV)',
            ], static fn (?string $value): bool => $value !== null),
        ];
        $row = 6;
        foreach ($people as $person) {
            $rows[$row++] = [
                'A' => $person['last'],
                'C' => $person['first'],
                'E' => $person['birth'],
                'G' => $person['personal'],
                'I' => $withOicColumn ? $person['oic'] : null,
                'K' => 'Střední vzdělání s maturitou',
            ];
        }

        return AttendanceFixture::xlsx(['Personalistika' => ['rows' => $rows]]);
    }

    /**
     * @param list<array{last:string,first:string,birth:string,personal:string,oic:string|int|null}> $people
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    public static function file(array $people, string $name = 'personalistika.xlsx'): array
    {
        return AttendanceFixture::file($name, self::workbook($people));
    }
}
