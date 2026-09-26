<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxInventory;
use PHPUnit\Framework\TestCase;

final class StereoNxInventoryTest extends TestCase
{
    public function testPreparesOnlyVerifiedStockAndVehicleFields(): void
    {
        $tables = $this->tables();
        $tables['SCenik'][] = [
            'Sklad' => 'HLAVNI', 'PolozkaSkladu' => 'NEPODPOROVANA', 'TypPolozky' => 'L',
            'Nazev' => 'Nemá cílový typ', 'Jednotka' => 'ks',
        ];
        $tables['Kcesty'][] = ['Cesta' => 'SYN-ROUTE', 'Odkud' => 'A', 'Kam' => 'B', 'Vzdalenost' => 12.0];

        $plan = StereoNxInventory::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertSame(1, $plan['counts']['warehouses_ready']);
        self::assertSame(1, $plan['counts']['stock_items_ready']);
        self::assertSame(1, $plan['counts']['stock_items_skipped']);
        self::assertSame(1, $plan['counts']['cars_ready']);
        self::assertSame(1, $plan['counts']['route_templates_skipped']);

        $item = $plan['records']['items'][0];
        self::assertSame('material', $item['item_type']);
        self::assertSame('4006381333931', $item['ean']);
        self::assertSame('serial', $item['tracking_mode']);
        self::assertSame('125.50', $item['sale_price_without_vat']);
        self::assertSame('2.500', $item['min_qty']);
        self::assertSame(750, $item['weight_g']);
        self::assertSame('12345678', $item['intrastat_cn8_code']);
        self::assertSame('0.750', $item['intrastat_net_mass_kg']);
        self::assertSame('KGM', $item['intrastat_supplementary_unit']);
        self::assertSame('1.000000', $item['intrastat_supplementary_unit_coefficient']);
        self::assertTrue($item['is_active']);
        self::assertSame(64, strlen($item['source_hash']));

        $car = $plan['records']['cars'][0];
        self::assertSame('TEST-01', $car['registration']);
        self::assertSame('diesel', $car['fuel_type']);
        self::assertSame(5000, $car['odometer_start']);
        self::assertFalse($car['is_archived']);

        self::assertSame([
            'stock_balance_skipped',
            'stock_vat_rate_skipped',
            'stock_item_type_unsupported',
            'car_details_unmapped',
            'route_templates_skipped',
        ], array_column($plan['warnings'], 'code'));
        $messages = json_encode($plan['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('SYN-001', $messages);
        self::assertStringNotContainsString('TEST-01', $messages);
    }

    public function testUnknownFlagsAndOrphanWarehouseAreSkippedInsteadOfInvented(): void
    {
        $tables = $this->tables();
        $tables['SCenik'][0]['VyrobniCisla'] = null;
        $tables['SCenik'][] = array_merge($tables['SCenik'][0], [
            'Sklad' => 'NEEXISTUJE', 'PolozkaSkladu' => 'SYN-002', 'VyrobniCisla' => false,
        ]);

        $plan = StereoNxInventory::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertSame(0, $plan['counts']['stock_items_ready']);
        self::assertSame(2, $plan['counts']['stock_items_skipped']);
        self::assertSame([], $plan['records']['items']);
        self::assertSame([
            'stock_balance_skipped',
            'stock_vat_rate_skipped',
            'stock_item_flags_unknown',
            'stock_item_warehouse_missing',
            'car_details_unmapped',
        ], array_column($plan['warnings'], 'code'));
    }

    public function testBarcodeEqualToSkuIsNotManufacturedAsEan(): void
    {
        $tables = $this->tables();
        $tables['SCenik'][0]['CarovyKod'] = 'SYN-001';

        $plan = StereoNxInventory::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertNull($plan['records']['items'][0]['ean']);
        self::assertContains('stock_item_ean_implicit', array_column($plan['warnings'], 'code'));
    }

    public function testDuplicateSourceIdentityFailsClosed(): void
    {
        $tables = $this->tables();
        $tables['SCenik'][] = $tables['SCenik'][0];

        try {
            StereoNxInventory::fromTables($tables, ['ico' => '12345679'], 1);
            self::fail('Duplicitní karta měla převod zastavit.');
        } catch (StereoNxException $e) {
            self::assertSame('stock_item_duplicate', $e->errorCode);
        }
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function tables(): array
    {
        return [
            'SSklady' => [[
                'Sklad' => 'HLAVNI', 'Nazev' => 'Syntetický sklad',
            ]],
            'SParamSkl' => [[
                'Sklad' => '', 'CenyBezDPH' => true, 'MetodaSkladu' => 'B',
            ]],
            'SCenik' => [[
                'Sklad' => 'HLAVNI', 'PolozkaSkladu' => 'SYN-001', 'TypPolozky' => 'M',
                'CarovyKod' => '4006381333931', 'Nazev' => 'Syntetický materiál', 'Jednotka' => 'ks',
                'SazbaDPHn' => 'Z', 'SazbaDPHp' => 'Z', 'ProdejniCena' => 125.5,
                'Mnozstvi' => 5.0, 'MnozstviPocSt' => 4.0, 'HmotnostKg' => 0.75,
                'MinMnozstvi' => 2.5, 'VyrobniCisla' => true, 'Neaktivni' => false,
                'Poznamka' => 'Pouze syntetický test', 'IntraKod' => '12345678',
                'IntraVlHmMJ' => 0.75, 'IntraDoplMJ' => 'KGM', 'IntraKoefMJ' => 1.0,
            ]],
            'Kauta' => [[
                'Vozidlo' => 'SYN-CAR', 'Nazev' => 'Syntetické vozidlo', 'SPZ' => 'TEST-01',
                'VIN' => '', 'Palivo' => 'nafta', 'PocStavKm' => 5000, 'KonStavTach' => 5100,
                'Poznamka' => 'Pouze syntetický test', 'DatumVyrazeni' => '',
            ]],
            'Kcesty' => [],
        ];
    }
}
