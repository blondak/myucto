<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Obligations\ExistingObligationSourceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ExistingObligationSourceServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $connection;
    private PDO $pdo;
    private ExistingObligationSourceService $service;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->connection = $container->get(Connection::class);
        $this->service = $container->get(ExistingObligationSourceService::class);
        $this->pdo = $this->connection->pdo();
        $this->pdo->beginTransaction();
        $sourceSupplierId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->connection)) $this->connection->close();
    }

    public function testTaxAdvanceUsesItsOwnPaymentStateAndTenant(): void
    {
        $this->pdo->prepare(
            'INSERT INTO tax_advance_schedules
                (supplier_id, taxpayer_type, advance_kind, period_year, seq_no,
                 amount, due_date)
             VALUES (?, "po", "tax", 2099, 1, 1200.00, "2099-06-15")'
        )->execute([$this->supplierId]);
        $id = (int) $this->pdo->lastInsertId();

        $rows = $this->service->taxAdvances($this->supplierId, '2099-01-01', '2099-12-31');
        self::assertCount(1, $rows);
        self::assertSame('tax_advance:' . $id, $rows[0]['id']);
        self::assertSame('dppo_advance', $rows[0]['kind']);
        self::assertSame(1200.0, $rows[0]['remaining']);
        self::assertSame('open', $rows[0]['status']);
        self::assertSame([], $this->service->taxAdvances($this->supplierId + 100000, '2099-01-01', '2099-12-31'));

        $this->pdo->prepare(
            'UPDATE tax_advance_schedules SET status = "paid", paid_amount = 1200.00 WHERE id = ?'
        )->execute([$id]);
        $paid = $this->service->taxAdvances($this->supplierId, '2099-01-01', '2099-12-31');
        self::assertSame(0.0, $paid[0]['remaining']);
        self::assertSame('paid', $paid[0]['status']);

        $this->pdo->prepare(
            'UPDATE tax_advance_schedules SET paid_amount = 1300.00 WHERE id = ?'
        )->execute([$id]);
        $overpaid = $this->service->taxAdvances($this->supplierId, '2099-01-01', '2099-12-31');
        self::assertSame(1300.0, $overpaid[0]['paid_amount']);
        self::assertSame(0.0, $overpaid[0]['remaining']);
    }

    public function testApprovedPayrollLiabilityIsReadWithoutPersonalData(): void
    {
        $this->insertPayrollLiability('2099-01-01', '2099-01-10');

        $rows = $this->service->payrollLiabilities($this->supplierId, '2099-01-01', '2099-01-31');
        self::assertCount(1, $rows);
        self::assertSame(123.45, $rows[0]['remaining']);
        self::assertSame('confirmed', $rows[0]['certainty']);
        self::assertNull($rows[0]['partner_name']);
        self::assertStringNotContainsString('Syntetická osoba', json_encode($rows[0], JSON_THROW_ON_ERROR));
        self::assertSame([], $this->service->payrollLiabilities($this->supplierId + 100000, '2099-01-01', '2099-01-31'));
    }

    public function testPayrollForecastIsReplacedByConfirmedPeriod(): void
    {
        $current = new \DateTimeImmutable('first day of this month');
        $next = $current->modify('+1 month');
        $later = $current->modify('+2 months');
        $this->insertPayrollLiability($current->format('Y-m-d'), $current->format('Y-m-10'));
        $forecast = $this->service->payrollForecasts(
            $this->supplierId, $next->format('Y-m-01'), $later->format('Y-m-t')
        );
        self::assertCount(2, $forecast);
        self::assertSame($next->format('Y-m-10'), $forecast[0]['due_on']);
        self::assertSame(123.45, $forecast[0]['remaining']);
        self::assertSame('estimate', $forecast[0]['certainty']);

        $this->insertPayrollLiability($next->format('Y-m-d'), $next->format('Y-m-10'));
        $after = $this->service->payrollForecasts(
            $this->supplierId, $next->format('Y-m-01'), $later->format('Y-m-t')
        );
        self::assertCount(1, $after);
        self::assertSame($later->format('Y-m-10'), $after[0]['due_on']);
    }

    public function testPayrollForecastUsesNetOfIncomingCorrection(): void
    {
        $current = new \DateTimeImmutable('first day of this month');
        $next = $current->modify('+1 month');
        $base = $this->insertPayrollLiability($current->format('Y-m-d'), $current->format('Y-m-10'));
        $this->insertPayrollCorrection($base, $current->format('Y-m-10'), 2345);

        $forecast = $this->service->payrollForecasts(
            $this->supplierId, $next->format('Y-m-01'), $next->format('Y-m-t')
        );

        self::assertCount(1, $forecast);
        self::assertSame('payable', $forecast[0]['side']);
        self::assertEqualsWithDelta(100.0, $forecast[0]['remaining'], 0.001);
    }

    public function testFullyCorrectedWageDoesNotCreateFutureForecast(): void
    {
        $current = new \DateTimeImmutable('first day of this month');
        $next = $current->modify('+1 month');
        $base = $this->insertPayrollLiability($current->format('Y-m-d'), $current->format('Y-m-10'));
        $this->insertPayrollCorrection($base, $current->format('Y-m-10'), 12345);

        self::assertSame([], $this->service->payrollForecasts(
            $this->supplierId, $next->format('Y-m-01'), $next->format('Y-m-t')
        ));
    }

    public function testPayrollCorrectionMovesForecastToLatestDueDay(): void
    {
        $current = new \DateTimeImmutable('first day of this month');
        $next = $current->modify('+1 month');
        $base = $this->insertPayrollLiability($current->format('Y-m-d'), $current->format('Y-m-10'));
        $this->insertPayrollCorrection($base, $current->format('Y-m-11'), 2345);

        $forecast = $this->service->payrollForecasts(
            $this->supplierId, $next->format('Y-m-01'), $next->format('Y-m-t')
        );

        self::assertCount(1, $forecast);
        self::assertSame($next->format('Y-m-11'), $forecast[0]['due_on']);
        self::assertEqualsWithDelta(100.0, $forecast[0]['remaining'], 0.001);
    }

    public function testCancelledPayrollRunDoesNotSeedForecast(): void
    {
        $current = new \DateTimeImmutable('first day of this month');
        $next = $current->modify('+1 month');
        $base = $this->insertPayrollLiability($current->format('Y-m-d'), $current->format('Y-m-10'));
        $this->pdo->prepare('UPDATE payroll_runs SET status = "cancelled" WHERE id = ? AND supplier_id = ?')
            ->execute([$base['run_id'], $this->supplierId]);

        self::assertSame([], $this->service->payrollForecasts(
            $this->supplierId, $next->format('Y-m-01'), $next->format('Y-m-t')
        ));
    }

    public function testIncomingOnlyConfirmedPeriodSuppressesForecast(): void
    {
        $current = new \DateTimeImmutable('first day of this month');
        $next = $current->modify('+1 month');
        $this->insertPayrollLiability($current->format('Y-m-d'), $current->format('Y-m-10'));
        $this->insertPayrollLiability($next->format('Y-m-d'), $next->format('Y-m-10'), 'incoming');

        self::assertSame([], $this->service->payrollForecasts(
            $this->supplierId, $next->format('Y-m-01'), $next->format('Y-m-t')
        ));
    }

    public function testVatForecastUsesTaxCalendarAndStopsAfterPostedClearing(): void
    {
        $period = new \DateTimeImmutable('first day of this month');
        $year = (int) $period->format('Y');
        $month = (int) $period->format('n');
        $this->setVatPayerAt($this->pdo, $this->supplierId, '2020-01-01', true);
        $this->pdo->prepare('UPDATE supplier SET vat_period = "monthly" WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, "Syntetická pokladna", "CZK", "211999")'
        )->execute([$this->supplierId]);
        $registerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, purpose, doc_number, status, doc_type, vat_mode, tax_date,
                 issue_date, total_amount, currency_code, description)
             VALUES (?, ?, "other", "SYNTH-VAT-FORECAST", "posted", "in", "vat", ?, ?, 121, "CZK",
                     "Syntetický příjem")'
        )->execute([$this->supplierId, $registerId, $period->format('Y-m-10'), $period->format('Y-m-10')]);
        $cashId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO cash_document_vat_lines
                (cash_document_id, vat_rate, base_amount, vat_amount, vat_classification_code)
             VALUES (?, 21, 100, 21, "1")'
        )->execute([$cashId]);

        $forecast = $this->service->vatForecastForPeriod($this->supplierId, $year, $month);
        self::assertNotNull($forecast);
        self::assertSame(21.0, $forecast['amount']);
        self::assertSame('estimate', $forecast['certainty']);
        $next = $period->modify('+1 month');
        self::assertSame(
            \MyInvoice\Service\Report\CzechWorkingDays::deadline(
                (int) $next->format('Y'), (int) $next->format('n')
            ),
            $forecast['due_on']
        );
        $forecastRows = $this->service->taxForecasts(
            $this->supplierId, $forecast['due_on'], $forecast['due_on']
        );
        self::assertCount(1, $forecastRows);
        self::assertSame($forecast['id'], $forecastRows[0]['id']);
        self::assertSame(21.0, $forecastRows[0]['remaining']);

        $this->pdo->prepare(
            'INSERT INTO vat_clearing_runs
                (supplier_id, source_id, period_year, period_first_month, period_type,
                 period_start, period_end, status, trigger_source)
             VALUES (?, 900001, ?, ?, "monthly", ?, ?, "posted", "manual")'
        )->execute([$this->supplierId, $year, $month, $period->format('Y-m-01'),
            $period->format('Y-m-t')]);
        self::assertNull($this->service->vatForecastForPeriod($this->supplierId, $year, $month));
        self::assertNull($this->service->vatForecastForPeriod($this->supplierId + 100000, $year, $month));

        $this->pdo->prepare('DELETE FROM vat_clearing_runs WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, purpose, doc_number, status, doc_type,
                 vat_mode, tax_date, issue_date, total_amount, currency_code, description)
             VALUES (?, ?, "purchase", "SYNTH-VAT-INPUT", "posted", "out", "vat",
                     ?, ?, 242, "CZK", "Syntetický výdaj")'
        )->execute([$this->supplierId, $registerId, $period->format('Y-m-10'), $period->format('Y-m-10')]);
        $purchaseCashId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO cash_document_vat_lines
                (cash_document_id, vat_rate, base_amount, vat_amount, vat_classification_code,
                 vat_deduction, vat_deduction_percent)
             VALUES (?, 21, 200, 42, "40", "full", 100)'
        )->execute([$purchaseCashId]);
        $refund = $this->service->vatForecastForPeriod($this->supplierId, $year, $month);
        self::assertNotNull($refund);
        self::assertSame('receivable', $refund['side']);
        self::assertSame('vat_refund', $refund['kind']);
        self::assertSame(21.0, $refund['remaining']);
        self::assertSame((new \DateTimeImmutable($forecast['due_on']))
            ->modify('+30 days')->format('Y-m-d'), $refund['due_on']);
        $refundRows = $this->service->taxForecasts(
            $this->supplierId, $refund['due_on'], $refund['due_on']
        );
        self::assertCount(1, $refundRows);
        self::assertSame($refund['id'], $refundRows[0]['id']);
        self::assertSame(21.0, $refundRows[0]['remaining']);
    }

    public function testDppoForecastDoesNotCreateReturn(): void
    {
        $this->pdo->prepare(
            'UPDATE supplier SET taxpayer_type = "po", accounting_mode = "double_entry" WHERE id = ?'
        )->execute([$this->supplierId]);
        $year = (int) date('Y');
        self::assertNull($this->service->dppoForecastForYear($this->supplierId, $year));
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM income_tax_returns WHERE supplier_id = ? AND year = ?'
        );
        $count->execute([$this->supplierId, $year]);
        self::assertSame(0, (int) $count->fetchColumn());

        $this->pdo->prepare(
            'INSERT INTO income_tax_returns
                (supplier_id, year, taxpayer_type, status, inputs)
             VALUES (?, ?, "po", "draft", "{}")'
        )->execute([$this->supplierId, $year]);
        $this->service->dppoForecastForYear($this->supplierId, $year);
        $count->execute([$this->supplierId, $year]);
        self::assertSame(1, (int) $count->fetchColumn());
        $state = $this->pdo->prepare(
            'SELECT status, inputs FROM income_tax_returns WHERE supplier_id = ? AND year = ?'
        );
        $state->execute([$this->supplierId, $year]);
        self::assertSame(['status' => 'draft', 'inputs' => '{}'], $state->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array{run_id:int,revision_id:int,employee_id:int,liability_id:int} */
    private function insertPayrollLiability(string $periodStart, string $dueOn, string $direction = 'outgoing'): array
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba", "employee", 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, "approved")'
        )->execute([$this->supplierId, $periodStart, $dueOn]);
        $runId = (int) $this->pdo->lastInsertId();
        $snapshot = '{"schema":"synthetic-payment.v1"}';
        $hash = hash('sha256', $snapshot);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-payment.v1", ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$this->supplierId, $runId, str_repeat('a', 64), $snapshot, $hash,
            $snapshot, $hash, hash('sha256', 'synthetic-obligation-revision-' . $periodStart, true)]);
        $revisionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")'
        )->execute([$this->supplierId, $revisionId, $employeeId, $snapshot, $hash]);
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "net-wage.synthetic", "net_wage", ?,
                     "recipient:synthetic", ?, "CZK", 12345, ?, ?, ?)'
        )->execute([$this->supplierId, $revisionId, $employeeId, $direction, $dueOn, $snapshot, $hash,
            hash('sha256', 'synthetic-obligation-liability-' . $periodStart, true)]);
        return [
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'employee_id' => $employeeId,
            'liability_id' => (int) $this->pdo->lastInsertId(),
        ];
    }

    /** @param array{run_id:int,revision_id:int,employee_id:int,liability_id:int} $base */
    private function insertPayrollCorrection(array $base, string $dueOn, int $amountMinor): void
    {
        $snapshot = '{"schema":"synthetic-payment.v1"}';
        $hash = hash('sha256', $snapshot);
        $this->pdo->prepare('UPDATE payroll_run_revisions SET status = "superseded", superseded_at = NOW() WHERE id = ? AND supplier_id = ?')
            ->execute([$base['revision_id'], $this->supplierId]);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 2, ?, "approved", "synthetic-payment.v1", ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$this->supplierId, $base['run_id'], $base['revision_id'], str_repeat('a', 64),
            $snapshot, $hash, $snapshot, $hash,
            hash('sha256', 'synthetic-obligation-correction-' . $base['run_id'], true)]);
        $revisionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")'
        )->execute([$this->supplierId, $revisionId, $base['employee_id'], $snapshot, $hash]);
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, previous_liability_id,
                 source_snapshot_json, source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "net-wage.synthetic", "net_wage", "incoming",
                     "recipient:synthetic", ?, "CZK", ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $revisionId, $base['employee_id'], $dueOn,
            $amountMinor, $base['liability_id'], $snapshot, $hash,
            hash('sha256', 'synthetic-obligation-correction-liability-' . $base['run_id'], true)]);
    }
}
