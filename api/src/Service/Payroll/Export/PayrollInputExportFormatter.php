<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use MyInvoice\Repository\Payroll\PayrollInputFilter;

/**
 * Převod řádku mzdového vstupu na řádek exportu (XLSX i PDF).
 *
 * Obě podoby exportu čtou tytéž hodnoty odsud, aby se Excel a tisková sestava
 * nerozešly ani v názvu stavu, ani v tom, jak se počítá sazba.
 */
final class PayrollInputExportFormatter
{
    /** Popisky stavů, shodné s `payroll.components.input_status` v cs.json. */
    private const STATUS_LABELS = [
        'draft' => 'Koncept',
        'approved' => 'Schválený',
        'locked' => 'Uzamčený během',
        'cancelled' => 'Zrušený',
    ];

    /** Popisky zdrojů, shodné s `payroll.components.source` v cs.json. */
    private const SOURCE_LABELS = [
        'manual' => 'Ruční vstup',
        'recurring' => 'Pravidelný předpis',
        'time' => 'Docházka',
        'absence' => 'Absence',
        'import' => 'Import',
        'correction' => 'Oprava',
        'travel' => 'Pracovní cesta',
    ];

    /** Popisky vztahů, shodné s `payroll.people.relations` v cs.json. */
    private const RELATION_LABELS = [
        'employment' => 'Pracovní poměr (mimo výkon funkce)',
        'small_scale_employment' => 'Zaměstnání malého rozsahu',
        'dpp' => 'Dohoda o provedení práce',
        'dpc' => 'Dohoda o pracovní činnosti',
        'partner_dependent' => 'Příjem společníka',
        'statutory_body' => 'Odměna za výkon funkce',
    ];

    /**
     * Jednotka množství. Katalog složek jednotku nevede; jistá je jen u hodinové
     * mzdy, kde množství znamená odpracované hodiny. Jinde zůstává prázdná,
     * vymyšlená jednotka by byla horší než žádná.
     */
    private const UNIT_BY_KIND = [
        'hourly_wage' => 'h',
    ];

