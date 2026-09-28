<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbraImportRepository;

final class AbraAssetImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly AbraImportRepository $imports,
    ) {}

    /** @return array{created:int,skipped:int,changed:int,failed:int,warnings:list<string>,counts:array<string,int>} */
    public function import(int $supplierId, int $userId, array $rows, int $lastYear, callable $cancelled): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'changed' => 0, 'failed' => 0, 'warnings' => [], 'counts' => []];
        $mapper = new AbraAssetMapper();
        $fixed = $this->db->pdo()->prepare('INSERT INTO assets
            (supplier_id, inventory_number, name, description, kind, asset_account_code,
             accumulated_account_code, acquisition_account_code, input_price, acquisition_date,
             put_into_use_date, status, tax_method, created_by)
            VALUES (?, ?, ?, ?, "tangible", ?, ?, "042", ?, ?, ?, "draft", "none", ?)');
        $small = $this->db->pdo()->prepare('INSERT INTO small_assets
            (supplier_id, name, inventory_number, acquisition_date, put_into_use_date,
             quantity, unit_price, price, status, disposed_at, notes, created_by)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            if ($cancelled()) throw new \RuntimeException('asset_import_cancelled');
            $plan = $mapper->map($row);
            if ($plan['acquired'] !== null && $plan['acquired'] > $lastYear . '-12-31') continue;
            if ($plan['disposed'] !== null && $plan['disposed'] < $lastYear . '-01-01') continue;
            if ($plan['blockers'] !== []) {
                $result['failed']++;
                array_push($result['warnings'], ...$plan['blockers']);
                continue;
            }
            $existing = $this->imports->lookup($supplierId, 'majetek', $plan['source_key']);
            if ($existing !== null) {
                if (hash_equals((string) $existing['source_hash'], $plan['source_hash'])) {
                    $result['skipped']++;
                } else {
                    $result['changed']++;
                    $result['warnings'][] = 'asset_source_changed';
                }
                continue;
            }
            $price = number_format($plan['price'], 2, '.', '');
            if ($plan['type'] === 'asset') {
                $fixed->execute([$supplierId, $plan['number'], $plan['name'], $plan['description'] ?: null,
                    $plan['account'], $plan['accumulated'] ?: null, $price, $plan['acquired'],
                    $plan['started'], $userId > 0 ? $userId : null]);
                $result['counts']['assets_created'] = ($result['counts']['assets_created'] ?? 0) + 1;
            } else {
                $small->execute([$supplierId, $plan['name'], $plan['number'], $plan['acquired'],
                    $plan['started'], $price, $price, $plan['disposed'] !== null ? 'disposed' : 'in_use',
                    $plan['disposed'], $plan['description'] ?: null, $userId > 0 ? $userId : null]);
                $result['counts']['small_assets_created'] = ($result['counts']['small_assets_created'] ?? 0) + 1;
            }
            $this->imports->remember($supplierId, 'majetek', $plan['source_key'], $plan['source_hash'],
                $plan['type'], (int) $this->db->pdo()->lastInsertId(), $lastYear);
            $result['created']++;
        }
        if (($result['counts']['assets_created'] ?? 0) > 0) $result['warnings'][] = 'asset_depreciation_review_required';
        $result['warnings'] = array_values(array_unique($result['warnings']));
        return $result;
    }
}
