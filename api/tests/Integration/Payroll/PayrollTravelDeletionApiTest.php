<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTravelAction;
use MyInvoice\Repository\Payroll\PayrollBusinessTripDeletionRepository;
use MyInvoice\Tests\Support\PayrollDeletionFixturesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Zrušení a smazání pracovní cesty.
 *
 * Vodicí princip: blokovat smí VÝHRADNĚ důkaz pohybu. U cesty je jím schválený
 * výpočet náhrad a vyúčtování, které z ní udělá mzdový vstup. Rozpracovaný
 * koncept žádný pohyb nemá, takže se maže bez cavyků.
 */
#[Group('integration')]
final class PayrollTravelDeletionApiTest extends TestCase
{
    use PayrollDeletionFixturesTrait;

    private PayrollTravelAction $action;
    private PayrollBusinessTripDeletionRepository $deletion;

    protected function setUp(): void
    {
        $container = $this->bootPayrollFixtures();
        $action = $container->get(PayrollTravelAction::class);
        self::assertInstanceOf(PayrollTravelAction::class, $action);
        $this->action = $action;
        $deletion = $container->get(PayrollBusinessTripDeletionRepository::class);
        self::assertInstanceOf(PayrollBusinessTripDeletionRepository::class, $deletion);
        $this->deletion = $deletion;
    }

    protected function tearDown(): void
    {
        $this->shutdownPayrollFixtures();
    }

