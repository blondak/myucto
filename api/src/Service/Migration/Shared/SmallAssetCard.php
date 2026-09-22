<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Karta drobného majetku převzatá z evidence cizího programu jako vstup pro
 * {@see \MyInvoice\Service\Accounting\SmallAsset\SmallAssetService::create()}. Karta nic
 * neúčtuje, náklad je v převedeném deníku.
 *
 * Co je v kartě společné: délky textů podle sloupců, stav podle data vyřazení a důvod
 * vyřazení jen u vyřazené karty. Název, cenu za kus a poznámku skládá zdroj.
 */
final class SmallAssetCard
{
    public const DEFAULT_NAME = 'Drobný majetek';

    /**
     * @param 'tangible'|'intangible' $kind
     * @param string|null $inventoryNumber prázdné = bez čísla ({@see inventoryNumber()})
     * @param string|null $location prázdné = bez umístění
     * @param string|null $disposed datum vyřazení; null = karta v užívání
     * @param array<string,mixed> $extra další pole karty (dodavatel, odpovědná osoba, vazba na doklad)
     * @return array<string,mixed>
     */
    public static function payload(
        string $kind,
        string $name,
        ?string $inventoryNumber,
        string $acquired,
        ?string $inUse,
        int|float $quantity,
        float $unitPrice,
        float $price,
        ?string $location,
        ?string $disposed,
        ?string $disposalReason,
        ?string $notes,
        array $extra = [],
    ): array {
        return [
            'asset_kind' => $kind,
            'name' => mb_substr($name, 0, 255),
            'inventory_number' => $inventoryNumber !== null && $inventoryNumber !== '' ? mb_substr($inventoryNumber, 0, 40) : null,
            'acquisition_date' => $acquired,
            'put_into_use_date' => $inUse,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'price' => $price,
            'location' => $location !== null && $location !== '' ? mb_substr($location, 0, 160) : null,
            'status' => $disposed !== null ? 'disposed' : 'in_use',
            'disposed_at' => $disposed,
            'disposal_reason' => $disposed !== null ? $disposalReason : null,
            'notes' => $notes,
        ] + $extra;
    }

    /**
     * Inventární číslo karty ze zdroje.
     *
     * @param bool $zeroIsEmpty číslo „0" = bez čísla (Money S3, PREMIER); POHODA ho převezme
     */
    public static function inventoryNumber(string $number, bool $zeroIsEmpty): ?string
    {
        return $number !== '' && (!$zeroIsEmpty || $number !== '0') ? $number : null;
    }

    /** Datum vyřazení v převáděném období: nejdřív k datu pořízení, po konci období = v užívání. */
    public static function disposedWithin(?string $disposed, string $acquired, string $end): ?string
    {
        return $disposed !== null && $disposed <= $end ? max($disposed, $acquired) : null;
    }
}
