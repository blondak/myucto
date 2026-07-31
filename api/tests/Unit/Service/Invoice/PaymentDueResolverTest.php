<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\PaymentDueResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentDueResolverTest extends TestCase
{
    private const SUPPLIER_MONTH = [
        'default_payment_due_days' => 1,
        'default_payment_due_unit' => 'month',
    ];
    private const SUPPLIER_DAYS = [
        'default_payment_due_days' => 7,
        'default_payment_due_unit' => 'days',
    ];

    /**
     * @param array<string,mixed>|null $own
     * @param array<string,mixed>|null $client
     * @param array<string,mixed>|null $supplier
     * @param array{value:int,unit:string} $expected
     */
    #[DataProvider('cases')]
    public function testResolve(?array $own, ?array $client, ?array $supplier, array $expected): void
    {
        self::assertSame($expected, PaymentDueResolver::resolve($own, $client, $supplier));
    }

    /** @return iterable<string, array{0:?array,1:?array,2:?array,3:array{value:int,unit:string}}> */
    public static function cases(): iterable
    {
        yield 'bez čehokoli → 7 dní' => [null, null, null, ['value' => 7, 'unit' => 'days']];

        yield 'jen dodavatel' => [
            null,
            null,
            self::SUPPLIER_MONTH,
            ['value' => 1, 'unit' => 'month'],
        ];

        yield 'klient s hodnotou a vlastní jednotkou' => [
            null,
            ['payment_due_default' => 14, 'payment_due_unit' => 'days'],
            self::SUPPLIER_MONTH,
            ['value' => 14, 'unit' => 'days'],
        ];

        // Regrese 791678df: klient přepisuje jen hodnotu → jednotka zůstává dodavatelova.
        yield 'klient bez jednotky dědí dodavatele' => [
            null,
            ['payment_due_default' => 2, 'payment_due_unit' => null],
            self::SUPPLIER_MONTH,
            ['value' => 2, 'unit' => 'month'],
        ];

        yield 'zakázka s vlastní jednotkou' => [
            ['payment_due_days' => 3, 'payment_due_unit' => 'days'],
            ['payment_due_default' => 1, 'payment_due_unit' => 'month'],
            self::SUPPLIER_MONTH,
            ['value' => 3, 'unit' => 'days'],
        ];

        // Jádro opravy: NULL jednotka zakázky se dřív brala jako dny, i když
        // klient (a přes něj dodavatel) znamenal kalendářní měsíce.
        yield 'zakázka bez jednotky dědí klienta' => [
            ['payment_due_days' => 2, 'payment_due_unit' => null],
            ['payment_due_default' => 1, 'payment_due_unit' => 'month'],
            self::SUPPLIER_DAYS,
            ['value' => 2, 'unit' => 'month'],
        ];

        yield 'zakázka bez jednotky dědí přes klienta až dodavatele' => [
            ['payment_due_days' => 1, 'payment_due_unit' => null],
            ['payment_due_default' => null, 'payment_due_unit' => null],
            self::SUPPLIER_MONTH,
            ['value' => 1, 'unit' => 'month'],
        ];

        // Jednotka patří k hodnotě: když klient hodnotu nemá, bere se celá dvojice
        // od dodavatele. Osiřelá klientská jednotka (bez hodnoty) se ignoruje —
        // jinak by „1 měsíc" dodavatele mlčky přepsala na 1 DEN.
        yield 'klient bez hodnoty s vlastní jednotkou' => [
            null,
            ['payment_due_default' => null, 'payment_due_unit' => 'days'],
            self::SUPPLIER_MONTH,
            ['value' => 1, 'unit' => 'month'],
        ];

        // Explicitní 0 je platná hodnota (splatnost = datum vystavení), ne „nenastaveno".
        yield 'nula od klienta se ctí' => [
            null,
            ['payment_due_default' => 0, 'payment_due_unit' => null],
            self::SUPPLIER_DAYS,
            ['value' => 0, 'unit' => 'days'],
        ];

        // Neznámá jednotka v datech se chová jako „nenastaveno" → dědí se dál.
        yield 'nesmyslná jednotka se ignoruje' => [
            ['payment_due_days' => 1, 'payment_due_unit' => 'weeks'],
            null,
            self::SUPPLIER_MONTH,
            ['value' => 1, 'unit' => 'month'],
        ];
    }

    public function testDueDateUsesCalendarMonths(): void
    {
        self::assertSame(
            '2026-02-28',
            PaymentDueResolver::dueDate(
                '2026-01-31',
                ['payment_due_days' => 1, 'payment_due_unit' => null],
                ['payment_due_default' => null, 'payment_due_unit' => 'month'],
                self::SUPPLIER_DAYS,
            ),
        );
    }
}
