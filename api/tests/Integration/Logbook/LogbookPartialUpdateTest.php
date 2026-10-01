<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Action\Logbook\CarsAction;
use MyInvoice\Action\Logbook\FuelCashDocumentsAction;
use MyInvoice\Action\Logbook\FuelingsAction;
use MyInvoice\Action\Logbook\FuelInvoicesAction;
use MyInvoice\Action\Logbook\TripCategoriesAction;
use MyInvoice\Action\Logbook\TripsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Repository\TripCategoryRepository;
use MyInvoice\Repository\TripRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Issue #113: PUT s částečným tělem nesmí vynulovat vynechané položky;
 * poslaný null / "" je naopak maže.
 */
#[Group('integration')]
final class LogbookPartialUpdateTest extends TestCase
{
    use LogbookFixtures;

    protected function setUp(): void
    {
        $this->bootLogbook();
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testCarPartialPutKeepsOmittedFields(): void
    {
        $repo = $this->container->get(CarRepository::class);
        $id = $repo->create($this->supplierA, [
            'registration' => '9PU 0001', 'name' => 'Služební', 'brand' => 'Testovací', 'model' => 'T1',
            'vin' => 'TESTVIN0000000001', 'fuel_type' => 'diesel', 'odometer_start' => 12000,
            'odometer_start_date' => '2099-01-01', 'is_default' => true, 'note' => 'poznámka',
        ], null);
        $repo->update($id, $this->supplierA, ['registration' => '9PU 0001', 'is_default' => true, 'is_archived' => true,
            'name' => 'Služební', 'brand' => 'Testovací', 'model' => 'T1', 'vin' => 'TESTVIN0000000001',
            'fuel_type' => 'diesel', 'odometer_start' => 12000, 'odometer_start_date' => '2099-01-01', 'note' => 'poznámka']);

        $res = $this->call(CarsAction::class, 'update', 'PUT', ['name' => 'Přejmenované'], ['id' => (string) $id]);
        self::assertSame(200, $res->getStatusCode());
        $car = $repo->find($id, $this->supplierA);
        self::assertSame('Přejmenované', $car['name']);
        self::assertSame('9PU 0001', $car['registration']);
        self::assertSame('Testovací', $car['brand']);
        self::assertSame('diesel', $car['fuel_type']);
        self::assertSame(12000, $car['odometer_start']);
        self::assertSame('2099-01-01', $car['odometer_start_date']);
        self::assertTrue($car['is_default']);
        self::assertTrue($car['is_archived']);
        self::assertSame('poznámka', $car['note']);

        $this->call(CarsAction::class, 'update', 'PUT', ['note' => null, 'is_archived' => false], ['id' => (string) $id]);
        $car = $repo->find($id, $this->supplierA);
        self::assertNull($car['note']);
        self::assertFalse($car['is_archived']);
        self::assertSame('Přejmenované', $car['name']);
    }

    public function testTripPartialPutKeepsOmittedFieldsAndRecomputesDistance(): void
    {
        $car = $this->car($this->supplierA, '9PU 0002');
        $category = $this->container->get(TripCategoryRepository::class)
            ->create($this->supplierA, ['code' => 'biz', 'label' => 'Služební']);
        $repo = $this->container->get(TripRepository::class);
        $id = $repo->create($this->supplierA, [
            'car_id' => $car, 'trip_date' => '2099-02-01', 'time_start' => '08:00', 'time_end' => '09:00',
            'odometer_start' => 1000, 'odometer_end' => 1050, 'distance_km' => 50, 'category_id' => $category,
            'purpose' => 'Schůzka', 'origin' => 'Praha', 'destination' => 'Brno', 'note' => 'n',
        ], null);

        $res = $this->call(TripsAction::class, 'update', 'PUT', ['purpose' => 'Jednání'], ['id' => (string) $id]);
        self::assertSame(200, $res->getStatusCode());
        $trip = $repo->find($id, $this->supplierA);
        self::assertSame('Jednání', $trip['purpose']);
        self::assertSame(1000, $trip['odometer_start']);
        self::assertSame(1050, $trip['odometer_end']);
        self::assertSame($category, $trip['category_id']);
        self::assertSame('Praha', $trip['origin']);
        self::assertSame('Brno', $trip['destination']);
        self::assertSame('08:00', $trip['time_start']);
        self::assertSame('n', $trip['note']);
        self::assertSame(50.0, $trip['distance_km']);

        $this->call(TripsAction::class, 'update', 'PUT', ['odometer_end' => 1080], ['id' => (string) $id]);
        self::assertSame(80.0, $repo->find($id, $this->supplierA)['distance_km'], 'Km se přepočtou z tachometru.');

        $this->call(TripsAction::class, 'update', 'PUT', ['category_id' => null, 'note' => ''], ['id' => (string) $id]);
        $trip = $repo->find($id, $this->supplierA);
        self::assertNull($trip['category_id']);
        self::assertNull($trip['note']);
        self::assertSame('Jednání', $trip['purpose']);
    }

    public function testFuelingPartialPutKeepsVatSplitVendorAndCar(): void
    {
        $car = $this->car($this->supplierA, '9PU 0003');
        $vendor = $this->fuelStationClient($this->supplierA, 'Testovací benzínka', '12345678');
        $repo = $this->container->get(FuelingRepository::class);
        $id = $repo->create($this->supplierA, [
            'car_id' => $car, 'fueled_date' => '2099-03-01', 'fuel_type' => 'diesel', 'quantity' => 40.0,
            'unit' => 'kWh', 'unit_price' => 30.0, 'amount_without_vat' => 1000.0, 'amount_vat' => 210.0,
            'amount_with_vat' => 1210.0, 'currency' => 'EUR', 'odometer' => 5000, 'station' => 'Stanice',
            'vendor_id' => $vendor, 'receipt_number' => 'R-1', 'note' => 'n',
        ], null);

        $res = $this->call(FuelingsAction::class, 'update', 'PUT', ['note' => 'nová'], ['id' => (string) $id]);
        self::assertSame(200, $res->getStatusCode());
        $f = $repo->find($id, $this->supplierA);
        self::assertSame('nová', $f['note']);
        self::assertSame($car, $f['car_id']);
        self::assertSame($vendor, $f['vendor_id']);
        self::assertSame(1000.0, $f['amount_without_vat']);
        self::assertSame(210.0, $f['amount_vat']);
        self::assertSame(1210.0, $f['amount_with_vat']);
        self::assertSame('EUR', $f['currency']);
        self::assertSame('kWh', $f['unit']);
        self::assertSame(40.0, $f['quantity']);
        self::assertSame(30.0, $f['unit_price']);
        self::assertSame(5000, $f['odometer']);
        self::assertSame('R-1', $f['receipt_number']);
        self::assertSame('Stanice', $f['station']);

        // Změna celkové částky bez rozpisu DPH: rozpis se přepočte poměrem, nezůstane nekonzistentní.
        $this->call(FuelingsAction::class, 'update', 'PUT', ['amount_with_vat' => 2420.0], ['id' => (string) $id]);
        $f = $repo->find($id, $this->supplierA);
        self::assertSame(2000.0, $f['amount_without_vat']);
        self::assertSame(420.0, $f['amount_vat']);

        $this->call(FuelingsAction::class, 'update', 'PUT', ['car_id' => null, 'vendor_id' => null, 'station' => null], ['id' => (string) $id]);
        $f = $repo->find($id, $this->supplierA);
        self::assertNull($f['car_id']);
        self::assertNull($f['vendor_id']);
        self::assertNull($f['station']);
        self::assertSame('R-1', $f['receipt_number']);

        $bad = $this->call(FuelingsAction::class, 'update', 'PUT', ['amount_with_vat' => null], ['id' => (string) $id]);
        self::assertSame(400, $bad->getStatusCode());
    }

    public function testTripCategoryPartialPutKeepsFlags(): void
    {
        $repo = $this->container->get(TripCategoryRepository::class);
        $id = $repo->create($this->supplierA, ['code' => 'priv', 'label' => 'Soukromá', 'is_private' => true, 'display_order' => 7]);
        $repo->update($id, $this->supplierA, ['code' => 'priv', 'label' => 'Soukromá', 'is_private' => true,
            'display_order' => 7, 'is_archived' => true]);

        $res = $this->call(TripCategoriesAction::class, 'update', 'PUT', ['label' => 'Soukromé jízdy'], ['id' => (string) $id]);
        self::assertSame(200, $res->getStatusCode());
        $c = $repo->find($id, $this->supplierA);
        self::assertSame('Soukromé jízdy', $c['label']);
        self::assertSame('priv', $c['code']);
        self::assertTrue($c['is_private']);
        self::assertTrue($c['is_archived']);
        self::assertSame(7, $c['display_order']);
    }

    public function testFuelInvoiceAssignRequiresCarIdKey(): void
    {
        $car = $this->car($this->supplierA, '9PU 0004');
        $res = $this->call(FuelInvoicesAction::class, 'assign', 'POST', [], ['id' => '999999999']);
        self::assertSame(400, $res->getStatusCode());
        self::assertSame('validation_failed', $this->json($res)['error']['code']);

        // Explicitní null je povolený pokyn „odebrat auto" - projde validací až k samotnému skenu.
        $null = $this->call(FuelInvoicesAction::class, 'assign', 'POST', ['car_id' => null], ['id' => '999999999']);
        self::assertNotSame('validation_failed', $this->json($null)['error']['code'] ?? '');
        self::assertGreaterThan(0, $car);
    }

    public function testFuelCashDocumentAssignRequiresCarIdKey(): void
    {
        $res = $this->call(FuelCashDocumentsAction::class, 'assign', 'POST', [], ['id' => '999999999']);
        self::assertSame(400, $res->getStatusCode());
        self::assertSame('validation_failed', $this->json($res)['error']['code']);

        $null = $this->call(FuelCashDocumentsAction::class, 'assign', 'POST', ['car_id' => null], ['id' => '999999999']);
        self::assertNotSame('validation_failed', $this->json($null)['error']['code'] ?? '');
    }

    public function testTripManualDistanceSurvivesPartialOdometerBodies(): void
    {
        $car = $this->car($this->supplierA, '9PU 0005');
        $repo = $this->container->get(TripRepository::class);
        $id = $repo->create($this->supplierA, [
            'car_id' => $car, 'trip_date' => '2099-04-01', 'distance_km' => 33,
        ], null);

        $res = $this->call(TripsAction::class, 'update', 'PUT', ['odometer_start' => 2000], ['id' => (string) $id]);
        self::assertSame(200, $res->getStatusCode());
        $trip = $repo->find($id, $this->supplierA);
        self::assertSame(2000, $trip['odometer_start']);
        self::assertSame(33.0, $trip['distance_km']);

        $id2 = $repo->create($this->supplierA, [
            'car_id' => $car, 'trip_date' => '2099-04-02', 'odometer_start' => 100, 'odometer_end' => 160, 'distance_km' => 60,
        ], null);
        $res = $this->call(TripsAction::class, 'update', 'PUT', ['odometer_end' => null], ['id' => (string) $id2]);
        self::assertSame(200, $res->getStatusCode());
        $trip = $repo->find($id2, $this->supplierA);
        self::assertNull($trip['odometer_end']);
        self::assertSame(60.0, $trip['distance_km']);
    }

    /**
     * @param class-string $class
     * @param array<string,mixed> $body
     * @param array<string,string> $args
     */
    private function call(string $class, string $method, string $verb, array $body, array $args): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($verb, '/api/logbook/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        return $this->container->get($class)->$method($request, new Response(), $args);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
