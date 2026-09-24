<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\WarehouseRepository;

/** Cílový zápis číselníků skladu a vozidel pro převody z účetních programů. */
final class MigratedInventoryWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly WarehouseRepository $warehouses,
        private readonly StockItemRepository $items,
        private readonly CarRepository $cars,
    ) {}

    public function stockEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $card @return array{id:int,created:bool} */
    public function warehouse(int $supplierId, array $card): array
    {
        $code = trim((string) ($card['code'] ?? ''));
        $name = trim((string) ($card['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 20 || $name === '' || mb_strlen($name) > 100) {
            throw new MigratedInventoryException('warehouse_target_invalid', 'Převáděný sklad nemá platný kód nebo název.');
        }
        $existing = $this->warehouses->findByCode($supplierId, $code);
        if ($existing !== null) {
            if ((string) $existing['name'] !== $name) {
                throw new MigratedInventoryException('warehouse_target_conflict', 'Cílový sklad se stejným kódem má jiný název.');
            }
            $this->verifyWarehouse($supplierId, (int) $existing['id'], $card);
            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $id = $this->warehouses->insert($supplierId, [
            'code' => $code, 'name' => $name, 'is_default' => false, 'is_active' => true, 'is_sellable' => true,
        ]);
        return ['id' => $id, 'created' => true];
    }

    /** @param array<string,mixed> $card */
    public function verifyWarehouse(int $supplierId, int $id, array $card): void
    {
        $row = $this->warehouses->find($supplierId, $id);
        if ($row === null || $row['code'] !== $card['code'] || $row['name'] !== $card['name']
            || $row['is_active'] !== true || $row['is_sellable'] !== true) {
            throw new MigratedInventoryException('inventory_target_changed', 'Cílový sklad chybí nebo se změnil.');
        }
    }

    /** @param array<string,mixed> $card @return array{id:int,created:bool} */
    public function item(int $supplierId, array $card): array
    {
        $sku = trim((string) ($card['sku'] ?? ''));
        if ($sku === '' || mb_strlen($sku) > 50 || trim((string) ($card['name'] ?? '')) === '') {
            throw new MigratedInventoryException('stock_item_target_invalid', 'Převáděná skladová karta nemá platný kód nebo název.');
        }
        $existing = $this->items->findBySku($supplierId, $sku);
        if ($existing !== null) {
            $this->verifyItem($supplierId, (int) $existing['id'], $card);
            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $id = $this->items->insert($supplierId, [
            'sku' => $sku, 'name' => $card['name'], 'item_type' => $card['item_type'], 'unit' => $card['unit'],
            'tracking_mode' => $card['tracking_mode'], 'ean' => $card['ean'], 'vat_rate_id' => null,
            'sale_price_without_vat' => $card['sale_price_without_vat'], 'min_qty' => $card['min_qty'],
            'intrastat_cn8_code' => $card['intrastat_cn8_code'],
            'intrastat_net_mass_kg' => $card['intrastat_net_mass_kg'],
            'intrastat_supplementary_unit' => $card['intrastat_supplementary_unit'],
            'intrastat_supplementary_unit_coefficient' => $card['intrastat_supplementary_unit_coefficient'],
            'is_active' => $card['is_active'], 'note' => $card['note'],
        ]);
        if ($card['weight_g'] !== null) {
            $this->items->updateEshopFieldsVersioned($supplierId, $id, 1, ['weight_g' => $card['weight_g']]);
        }
        return ['id' => $id, 'created' => true];
    }

    /** @param array<string,mixed> $card */
    public function verifyItem(int $supplierId, int $id, array $card): void
    {
        $row = $this->items->find($supplierId, $id);
        if ($row === null) {
            throw new MigratedInventoryException('inventory_target_changed', 'Cílová skladová karta chybí nebo se změnila.');
        }
        foreach (['sku', 'name', 'item_type', 'unit', 'tracking_mode', 'ean', 'sale_price_without_vat', 'min_qty',
                     'note', 'intrastat_cn8_code', 'intrastat_net_mass_kg', 'intrastat_supplementary_unit',
                     'intrastat_supplementary_unit_coefficient'] as $field) {
            if (($row[$field] === null ? null : (string) $row[$field]) !== ($card[$field] === null ? null : (string) $card[$field])) {
                throw new MigratedInventoryException('inventory_target_changed', 'Cílová skladová karta chybí nebo se změnila.');
            }
        }
        if ((bool) $row['is_active'] !== $card['is_active']
            || ($row['weight_g'] === null ? null : (int) $row['weight_g']) !== $card['weight_g']) {
            throw new MigratedInventoryException('inventory_target_changed', 'Cílová skladová karta chybí nebo se změnila.');
        }
    }

    /** @param array<string,mixed> $card @return array{id:int,created:bool} */
    public function car(int $supplierId, array $card, ?int $userId): array
    {
        $registration = trim((string) ($card['registration'] ?? ''));
        if ($registration === '' || mb_strlen($registration) > 20) {
            throw new MigratedInventoryException('car_target_invalid', 'Převáděné vozidlo nemá platnou registrační značku.');
        }
        $existing = $this->cars->findByRegistration($supplierId, $registration);
        if ($existing !== null) {
            $this->verifyCar($supplierId, (int) $existing['id'], $card);
            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $id = $this->cars->create($supplierId, $card + ['is_default' => false], $userId);
        return ['id' => $id, 'created' => true];
    }

    /** @param array<string,mixed> $card */
    public function verifyCar(int $supplierId, int $id, array $card): void
    {
        $row = $this->cars->find($id, $supplierId);
        if ($row === null || $row['registration'] !== $card['registration'] || $row['name'] !== $card['name']
            || $row['vin'] !== $card['vin'] || $row['fuel_type'] !== $card['fuel_type']
            || $row['odometer_start'] !== $card['odometer_start'] || $row['is_archived'] !== $card['is_archived']
            || $row['note'] !== $card['note']) {
            throw new MigratedInventoryException('inventory_target_changed', 'Cílové vozidlo chybí nebo se změnilo.');
        }
    }
}
