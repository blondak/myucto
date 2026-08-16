<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Repository\Payroll\PayrollAverageEarningDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveEntitlementDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveLedgerDeletionRepository;
use MyInvoice\Tests\Support\PayrollDeletionFixturesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Mazání průměrného výdělku, zápisu v knize dovolené a nároku na dovolenou.
 *
 * Vodicí princip: blokovat smí VÝHRADNĚ důkaz pohybu — schválený výpočet nebo
 * navázaná náhrada. Špatně zadaný zápis, ze kterého nic nevzešlo, se maže.
 */
#[Group('integration')]
final class PayrollAbsenceDeletionApiTest extends TestCase
{
    use PayrollDeletionFixturesTrait;

    private PayrollAbsenceAction $action;
    private PayrollAverageEarningDeletionRepository $averageDeletion;
    private PayrollLeaveLedgerDeletionRepository $ledgerDeletion;
    private PayrollLeaveEntitlementDeletionRepository $entitlementDeletion;

    protected function setUp(): void
    {
        $container = $this->bootPayrollFixtures();
        foreach ([
            'action' => PayrollAbsenceAction::class,
            'averageDeletion' => PayrollAverageEarningDeletionRepository::class,
            'ledgerDeletion' => PayrollLeaveLedgerDeletionRepository::class,
            'entitlementDeletion' => PayrollLeaveEntitlementDeletionRepository::class,
        ] as $property => $class) {
            $service = $container->get($class);
            self::assertInstanceOf($class, $service);
            $this->{$property} = $service;
        }
    }

    protected function tearDown(): void
    {
        $this->shutdownPayrollFixtures();
    }

    // ── Průměrný výdělek ─────────────────────────────────────────────────────

    public function testUnapprovedAverageIsDeletable(): void
    {
        $snapshotId = $this->insertAverage(2026, 1, 'manual_review');

        $listed = $this->listedAverage($snapshotId);
        self::assertTrue($listed['can_delete']);
        self::assertNull($listed['delete_blocker']);

        $response = $this->deleteAverage($snapshotId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('payroll_average_earning_snapshots', 'id', $snapshotId));
    }

