<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\Ms3Journal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Středisko a zakázka z řádku deníku Money. Registrační značku vozidla účetní do zakázky
 * píšou v různých tvarech — musí z nich vzniknout jedna zakázka, ne tři.
 */
final class JournalDimensionsTest extends TestCase
{
    /** @return iterable<string,array{string,?string}> */
    public static function plates(): iterable
    {
        yield 'mezera' => ['1AB 2345', '1AB 2345'];
        yield 'bez mezery' => ['3CD4567', '3CD 4567'];
        yield 'tečka na konci' => ['5E6 7890.', '5E6 7890'];
        yield 'malá písmena' => ['5e67890', '5E6 7890'];
        yield 'starší šestimístná' => ['7B 9884', '7B 9884'];
        yield 'elektromobil' => ['EL123AB', 'EL 123 AB'];
        yield 'elektromobil s mezerami' => ['EL 456 CD', 'EL 456 CD'];
        yield 'zakázka projektu' => ['PRJ1024', null];
        yield 'číslo smlouvy' => ['018908-017', null];
        yield 'lokalita' => ['LOKALITA', null];
        yield 'číselný kód' => ['5500020', null];
    }

    #[DataProvider('plates')]
    public function testVehiclePlateIsNormalised(string $code, ?string $expected): void
    {
        self::assertSame($expected, Ms3Journal::vehiclePlate($code));
    }

    public function testJobCodeMergesPlateVariantsAndKeepsOtherCodes(): void
    {
        self::assertSame(Ms3Journal::jobCode(['Zakazka' => '5E6 7890.']), Ms3Journal::jobCode(['Zakazka' => '5E67890']));
        self::assertSame('PRJ2026', Ms3Journal::jobCode(['Zakazka' => ' PRJ2026 ']));
        self::assertNull(Ms3Journal::jobCode(['Zakazka' => '  ']));
    }

    public function testCostCentreComesFromStredNotFromZakazka(): void
    {
        self::assertSame('REŽIE', Ms3Journal::costCenter(['Stred' => 'REŽIE', 'Zakazka' => 'PRJ1024']));
        self::assertNull(Ms3Journal::costCenter(['Zakazka' => 'PRJ1024']));
    }
}
