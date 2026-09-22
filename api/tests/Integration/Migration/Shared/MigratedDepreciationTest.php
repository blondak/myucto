<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Migration\Shared\MigratedDepreciation;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MigratedDepreciationTest extends SharedMigrationDbTestCase
{
    public function testOwnPolicyKeepsAccountingRowBookedInMyUcto(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $migrated = new MigratedDepreciation($entries);

        $first = $migrated->confirm($supplier, $asset, 'accounting', 2024, 1000.0, 1000.0, 9000.0, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, true);
        self::assertTrue($first['written']);
        $row = $entries->findYear($asset, 'accounting', 2024);
        self::assertTrue(DepreciationEntryRepository::isBookedByMigratedJournal($row));
        self::assertSame(['journal' => DepreciationEntryRepository::MIGRATED_JOURNAL, 'program' => 'Money S3'], is_string($row['detail']) ? json_decode($row['detail'], true) : $row['detail']);

        self::assertFalse($migrated->confirm($supplier, $asset, 'accounting', 2024, 1000.0, 1000.0, 9000.004, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, true)['written'], 'shodný řádek');
        $again = $migrated->confirm($supplier, $asset, 'accounting', 2024, 1100.0, 1100.0, 8900.0, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, true);
        self::assertTrue($again['written'], 'vlastní řádek převodu se srovná');
        self::assertSame(1000.0, (float) $again['previous']['amount']);

        $this->db->pdo()->prepare('UPDATE depreciation_entries SET detail = NULL WHERE asset_id = ?')->execute([$asset]);
        self::assertFalse($migrated->confirm($supplier, $asset, 'accounting', 2024, 1200.0, 1200.0, 8800.0, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, true)['written'], 'řádek zaúčtovaný v MyÚčtu se nepřepíše');
    }

    public function testTaxRowIsOverwrittenRegardlessOfOrigin(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $migrated = new MigratedDepreciation($entries);
        // Potvrzené přerušení odpisu v MyÚčtu (řádek bez původu převodu).
        $entries->upsert(['supplier_id' => $supplier, 'asset_id' => $asset, 'kind' => 'tax', 'fiscal_year' => 2024, 'amount' => 0.0, 'full_amount' => 0.0,
            'residual_value_end' => 10000.0, 'is_paused' => true, 'is_half' => false, 'months_count' => null, 'detail' => null, 'status' => 'confirmed']);

        $result = $migrated->confirm($supplier, $asset, 'tax', 2024, 2000.0, 2000.0, 8000.0, false, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_OWN, false);
        self::assertTrue($result['written']);
        self::assertTrue((bool) $result['previous']['is_paused']);
        self::assertSame(2000.0, (float) $entries->findYear($asset, 'tax', 2024)['amount']);
    }

    public function testClampWritesZeroButComparesRawResidual(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $migrated = new MigratedDepreciation($entries);
        $migrated->confirm($supplier, $asset, 'tax', 2024, 500.0, 500.0, -3.0, false, false, null, 'Money S3', 'confirmed', MigratedDepreciation::OVERWRITE_OWN, true, true);
        self::assertSame(0.0, (float) $entries->findYear($asset, 'tax', 2024)['residual_value_end']);
        self::assertTrue($migrated->confirm($supplier, $asset, 'tax', 2024, 500.0, 500.0, -3.0, false, false, null, 'Money S3', 'confirmed', MigratedDepreciation::OVERWRITE_OWN, true, true)['written'],
            'nezkrácená zůstatková cena se od zapsané liší, řádek se zapíše znovu (stejné hodnoty)');
    }

    /** @return array{0:int,1:int,2:DepreciationEntryRepository} */
    private function asset(): array
    {
        $supplier = $this->supplier();
        $this->db->pdo()->prepare("INSERT INTO assets (supplier_id, inventory_number, name, acquisition_date, input_price) VALUES (?, 'M-1', 'Stroj', '2020-01-01', 10000)")
            ->execute([$supplier]);
        return [$supplier, (int) $this->db->pdo()->lastInsertId(), new DepreciationEntryRepository($this->db)];
    }
}