    /**
     * @param array<string,mixed> $row řádek z {@see \MyInvoice\Repository\Payroll\PayrollInputRepository::exportPage()}
     * @return array{employee_id:int,personal_number:string,name:string,relation:string,component_code:string,
     *   component_name:string,unit:string,quantity:?float,rate:?float,amount:float,amount_minor:int,
     *   status:string,source:string,import:string,row_key:int,row_version:int}
     */
    public static function row(array $row): array
    {
        $amountMinor = (int) ($row['amount_minor'] ?? 0);
        $milli = isset($row['quantity_milliunits']) ? (int) $row['quantity_milliunits'] : null;
        $importId = isset($row['import_id']) ? (int) $row['import_id'] : null;

        return [
            'employee_id' => (int) ($row['employee_id'] ?? 0),
            'personal_number' => (string) ($row['employment_code'] ?? ''),
            'name' => (string) ($row['employee_name'] ?? ''),
            'relation' => self::relationLabel((string) ($row['relation_type'] ?? '')),
            'component_code' => (string) ($row['component_code'] ?? ''),
            'component_name' => (string) ($row['component_name'] ?? ''),
            'unit' => $milli === null ? '' : (self::UNIT_BY_KIND[(string) ($row['component_kind'] ?? '')] ?? ''),
            'quantity' => self::quantity($milli),
            'rate' => self::rate($amountMinor, $milli),
            'amount' => $amountMinor / 100.0,
            'amount_minor' => $amountMinor,
            'status' => self::statusLabel((string) ($row['status'] ?? '')),
            'source' => self::sourceLabel((string) ($row['source_kind'] ?? '')),
            'import' => self::importLabel($importId, isset($row['import_name']) ? (string) $row['import_name'] : null),
            'row_key' => (int) ($row['id'] ?? 0),
            'row_version' => (int) ($row['row_version'] ?? 0),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public static function sourceLabel(string $source): string
    {
        return self::SOURCE_LABELS[$source] ?? $source;
    }

    public static function relationLabel(string $relation): string
    {
        return self::RELATION_LABELS[$relation] ?? $relation;
    }

    public static function quantity(?int $milliunits): ?float
    {
        return $milliunits === null ? null : $milliunits / 1000;
    }

    /**
     * Sazba za jednotku = částka / množství, v Kč na dvě místa.
     *
     * Vstup sazbu neukládá, ukládá částku a množství. Sazba se proto dopočítává
     * a počítá se v haléřích, ne ve float Kč: `round(180.98 * 24.25, 2)` už
     * jednou utekl o haléř.
     */
    public static function rate(int $amountMinor, ?int $milliunits): ?float
    {
        if ($milliunits === null || $milliunits === 0) {
            return null;
        }
        $rateMinor = (int) round($amountMinor * 1000 / $milliunits);

        return $rateMinor / 100;
    }

    public static function importLabel(?int $importId, ?string $name): string
    {
        if ($importId === null) {
            return '';
        }
        $name = $name === null ? '' : trim($name);

        return $name === '' ? '#' . $importId : '#' . $importId . ' ' . $name;
    }

    /** Částka v haléřích jako „1 234,56". */
    public static function money(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return $sign
            . number_format(intdiv($absolute, 100), 0, ',', ' ')
            . ','
            . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Množství bez zbytečných nul: 8 → „8", 7,5 → „7,5". */
    public static function quantityText(?float $quantity): string
    {
        if ($quantity === null) {
            return '';
        }
        $text = rtrim(rtrim(number_format($quantity, 3, ',', ' '), '0'), ',');

        return $text === '-0' ? '0' : $text;
    }

    /**
     * `2026-06-01` → `06/2026`; s rozsahem `01/2026 – 08/2026`.
     *
     * Rozsah se do popisku musí dostat celý. Filtr `period_to` export přijímá
     * (čte se jím historie jednoho vztahu), ale hlavička odvozená jen ze
     * začátku by osmiměsíční sestavu označila jako jediný měsíc — a to je
     * tvrzení o obsahu, které v exportu nikdo nemá jak ověřit.
     */
    public static function periodLabel(string $periodStart, ?string $periodEnd = null): string
    {
        $start = substr($periodStart, 5, 2) . '/' . substr($periodStart, 0, 4);
        if ($periodEnd === null || substr($periodEnd, 0, 7) === substr($periodStart, 0, 7)) {
            return $start;
        }

        return $start . ' – ' . substr($periodEnd, 5, 2) . '/' . substr($periodEnd, 0, 4);
    }

    /** Období v názvu souboru: `2026-06`, u rozsahu `2026-01_2026-08`. */
    public static function periodSlug(string $periodStart, ?string $periodEnd = null): string
    {
        $start = substr($periodStart, 0, 7);
        if ($periodEnd === null || substr($periodEnd, 0, 7) === $start) {
            return $start;
        }

        return $start . '_' . substr($periodEnd, 0, 7);
    }

    /**
     * Text, který by tabulkový procesor mohl vyhodnotit jako vzorec
     * (CSV/formula injection). Buňka se pak zapisuje výslovně jako text
     * s apostrofovým prefixem; obsah se nemění, aby ho šlo zpětně načíst.
     */
    public static function isFormulaLike(string $value): bool
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true);
    }

    /**
     * Použitý filtr čitelně, řádek po řádku. Bez filtru se to řekne výslovně,
     * aby z hlavičky bylo poznat, že nic nechybí.
     *
     * @param array{employee:?string,employment:?string,components:array<int,string>,import:?string} $labels
     * @return list<array{label:string,value:string}>
     */
    public static function filterLines(PayrollInputFilter $filter, array $labels): array
    {
        $lines = [];
        if ($filter->q !== null) {
            $lines[] = ['label' => 'Hledaný text', 'value' => $filter->q];
        }
        if ($filter->employmentId !== null) {
            $lines[] = ['label' => 'Pracovní vztah', 'value' => $labels['employment'] ?? '#' . $filter->employmentId];
        }
        if ($filter->employeeId !== null) {
            $lines[] = ['label' => 'Zaměstnanec', 'value' => $labels['employee'] ?? '#' . $filter->employeeId];
        }
        if ($filter->componentIds !== []) {
            $lines[] = [
                'label' => 'Složky',
                'value' => implode(', ', array_map(
                    static fn (int $id): string => $labels['components'][$id] ?? '#' . $id,
                    $filter->componentIds,
                )),
            ];
        }
        if ($filter->componentCodes !== []) {
            $lines[] = ['label' => 'Kódy složek', 'value' => implode(', ', $filter->componentCodes)];
        }
        if ($filter->statuses !== []) {
            $lines[] = [
                'label' => 'Stav',
                'value' => implode(', ', array_map(self::statusLabel(...), $filter->statuses)),
            ];
        }
        if ($filter->sourceKinds !== []) {
            $lines[] = [
                'label' => 'Zdroj',
                'value' => implode(', ', array_map(self::sourceLabel(...), $filter->sourceKinds)),
            ];
        }
        if ($filter->importId !== null) {
            $lines[] = ['label' => 'Importní dávka', 'value' => self::importLabel($filter->importId, $labels['import'])];
        }
        if ($lines === []) {
            $lines[] = ['label' => 'Filtr', 'value' => 'bez filtru, všechny vstupy období kromě zrušených'];
        }

        return $lines;
    }
}
