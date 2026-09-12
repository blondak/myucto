<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Eshop\ProductEditorService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class StockItemIntrastatTest extends StockTestCase
{
    private StockItemAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(StockItemAction::class);
    }

    public function testCreateAndPartialUpdatePersistIntrastatFieldsAndIncrementRowVersion(): void
    {
        $supplierId = $this->createSupplier();
        $created = $this->call('create', $supplierId, [
            'sku' => 'INTRASTAT-1',
            'name' => 'Syntetické zboží',
            'intrastat_cn8_code' => '84713000',
            'intrastat_country_of_origin' => 'de',
            'intrastat_net_mass_kg' => '1.275',
            'intrastat_supplementary_unit' => 'pce',
            'intrastat_supplementary_unit_coefficient' => '2.500000',
        ]);

        self::assertSame(201, $created['status']);
        self::assertSame('84713000', $created['body']['intrastat_cn8_code']);
        self::assertSame('DE', $created['body']['intrastat_country_of_origin']);
        self::assertSame('1.275', $created['body']['intrastat_net_mass_kg']);
        self::assertSame('PCE', $created['body']['intrastat_supplementary_unit']);
        self::assertSame('2.500000', $created['body']['intrastat_supplementary_unit_coefficient']);

        $updated = $this->call('update', $supplierId, [
            'intrastat_net_mass_kg' => '1.500',
        ], (int) $created['body']['id']);

        self::assertSame(200, $updated['status']);
        self::assertSame('84713000', $updated['body']['intrastat_cn8_code']);
        self::assertSame('DE', $updated['body']['intrastat_country_of_origin']);
        self::assertSame('1.500', $updated['body']['intrastat_net_mass_kg']);
        self::assertSame((int) $created['body']['row_version'] + 1, $updated['body']['row_version']);

        $legacyPayload = $updated['body'];
        unset(
            $legacyPayload['intrastat_cn8_code'],
            $legacyPayload['intrastat_country_of_origin'],
            $legacyPayload['intrastat_net_mass_kg'],
            $legacyPayload['intrastat_supplementary_unit'],
            $legacyPayload['intrastat_supplementary_unit_coefficient'],
        );
        self::assertTrue($this->itemsRepo->updateVersioned(
            $supplierId,
            (int) $updated['body']['id'],
            (int) $updated['body']['row_version'],
            $legacyPayload,
        ));
        $afterLegacyUpdate = $this->itemsRepo->find($supplierId, (int) $updated['body']['id']);
        self::assertSame('84713000', $afterLegacyUpdate['intrastat_cn8_code']);
        self::assertSame('DE', $afterLegacyUpdate['intrastat_country_of_origin']);
        self::assertSame('1.500', $afterLegacyUpdate['intrastat_net_mass_kg']);

        $editor = $this->container->get(ProductEditorService::class);
        $savedByEditor = $editor->save($supplierId, (int) $afterLegacyUpdate['id'], [
            'row_version' => $afterLegacyUpdate['row_version'],
            'item' => [
                'sku' => $afterLegacyUpdate['sku'],
                'name' => $afterLegacyUpdate['name'],
                'item_type' => $afterLegacyUpdate['item_type'],
                'unit' => $afterLegacyUpdate['unit'],
                'tracking_mode' => $afterLegacyUpdate['tracking_mode'],
                'ean' => $afterLegacyUpdate['ean'],
                'vat_rate_id' => $afterLegacyUpdate['vat_rate_id'],
                'sale_price_without_vat' => $afterLegacyUpdate['sale_price_without_vat'],
                'min_qty' => $afterLegacyUpdate['min_qty'],
                'intrastat_cn8_code' => '84713000',
                'intrastat_country_of_origin' => 'CZ',
                'intrastat_net_mass_kg' => 2.5,
                'intrastat_supplementary_unit' => null,
                'intrastat_supplementary_unit_coefficient' => null,
                'is_active' => $afterLegacyUpdate['is_active'],
                'note' => $afterLegacyUpdate['note'],
            ],
            'product' => [
                'manufacturer_id' => null,
                'warranty_months' => null,
                'delivery_days' => null,
                'export_eshop' => false,
                'is_stocked' => true,
                'weight_g' => null,
                'pricing_base' => 'weighted_avg',
                'i18n' => [],
                'categories' => [],
                'tag_ids' => [],
                'attributes' => [],
                'fees' => [],
            ],
            'prices' => [],
            'promo_prices' => [],
            'vendors' => [],
        ]);
        self::assertSame('CZ', $savedByEditor['intrastat_country_of_origin']);
        self::assertSame('2.500', $savedByEditor['intrastat_net_mass_kg']);
        self::assertNull($savedByEditor['intrastat_supplementary_unit']);
    }

    public function testValidationRejectsMalformedAndIncompleteIntrastatValues(): void
    {
        $supplierId = $this->createSupplier();
        foreach ([
            ['intrastat_cn8_code' => '8471300'],
            ['intrastat_cn8_code' => '8471AB00'],
            ['intrastat_country_of_origin' => 'ZZ'],
            ['intrastat_net_mass_kg' => '1.2345'],
            ['intrastat_net_mass_kg' => '0'],
            ['intrastat_supplementary_unit' => 'PC'],
            ['intrastat_supplementary_unit' => 'ABC'],
            ['intrastat_supplementary_unit_coefficient' => '1.000000'],
            [
                'intrastat_supplementary_unit' => 'PCE',
                'intrastat_supplementary_unit_coefficient' => '1.0000001',
            ],
        ] as $index => $fields) {
            $result = $this->call('create', $supplierId, [
                'sku' => 'INTRASTAT-BAD-' . $index,
                'name' => 'Neplatné syntetické zboží',
                ...$fields,
            ]);
            self::assertSame(400, $result['status'], 'Varianta ' . $index);
            self::assertSame('validation_failed', $result['body']['error']['code'], 'Varianta ' . $index);
        }
    }

    public function testZzzSupplementaryUnitIsStoredWithoutCoefficient(): void
    {
        $supplierId = $this->createSupplier();
        $created = $this->call('create', $supplierId, [
            'sku' => 'INTRASTAT-ZZZ',
            'name' => 'Syntetické zboží bez určené jednotky',
            'intrastat_country_of_origin' => 'QU',
            'intrastat_supplementary_unit' => 'zzz',
            'intrastat_supplementary_unit_coefficient' => null,
        ]);

        self::assertSame(201, $created['status']);
        self::assertSame('QU', $created['body']['intrastat_country_of_origin']);
        self::assertSame('ZZZ', $created['body']['intrastat_supplementary_unit']);
        self::assertNull($created['body']['intrastat_supplementary_unit_coefficient']);
    }

    public function testFloatCoefficientUsesFixedDecimalNotationWithoutRoundingExtraPrecision(): void
    {
        $supplierId = $this->createSupplier();
        $valid = $this->call('create', $supplierId, [
            'sku' => 'INTRASTAT-FLOAT-OK',
            'name' => 'Syntetické zboží s malým koeficientem',
            'intrastat_supplementary_unit' => 'MTQ',
            'intrastat_supplementary_unit_coefficient' => 0.000001,
        ]);

        self::assertSame(201, $valid['status']);
        self::assertSame('0.000001', $valid['body']['intrastat_supplementary_unit_coefficient']);

        $tooPrecise = $this->call('create', $supplierId, [
            'sku' => 'INTRASTAT-FLOAT-BAD',
            'name' => 'Syntetické zboží s příliš přesným koeficientem',
            'intrastat_supplementary_unit' => 'MTQ',
            'intrastat_supplementary_unit_coefficient' => 0.0000001,
        ]);

        self::assertSame(400, $tooPrecise['status']);
        self::assertSame('validation_failed', $tooPrecise['body']['error']['code']);
    }

    public function testRepositoryDoesNotExposeIntrastatFieldsAcrossTenants(): void
    {
        $firstSupplierId = $this->createSupplier();
        $secondSupplierId = $this->createSupplier();
        $created = $this->call('create', $firstSupplierId, [
            'sku' => 'INTRASTAT-TENANT',
            'name' => 'Tenantové syntetické zboží',
            'intrastat_cn8_code' => '84713000',
            'intrastat_country_of_origin' => 'CZ',
            'intrastat_net_mass_kg' => '0.001',
        ]);

        self::assertSame(201, $created['status']);
        self::assertNull($this->itemsRepo->find($secondSupplierId, (int) $created['body']['id']));
        self::assertNull($this->itemsRepo->findBySku($secondSupplierId, 'INTRASTAT-TENANT'));
    }

    /** @param array<string,mixed> $body @return array{status:int,body:array<string,mixed>} */
    private function call(string $method, int $supplierId, array $body, ?int $itemId = null): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method === 'create' ? 'POST' : 'PUT', '/api/stock/items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        $response = $method === 'create'
            ? $this->action->create($request, new Response())
            : $this->action->update($request, new Response(), ['id' => (string) $itemId]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }
}