    public function testDraftTripIsDeletableAndClearsItsScaffold(): void
    {
        $tripId = $this->insertTrip('draft');
        $this->insertTripItem($tripId);
        $this->insertFreeMeal($tripId);

        $listed = $this->listedTrip($tripId);
        self::assertTrue($listed['can_delete']);
        self::assertNull($listed['delete_blocker']);

        $detail = $this->detailTrip($tripId);
        self::assertTrue($detail['can_delete']);
        self::assertNull($detail['delete_blocker']);

        $response = $this->deleteTrip($tripId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cascade = $this->json($response)['cascade'];
        self::assertIsArray($cascade);
        self::assertSame(1, $cascade['items']);
        self::assertSame(1, $cascade['free_meals']);

        self::assertSame(0, $this->rowCount('payroll_business_trips', 'id', $tripId));
        self::assertSame(0, $this->rowCount('payroll_business_trip_items', 'trip_id', $tripId));
        self::assertSame(0, $this->rowCount('payroll_business_trip_free_meals', 'trip_id', $tripId));
    }

    public function testApprovedTripIsBlockedAndTheMessageExplainsWhatToDo(): void
    {
        $tripId = $this->insertTrip('approved');

        $decision = $this->deletion->canDelete($this->supplierId, $tripId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_business_trip_approved', $decision->blockerCode);
        self::assertStringContainsString('Zrušit cestu', (string) $decision->blockerMessage);

        $listed = $this->listedTrip($tripId);
        self::assertFalse($listed['can_delete']);
        $blocker = $listed['delete_blocker'];
        self::assertIsArray($blocker);
        self::assertSame('payroll_business_trip_approved', $blocker['code']);

        $response = $this->deleteTrip($tripId);
        self::assertSame(409, $response->getStatusCode());
        $error = $this->errorOf($response);
        self::assertSame('payroll_business_trip_approved', $error['code']);
        $this->assertActionableMessage((string) $error['message']);
        self::assertSame(1, $this->rowCount('payroll_business_trips', 'id', $tripId));
    }

    public function testSettledTripCanBeNeitherDeletedNorCancelled(): void
    {
        $tripId = $this->insertTrip('settled');

        $response = $this->deleteTrip($tripId);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payroll_business_trip_settled', $this->errorOf($response)['code']);

        $cancel = $this->cancelTrip($tripId);
        self::assertSame(409, $cancel->getStatusCode());
        $error = $this->errorOf($cancel);
        self::assertSame('payroll_business_trip_materialized', $error['code']);
        $this->assertActionableMessage((string) $error['message']);
        self::assertSame(
            'settled',
            $this->fetchRow(
                'SELECT status FROM payroll_business_trips WHERE supplier_id = ? AND id = ?',
                $this->supplierId,
                $tripId,
            )['status'],
        );
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletes(): void
    {
        $tripId = $this->insertTrip('draft');

        self::assertNull($this->deletion->canDelete($this->otherSupplierId, $tripId));

        $response = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/travel/trips/{$tripId}",
                [],
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $tripId],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_business_trips', 'id', $tripId));
    }

    public function testConcurrentApprovalFailsOnRecheckNotOnForeignKeyError(): void
    {
        $tripId = $this->insertTrip('draft');
        $decision = $this->deletion->canDelete($this->supplierId, $tripId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        // Mezi vykreslením seznamu a kliknutím cestu někdo schválil.
        $this->promoteTrip($tripId, 'approved');

        $response = $this->deleteTrip($tripId);
        self::assertSame(409, $response->getStatusCode());
        $error = $this->errorOf($response);
        self::assertSame('payroll_business_trip_approved', $error['code']);
        $this->assertActionableMessage((string) $error['message']);
        self::assertSame(1, $this->rowCount('payroll_business_trips', 'id', $tripId));
    }

    public function testDeletionLeavesAnAuditTrail(): void
    {
        $tripId = $this->insertTrip('draft');

        self::assertSame(200, $this->deleteTrip($tripId)->getStatusCode());

        $payload = $this->auditPayloadOf('payroll.travel.deleted', $tripId);
        self::assertSame($this->employmentId, $payload['employment_id']);
        self::assertSame('draft', $payload['status']);
        self::assertArrayHasKey('cascade', $payload);
    }

    public function testRepeatedDeleteIsIdempotent(): void
    {
        $tripId = $this->insertTrip('draft');

        self::assertSame(200, $this->deleteTrip($tripId)->getStatusCode());
        $second = $this->deleteTrip($tripId);

        self::assertSame(404, $second->getStatusCode());
        self::assertSame(0, $this->rowCount('payroll_business_trips', 'id', $tripId));
        self::assertSame(1, $this->auditCount('payroll.travel.deleted', $tripId));
    }

    public function testCancelKeepsTheTraceAndIsIdempotent(): void
    {
        $tripId = $this->insertTrip('approved');

        $first = $this->cancelTrip($tripId);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertTrue($this->json($first)['cancelled']);
        self::assertSame(
            'cancelled',
            $this->fetchRow(
                'SELECT status FROM payroll_business_trips WHERE supplier_id = ? AND id = ?',
                $this->supplierId,
                $tripId,
            )['status'],
        );

        $second = $this->cancelTrip($tripId);
        self::assertSame(200, $second->getStatusCode(), (string) $second->getBody());
        self::assertFalse($this->json($second)['cancelled']);
        self::assertSame(1, $this->auditCount('payroll.travel.cancelled', $tripId));

        // Zrušená cesta zůstává v evidenci jako stopa — smazat ji už nejde.
        $delete = $this->deleteTrip($tripId);
        self::assertSame(409, $delete->getStatusCode());
        self::assertSame('payroll_business_trip_cancelled', $this->errorOf($delete)['code']);
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    private function insertTrip(string $status): int
    {
        $settled = $status === 'draft' || $status === 'cancelled';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trips
                (supplier_id, employee_id, employment_id, country_code,
                 departure_at, arrival_at, origin_place, destination_place,
                 purpose, transport_mode, advance_minor, settlement_period_start,
                 status, entitlement_total_minor, exempt_total_minor,
                 taxable_total_minor, ruleset_id, calculation_json, calculation_hash)
             VALUES (?, ?, ?, "CZ", "2026-02-02 08:00:00", "2026-02-02 18:00:00",
                     "Praha", "Brno", "Jednání", "public_transport", 0, "2026-02-01",
                     ?, ?, ?, ?, ?, ?, ' . ($settled ? 'NULL' : 'UNHEX(SHA2("trip", 256))') . ')'
        );
        $stmt->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $status,
            $settled ? null : 15000,
            $settled ? null : 15000,
            $settled ? null : 0,
            $settled ? null : 'travel/2026',
            $settled ? null : '{"blockers":[]}',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function promoteTrip(int $tripId, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_business_trips
                SET status = ?, entitlement_total_minor = 15000, exempt_total_minor = 15000,
                    taxable_total_minor = 0, ruleset_id = "travel/2026",
                    calculation_json = \'{"blockers":[]}\',
                    calculation_hash = UNHEX(SHA2("trip", 256))
              WHERE supplier_id = ? AND id = ?'
        )->execute([$status, $this->supplierId, $tripId]);
    }

    private function insertTripItem(int $tripId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trip_items
                (supplier_id, trip_id, item_kind, spent_on, description, amount_minor,
                 is_documented, sort_order)
             VALUES (?, ?, "transport", "2026-02-02", "Jízdenka", 25000, 1, 1)'
        )->execute([$this->supplierId, $tripId]);
    }

    private function insertFreeMeal(int $tripId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trip_free_meals
                (supplier_id, trip_id, meal_date, meal_count)
             VALUES (?, ?, "2026-02-02", 1)'
        )->execute([$this->supplierId, $tripId]);
    }

    private function deleteTrip(int $tripId): ResponseInterface
    {
        return $this->action->delete(
            $this->request('DELETE', "/api/payroll/travel/trips/{$tripId}", []),
            new Response(),
            ['id' => (string) $tripId],
        );
    }

    private function cancelTrip(int $tripId): ResponseInterface
    {
        return $this->action->cancel(
            $this->request(
                'POST',
                "/api/payroll/travel/trips/{$tripId}/cancel",
                [],
                role: 'admin',
            ),
            new Response(),
            ['id' => (string) $tripId],
        );
    }

    /** @return array<string,mixed> */
    private function listedTrip(int $tripId): array
    {
        $response = $this->action->list(
            $this->request('GET', '/api/payroll/travel/trips'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $trips = $this->json($response)['trips'];
        self::assertIsArray($trips);
        foreach ($trips as $trip) {
            if (is_array($trip) && (int) $trip['id'] === $tripId) {
                return $trip;
            }
        }
        self::fail('Cesta v seznamu chybí.');
    }

    /** @return array<string,mixed> */
    private function detailTrip(int $tripId): array
    {
        $response = $this->action->recalculate(
            $this->request('GET', "/api/payroll/travel/trips/{$tripId}/calculation"),
            new Response(),
            ['id' => (string) $tripId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $trip = $this->json($response)['trip'];
        self::assertIsArray($trip);

        return $trip;
    }
}