    public function testApprovedAverageIsBlocked(): void
    {
        $snapshotId = $this->insertAverage(2026, 2, 'approved');

        $decision = $this->averageDeletion->canDelete($this->supplierId, $snapshotId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_average_approved', $decision->blockerCode);
        self::assertStringContainsString('novou revizi', (string) $decision->blockerMessage);

        $response = $this->deleteAverage($snapshotId);
        self::assertSame(409, $response->getStatusCode());
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
        self::assertSame(1, $this->rowCount('payroll_average_earning_snapshots', 'id', $snapshotId));
    }

    public function testAverageUsedByAbsenceIsBlocked(): void
    {
        $snapshotId = $this->insertAverage(2026, 3, 'manual_review');
        $this->insertAbsenceUsingAverage($snapshotId);

        $decision = $this->averageDeletion->canDelete($this->supplierId, $snapshotId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_average_used_by_absence', $decision->blockerCode);
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletesAverage(): void
    {
        $snapshotId = $this->insertAverage(2026, 4, 'manual_review');

        self::assertNull($this->averageDeletion->canDelete($this->otherSupplierId, $snapshotId));

        $response = $this->action->deleteAverage(
            $this->request(
                'DELETE',
                "/api/payroll/time/averages/{$snapshotId}",
                [],
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $snapshotId],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_average_earning_snapshots', 'id', $snapshotId));
    }

    public function testAverageConcurrentApprovalFailsOnRecheck(): void
    {
        $snapshotId = $this->insertAverage(2027, 1, 'manual_review');
        $decision = $this->averageDeletion->canDelete($this->supplierId, $snapshotId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        $this->db->pdo()->prepare(
            "UPDATE payroll_average_earning_snapshots
                SET status = 'approved'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $snapshotId]);

        $response = $this->deleteAverage($snapshotId);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payroll_average_approved', $this->errorOf($response)['code']);
        self::assertSame(1, $this->rowCount('payroll_average_earning_snapshots', 'id', $snapshotId));
    }

    public function testAverageDeletionIsAuditedAndIdempotent(): void
    {
        $snapshotId = $this->insertAverage(2027, 2, 'manual_review');

        self::assertSame(200, $this->deleteAverage($snapshotId)->getStatusCode());
        $payload = $this->auditPayloadOf('payroll.average_earning.deleted', $snapshotId);
        self::assertSame(2027, $payload['applicable_year']);
        self::assertSame($this->employmentId, $payload['employment_id']);

        $second = $this->deleteAverage($snapshotId);
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(1, $this->auditCount('payroll.average_earning.deleted', $snapshotId));
    }

    // ── Kniha dovolené ───────────────────────────────────────────────────────

    public function testManualLeaveEntryIsDeletable(): void
    {
        $entryId = $this->insertLedgerEntry('adjustment', 480, 2026);

        $listed = $this->listedLeaveEntry($entryId);
        self::assertTrue($listed['can_delete']);
        self::assertNull($listed['delete_blocker']);

        $response = $this->deleteLeaveEntry($entryId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('payroll_leave_ledger', 'id', $entryId));
    }

    public function testTakenLeaveEntryIsBlockedBecauseItCameFromAnAbsence(): void
    {
        $entryId = $this->insertLedgerEntry('taken', -480, 2026);

        $decision = $this->ledgerDeletion->canDelete($this->supplierId, $entryId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_leave_entry_not_manual', $decision->blockerCode);
        self::assertStringContainsString('absenc', (string) $decision->blockerMessage);

        $response = $this->deleteLeaveEntry($entryId);
        self::assertSame(409, $response->getStatusCode());
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
        self::assertSame(1, $this->rowCount('payroll_leave_ledger', 'id', $entryId));
    }

    public function testLeaveEntryInApprovedRunYearIsBlocked(): void
    {
        $entryId = $this->insertLedgerEntry('adjustment', 480, 2026);
        $this->approveRunForEmployment('2026-04-01', 'leave-approved-run');

        $decision = $this->ledgerDeletion->canDelete($this->supplierId, $entryId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_leave_entry_in_approved_run', $decision->blockerCode);

        $response = $this->deleteLeaveEntry($entryId);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_leave_ledger', 'id', $entryId));
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletesLeaveEntry(): void
    {
        $entryId = $this->insertLedgerEntry('adjustment', 480, 2026);

        self::assertNull($this->ledgerDeletion->canDelete($this->otherSupplierId, $entryId));

        $response = $this->action->deleteLeaveEntry(
            $this->request(
                'DELETE',
                "/api/payroll/time/leave-ledger/{$entryId}",
                [],
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $entryId],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_leave_ledger', 'id', $entryId));
    }

    public function testLeaveEntryConcurrentReversalFailsOnRecheck(): void
    {
        $entryId = $this->insertLedgerEntry('adjustment', 480, 2026);
        $decision = $this->ledgerDeletion->canDelete($this->supplierId, $entryId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        $this->insertLedgerEntry('reversal', -480, 2026, reversalOfId: $entryId);

        $response = $this->deleteLeaveEntry($entryId);
        self::assertSame(409, $response->getStatusCode());
        $error = $this->errorOf($response);
        self::assertSame('payroll_leave_entry_reversed', $error['code']);
        $this->assertActionableMessage((string) $error['message']);
        self::assertSame(1, $this->rowCount('payroll_leave_ledger', 'id', $entryId));
    }

    public function testLeaveEntryDeletionIsAuditedAndIdempotent(): void
    {
        $entryId = $this->insertLedgerEntry('payout', -480, 2026);

        self::assertSame(200, $this->deleteLeaveEntry($entryId)->getStatusCode());
        $payload = $this->auditPayloadOf('payroll.leave_ledger.deleted', $entryId);
        self::assertSame('payout', $payload['entry_type']);
        self::assertSame(-480, $payload['minutes_delta']);

        $second = $this->deleteLeaveEntry($entryId);
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(1, $this->auditCount('payroll.leave_ledger.deleted', $entryId));
    }

    // ── Nárok na dovolenou ───────────────────────────────────────────────────

    public function testLatestEntitlementRevisionIsDeletableTogetherWithItsLedgerEntries(): void
    {
        $first = $this->insertEntitlement(2026, 1);
        $second = $this->insertEntitlement(2026, 2, previousLedgerEntryId: $first['ledger_id']);

        $listed = $this->listedEntitlement($second['snapshot_id']);
        self::assertTrue($listed['can_delete']);
        self::assertNull($listed['delete_blocker']);

        $balanceBefore = $this->leaveBalance(2026);
        $response = $this->deleteEntitlement($second['snapshot_id']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cascade = $this->json($response)['cascade'];
        self::assertIsArray($cascade);
        // Zmizí vlastní nárok revize i reverze, kterou vystavila proti té předchozí.
        self::assertSame(2, $cascade['ledger']);

        self::assertSame(
            0,
            $this->rowCount('payroll_leave_entitlement_snapshots', 'id', $second['snapshot_id']),
        );
        self::assertSame(0, $this->rowCount('payroll_leave_ledger', 'id', $second['ledger_id']));
        self::assertSame(0, $this->rowCount('payroll_leave_ledger', 'reversal_of_id', $first['ledger_id']));
        // Saldo se vrátilo na stav před druhou revizí — nárok první revize je zpět.
        self::assertSame(9600, $this->leaveBalance(2026));
        self::assertSame(9600, $balanceBefore);
    }

    public function testOlderEntitlementRevisionIsBlocked(): void
    {
        $first = $this->insertEntitlement(2026, 1);
        $this->insertEntitlement(2026, 2, previousLedgerEntryId: $first['ledger_id']);

        $decision = $this->entitlementDeletion->canDelete($this->supplierId, $first['snapshot_id']);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_leave_entitlement_superseded', $decision->blockerCode);
        self::assertStringContainsString('poslední revize', (string) $decision->blockerMessage);
    }

    public function testEntitlementInApprovedRunYearIsBlocked(): void
    {
        $entitlement = $this->insertEntitlement(2026, 1);
        $this->approveRunForEmployment('2026-06-01', 'entitlement-approved-run');

        $decision = $this->entitlementDeletion->canDelete(
            $this->supplierId,
            $entitlement['snapshot_id'],
        );
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_leave_entitlement_in_approved_run', $decision->blockerCode);

        $response = $this->deleteEntitlement($entitlement['snapshot_id']);
        self::assertSame(409, $response->getStatusCode());
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
        self::assertSame(
            1,
            $this->rowCount('payroll_leave_entitlement_snapshots', 'id', $entitlement['snapshot_id']),
        );
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletesEntitlement(): void
    {
        $entitlement = $this->insertEntitlement(2026, 1);

        self::assertNull(
            $this->entitlementDeletion->canDelete($this->otherSupplierId, $entitlement['snapshot_id']),
        );

        $response = $this->action->deleteEntitlement(
            $this->request(
                'DELETE',
                "/api/payroll/time/leave-entitlements/{$entitlement['snapshot_id']}",
                [],
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $entitlement['snapshot_id']],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            1,
            $this->rowCount('payroll_leave_entitlement_snapshots', 'id', $entitlement['snapshot_id']),
        );
    }

    public function testEntitlementConcurrentRevisionFailsOnRecheck(): void
    {
        $first = $this->insertEntitlement(2026, 1);
        $decision = $this->entitlementDeletion->canDelete($this->supplierId, $first['snapshot_id']);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        $this->insertEntitlement(2026, 2, previousLedgerEntryId: $first['ledger_id']);

        $response = $this->deleteEntitlement($first['snapshot_id']);
        self::assertSame(409, $response->getStatusCode());
        $error = $this->errorOf($response);
        self::assertSame('payroll_leave_entitlement_superseded', $error['code']);
        $this->assertActionableMessage((string) $error['message']);
        self::assertSame(
            1,
            $this->rowCount('payroll_leave_entitlement_snapshots', 'id', $first['snapshot_id']),
        );
    }

    public function testEntitlementDeletionIsAuditedAndIdempotent(): void
    {
        $entitlement = $this->insertEntitlement(2028, 1);

        self::assertSame(200, $this->deleteEntitlement($entitlement['snapshot_id'])->getStatusCode());
        $payload = $this->auditPayloadOf(
            'payroll.leave_entitlement.deleted',
            $entitlement['snapshot_id'],
        );
        self::assertSame(2028, $payload['leave_year']);
        self::assertSame(1, $payload['revision_no']);

        $second = $this->deleteEntitlement($entitlement['snapshot_id']);
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(
            1,
            $this->auditCount('payroll.leave_entitlement.deleted', $entitlement['snapshot_id']),
        );
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    private function insertAverage(int $year, int $quarter, string $status): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor, worked_minutes,
                 worked_days, average_hourly_minor, support_status, status, ruleset_id,
                 ruleset_hash, input_hash, input_trace, created_by)
             VALUES (?, ?, ?, ?, 1, 'actual', ?, ?, 12000000, 0, 10080, 22, 20000,
                     'manual_review', ?, 'averages/2026', ?, UNHEX(SHA2(?, 256)), '{}', ?)"
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $year,
            $quarter,
            sprintf('%04d-01-01', $year - 1),
            sprintf('%04d-03-31', $year - 1),
            $status,
            str_repeat('a', 64),
            "average-{$year}-{$quarter}",
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertAbsenceUsingAverage(int $snapshotId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 compensation_policy, average_snapshot_id, status)
             VALUES (?, ?, 'dpn', '2026-08-01', '2026-08-05', 'dpn', ?, 'approved')"
        )->execute([$this->supplierId, $this->employmentId, $snapshotId]);
    }

    private function insertLedgerEntry(
        string $entryType,
        int $minutesDelta,
        int $year,
        ?int $reversalOfId = null,
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_leave_ledger
                (supplier_id, employment_id, leave_year, effective_date, entry_type,
                 minutes_delta, reversal_of_id, reason, support_status, source_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "manual_review", UNHEX(SHA2(?, 256)), ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $year,
            sprintf('%04d-01-01', $year),
            $entryType,
            $minutesDelta,
            $reversalOfId,
            "Testovací zápis {$entryType}",
            uniqid("leave-{$entryType}-", true),
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{snapshot_id:int,ledger_id:int} */
    private function insertEntitlement(
        int $year,
        int $revision,
        ?int $previousLedgerEntryId = null,
    ): array {
        if ($previousLedgerEntryId !== null) {
            $this->insertLedgerEntry('reversal', -9600, $year, reversalOfId: $previousLedgerEntryId);
        }
        $ledgerId = $this->insertLedgerEntry('entitlement', 9600, $year);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_leave_entitlement_snapshots
                (supplier_id, employment_id, leave_year, revision_no, relation_type,
                 weekly_minutes, entitlement_weeks, continuous_calendar_days,
                 worked_equivalent_minutes, worked_week_multiples, entitlement_minutes,
                 rationale, support_status, input_hash, calculation_trace,
                 leave_ledger_entry_id, created_by)
             VALUES (?, ?, ?, ?, 'employment', 2400, 4, 365, 96000, 40, 9600,
                     'Testovací nárok', 'manual_review', UNHEX(SHA2(?, 256)), '{}', ?, ?)"
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $year,
            $revision,
            "entitlement-{$year}-{$revision}",
            $ledgerId,
            $this->userId,
        ]);

        return [
            'snapshot_id' => (int) $this->db->pdo()->lastInsertId(),
            'ledger_id' => $ledgerId,
        ];
    }

    private function approveRunForEmployment(string $periodStart, string $seed): void
    {
        $runId = $this->insertRun($this->supplierId, $periodStart);
        $revisionId = $this->insertApprovedRevision($this->supplierId, $runId, $seed);
        $this->linkEmploymentToRevision(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            $this->employmentId,
        );
    }

    private function leaveBalance(int $year): int
    {
        return (int) $this->fetchRow(
            'SELECT COALESCE(SUM(minutes_delta), 0) AS balance
               FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?',
            $this->supplierId,
            $this->employmentId,
            $year,
        )['balance'];
    }

    private function deleteAverage(int $snapshotId): ResponseInterface
    {
        return $this->action->deleteAverage(
            $this->request('DELETE', "/api/payroll/time/averages/{$snapshotId}", []),
            new Response(),
            ['id' => (string) $snapshotId],
        );
    }

    private function deleteLeaveEntry(int $entryId): ResponseInterface
    {
        return $this->action->deleteLeaveEntry(
            $this->request('DELETE', "/api/payroll/time/leave-ledger/{$entryId}", []),
            new Response(),
            ['id' => (string) $entryId],
        );
    }

    private function deleteEntitlement(int $snapshotId): ResponseInterface
    {
        return $this->action->deleteEntitlement(
            $this->request('DELETE', "/api/payroll/time/leave-entitlements/{$snapshotId}", []),
            new Response(),
            ['id' => (string) $snapshotId],
        );
    }

    /** @return array<string,mixed> */
    private function listedAverage(int $snapshotId): array
    {
        $response = $this->action->averages(
            $this->request(
                'GET',
                '/api/payroll/time/averages?employment_id=' . $this->employmentId,
            )->withQueryParams(['employment_id' => (string) $this->employmentId]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $snapshots = $this->json($response)['snapshots'];
        self::assertIsArray($snapshots);
        foreach ($snapshots as $snapshot) {
            if (is_array($snapshot) && (int) $snapshot['id'] === $snapshotId) {
                return $snapshot;
            }
        }
        self::fail('Snapshot průměru v seznamu chybí.');
    }

    /** @return array<string,mixed> */
    private function listedLeaveEntry(int $entryId): array
    {
        foreach ($this->leaveLedgerPayload(2026)['entries'] ?? [] as $entry) {
            if (is_array($entry) && (int) $entry['id'] === $entryId) {
                return $entry;
            }
        }
        self::fail('Zápis v knize dovolené chybí.');
    }

    /** @return array<string,mixed> */
    private function listedEntitlement(int $snapshotId): array
    {
        foreach ($this->leaveLedgerPayload(2026)['entitlements'] ?? [] as $entitlement) {
            if (is_array($entitlement) && (int) $entitlement['id'] === $snapshotId) {
                return $entitlement;
            }
        }
        self::fail('Revize nároku v odpovědi chybí.');
    }

    /** @return array<string,mixed> */
    private function leaveLedgerPayload(int $year): array
    {
        $response = $this->action->leaveLedger(
            $this->request('GET', '/api/payroll/time/leave-ledger')->withQueryParams([
                'employment_id' => (string) $this->employmentId,
                'year' => (string) $year,
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }
}
