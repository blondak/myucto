<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Jediné místo, které rozhoduje o splatnosti (hodnota + jednotka) napříč
 * novou fakturou, klonem a pravidelnou fakturou.
 *
 * Pravidla — hodnota a jednotka se řeší ZVLÁŠŤ:
 *
 *   * Hodnota:  zakázka/šablona → klient → dodavatel → 7. NULL přeskakujeme,
 *               explicitní 0 ctíme (= splatnost v den vystavení).
 *   * Jednotka: NULL na kterékoli úrovni znamená „zděď z nadřazené", tedy
 *               zakázka/šablona → klient → dodavatel ('days' | 'month', NOT NULL).
 *               Dědí se ale jen od úrovně, ze které pochází HODNOTA, a výš —
 *               jednotka zakázky nemá co mluvit do hodnoty převzaté od klienta.
 *               Osiřelá jednotka bez hodnoty se tím pádem ignoruje: klientovo
 *               'days' bez `payment_due_default` nesmí z dodavatelova „1 měsíce"
 *               udělat 1 den.
 *
 * Bez tohohle sjednocení se větve rozcházely: klient s vlastní hodnotou a bez
 * jednotky dědil dodavatele, ale zakázka se stejným NULL spadla natvrdo na dny.
 */
final class PaymentDueResolver
{
    private const FALLBACK_VALUE = 7;
    private const FALLBACK_UNIT  = 'days';

    /**
     * @param array<string,mixed>|null $own      zakázka nebo šablona (payment_due_days, payment_due_unit)
     * @param array<string,mixed>|null $client   klient (payment_due_default, payment_due_unit)
     * @param array<string,mixed>|null $supplier dodavatel (default_payment_due_days, default_payment_due_unit)
     *
     * @return array{value:int,unit:string}
     */
    public static function resolve(?array $own, ?array $client, ?array $supplier): array
    {
        $clientUnit = self::unit($client, 'payment_due_unit')
            ?? self::unit($supplier, 'default_payment_due_unit');

        if ($own !== null && ($own['payment_due_days'] ?? null) !== null) {
            return [
                'value' => (int) $own['payment_due_days'],
                'unit'  => self::unit($own, 'payment_due_unit') ?? $clientUnit ?? self::FALLBACK_UNIT,
            ];
        }

        if ($client !== null && ($client['payment_due_default'] ?? null) !== null) {
            return [
                'value' => (int) $client['payment_due_default'],
                'unit'  => $clientUnit ?? self::FALLBACK_UNIT,
            ];
        }

        if ($supplier !== null && ($supplier['default_payment_due_days'] ?? null) !== null) {
            return [
                'value' => (int) $supplier['default_payment_due_days'],
                'unit'  => self::unit($supplier, 'default_payment_due_unit') ?? self::FALLBACK_UNIT,
            ];
        }

        return ['value' => self::FALLBACK_VALUE, 'unit' => self::FALLBACK_UNIT];
    }

    /**
     * @param array<string,mixed>|null $own
     * @param array<string,mixed>|null $client
     * @param array<string,mixed>|null $supplier
     */
    public static function dueDate(string $issueDate, ?array $own, ?array $client, ?array $supplier): string
    {
        ['value' => $value, 'unit' => $unit] = self::resolve($own, $client, $supplier);

        return DueDateCalculator::calculate($issueDate, $value, $unit);
    }

    /**
     * Neznámou hodnotu bereme jako „nenastaveno" (→ dědí se dál), ne jako dny —
     * jinak by překlep v datech tiše zafixoval jednotku na nejnižší úrovni.
     *
     * @param array<string,mixed>|null $row
     */
    private static function unit(?array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return in_array($value, ['days', 'month'], true) ? (string) $value : null;
    }
}
