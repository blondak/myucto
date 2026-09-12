<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Action\Logbook\FuelingsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\FuelingRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Doplnění odhadu tachometru přes akci: náhled nic nezapíše, zápis doplní jen chybějící
 * stavy s příznakem odhadu, zadané hodnoty nemění, skutečný stav odhad nahradí.
 */
#[Group('integration')]
final class FuelingOdometerEstimateTest extends TestCase
{
    use LogbookFixtures;

    private int $carA = 0;
    private int $carEmpty = 0;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->carA = $this->car($this->supplierA, '1AB 2345');
        $this->carEmpty = $this->car($this->supplierA, '2AB 3456');
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testFillsOnlyMissingOdometersAndMarksThemAsEstimate(): void
    {
        $known1 = $this->fueling($this->carA, '2099-01-01', 10000, 40.0);
        $missing = $this->fueling($this->carA, '2099-01-15', null, 30.0);
        $known2 = $this->fueling($this->carA, '2099-02-10', 11000, 40.0);
        $emptyCar = $this->fueling($this->carEmpty, '2099-01-20', null, 35.0);
        $action = $this->container->get(FuelingsAction::class);

        $warnings = $this->call($action, 'warnings', 'GET', [], ['year' => '2099']);
        self::assertSame(1, $warnings['body']['totals']['estimable']);
        $byCar = array_column($warnings['body']['cars'], 'odometer_estimate', 'car_id');
        self::assertSame(['estimable' => 1, 'reason' => null], $byCar[$this->carA]);
        self::assertSame(['estimable' => 0, 'reason' => 'no_known_odometer'], $byCar[$this->carEmpty]);

        $dry = $this->call($action, 'estimateOdometers', 'POST', ['dry_run' => true]);
        self::assertSame(200, $dry['status']);
        self::assertSame(0, $dry['body']['filled']);
        self::assertSame(1, $dry['body']['totals']['estimable']);
        self::assertNull($this->odometer($missing)['odometer'], 'Náhled nic nezapíše.');

        $res = $this->call($action, 'estimateOdometers', 'POST', []);
        self::assertSame(200, $res['status']);
        self::assertSame(1, $res['body']['filled']);
        self::assertSame([$missing], $res['body']['filled_ids']);

        // 1 000 km dělených podle litrů: 30 / (30 + 40)
        self::assertSame(['odometer' => 10429, 'estimate' => 1], $this->odometer($missing));
        self::assertSame(['odometer' => 10000, 'estimate' => 0], $this->odometer($known1));
        self::assertSame(['odometer' => 11000, 'estimate' => 0], $this->odometer($known2));
        self::assertSame(['odometer' => null, 'estimate' => 0], $this->odometer($emptyCar));

        $log = $this->pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = 'fueling.odometer_estimated'");
        $log->execute([$this->supplierA]);
        self::assertSame(1, (int) $log->fetchColumn());

        $again = $this->call($action, 'estimateOdometers', 'POST', []);
        self::assertSame(0, $again['body']['filled'], 'Odhadnutý stav se podruhé nepřepisuje.');

        $repo = $this->container->get(FuelingRepository::class);
        self::assertTrue($repo->find($missing, $this->supplierA)['odometer_is_estimate']);

        // Zápis odhadu do tankování se zadaným stavem se odmítne i mimo akci.
        self::assertFalse($repo->setEstimatedOdometer($known1, $this->supplierA, 12345));
        self::assertSame(['odometer' => 10000, 'estimate' => 0], $this->odometer($known1));
    }

    public function testCarFilterAndForeignCarAreRespected(): void
    {
        $this->fueling($this->carA, '2099-01-01', 10000, 40.0);
        $missing = $this->fueling($this->carA, '2099-01-15', null, 30.0);
        $this->fueling($this->carA, '2099-02-10', 11000, 40.0);
        $action = $this->container->get(FuelingsAction::class);

        $foreign = $this->car($this->supplierB, '9ZZ 9999');
        $bad = $this->call($action, 'estimateOdometers', 'POST', ['car_id' => $foreign]);
        self::assertSame(400, $bad['status']);

        $other = $this->call($action, 'estimateOdometers', 'POST', ['car_id' => $this->carEmpty]);
        self::assertSame(0, $other['body']['filled']);
        self::assertNull($this->odometer($missing)['odometer']);

        $own = $this->call($action, 'estimateOdometers', 'POST', ['car_id' => $this->carA, 'year' => 2099]);
        self::assertSame(1, $own['body']['filled']);
    }

    public function testRealOdometerReplacesEstimate(): void
    {
        $this->fueling($this->carA, '2099-01-01', 10000, 40.0);
        $missing = $this->fueling($this->carA, '2099-01-15', null, 30.0);
        $this->fueling($this->carA, '2099-02-10', 11000, 40.0);
        $action = $this->container->get(FuelingsAction::class);
        $this->call($action, 'estimateOdometers', 'POST', []);
        $repo = $this->container->get(FuelingRepository::class);
        $body = ['fueled_date' => '2099-01-15', 'amount_with_vat' => 1000, 'car_id' => $this->carA, 'quantity' => 30];

        // Uložení formuláře s nezměněným odhadem příznak drží.
        $this->call($action, 'update', 'PUT', $body + ['odometer' => 10429], [], ['id' => $missing]);
        self::assertSame(['odometer' => 10429, 'estimate' => 1], $this->odometer($missing));

        // Skutečný stav z doplnění dokladu odhad nahradí.
        self::assertTrue($repo->fillMissing($missing, $this->supplierA, ['odometer' => 10450]));
        self::assertSame(['odometer' => 10450, 'estimate' => 0], $this->odometer($missing));

        // Ruční přepis na jiný stav je skutečný údaj.
        $this->pdo->prepare('UPDATE fuelings SET odometer_is_estimate = 1 WHERE id = ?')->execute([$missing]);
        $this->call($action, 'update', 'PUT', $body + ['odometer' => 10460], [], ['id' => $missing]);
        self::assertSame(['odometer' => 10460, 'estimate' => 0], $this->odometer($missing));

        // Zadaný (ne odhadnutý) stav doplnění z dokladu nepřepíše.
        $repo->fillMissing($missing, $this->supplierA, ['odometer' => 99999]);
        self::assertSame(10460, $this->odometer($missing)['odometer']);
    }

    private function fueling(int $carId, string $date, ?int $odometer, ?float $quantity): int
    {
        $this->pdo->prepare(
            "INSERT INTO fuelings (supplier_id, car_id, fueled_date, quantity, unit, amount_with_vat, odometer, source)
             VALUES (?, ?, ?, ?, 'l', 1000, ?, 'manual')"
        )->execute([$this->supplierA, $carId, $date, $quantity, $odometer]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{odometer:?int, estimate:int} */
    private function odometer(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT odometer, odometer_is_estimate FROM fuelings WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ['odometer' => $r['odometer'] !== null ? (int) $r['odometer'] : null, 'estimate' => (int) $r['odometer_is_estimate']];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $query
     * @param array<string,mixed> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $httpMethod, array $body, array $query = [], array $args = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query)
            ->withParsedBody($body);

        /** @var ResponseInterface $response */
        $response = $args === []
            ? $action->{$method}($request, new Psr7Response())
            : $action->{$method}($request, new Psr7Response(), $args);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
