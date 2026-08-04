<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Document\ApprovedRevisionPayslipBatchService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorageScope;
use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsService;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollRunCommandService
{
    private const COMMAND_SAVEPOINT = 'payroll_run_command';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollRunSnapshotBuilder $snapshotBuilder,
        private readonly PayrollRunCalculationPipeline $calculationPipeline,
        private readonly PayrollRunWorkflow $workflow,
        private readonly PayrollPeriodOwnershipService $ownership,
        private readonly ?PayrollApprovedRevisionPostingService
            $approvedPosting = null,
        private readonly ?ApprovedRevisionPayslipBatchService
            $approvedPayslips = null,
        private readonly ?PayrollControlTotalsService
            $controlTotals = null,
    ) {}

    /** @return array<string,mixed> */
    public function createRun(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId,
        int $actorUserId,
    ): array {
        $this->assertActor($actorUserId);
        $period = $this->period($periodStart);
        $this->paymentDate($paymentDate, $period);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->assertModuleAvailable($supplierId, $periodStart);
            $run = $this->runs->createOrGet(
                $supplierId,
                $periodStart,
                $paymentDate,
                $officeId,
                $actorUserId,
            );
            $this->ownership->claimPayroll(
                $supplierId,
                (int) $period->format('Y'),
                (int) $period->format('m'),
                'payroll_run',
                (int) $run['id'],
                $actorUserId,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $run;
        } catch (\Throwable $e) {
            $this->rollbackOwnedTransaction($pdo, $ownsTransaction);
            throw $e;
        }
    }

    public function lockInputs(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::LOCK_INPUTS,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function calculate(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::CALCULATE,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function review(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::REVIEW,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function approve(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::APPROVE,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function requestCorrection(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        string $reason,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::REQUEST_CORRECTION,
            $idempotencyKey,
            $actorUserId,
            $reason,
        );
    }

    public function reopen(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        string $reason,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::REOPEN,
            $idempotencyKey,
            $actorUserId,
            $reason,
        );
    }

    public function cancel(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        string $reason,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::CANCEL,
            $idempotencyKey,
            $actorUserId,
            $reason,
        );
    }

    public function close(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::CLOSE,
            $idempotencyKey,
            $actorUserId,
        );
    }

    private function execute(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        PayrollRunCommand $command,
        string $idempotencyKey,
        int $actorUserId,
        ?string $reason = null,
    ): PayrollRunCommandResult {
        $this->assertActor($actorUserId);
        if ($supplierId <= 0 || $runId <= 0 || $expectedVersion <= 0) {
            throw new \InvalidArgumentException('Identifikace mzdového příkazu není platná.');
        }
        $normalizedKey = trim($idempotencyKey);
        if (mb_strlen($normalizedKey) < 8 || mb_strlen($normalizedKey) > 190) {
            throw new \InvalidArgumentException(
                'Idempotency key musí mít 8 až 190 znaků.',
            );
        }
        $reason = $reason === null ? null : trim($reason);
        $keyHashBinary = hash('sha256', $normalizedKey, true);
        $keyHashHex = hash('sha256', $normalizedKey);
        $requestHash = hash('sha256', CanonicalJson::encode([
            'actor_user_id' => $actorUserId,
            'command' => $command->value,
            'expected_row_version' => $expectedVersion,
            'reason' => $reason,
            'run_id' => $runId,
            'supplier_id' => $supplierId,
        ]));

        $pdo = $this->db->pdo();
        $nestedTransaction = $pdo->inTransaction();
        if ($nestedTransaction) {
            $pdo->exec('SAVEPOINT ' . self::COMMAND_SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        $payslipStorageScope = null;
        try {
            $run = $this->runs->lock($supplierId, $runId);
            if ($run === null) {
                throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
            }

            $receipt = $this->runs->commandReceipt($supplierId, $keyHashBinary);
            if ($receipt !== null) {
                $result = $this->replay(
                    $supplierId,
                    $runId,
                    $command,
                    $requestHash,
                    $receipt,
                );
                $this->finishCommandTransaction($pdo, $nestedTransaction);
                return $result;
            }

            $this->assertModuleAvailable(
                $supplierId,
                (string) $run['period_start'],
            );
            $currentVersion = (int) $run['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollRunConflictException($currentVersion);
            }
            $from = PayrollRunStatus::from((string) $run['status']);
            $revision = $this->runs->currentRevision($supplierId, $runId);
            $snapshot = null;
            if (in_array($command, [
                PayrollRunCommand::LOCK_INPUTS,
                PayrollRunCommand::REOPEN,
            ], true)) {
                $snapshot = $this->snapshotBuilder->build(
                    $supplierId,
                    (string) $run['period_start'],
                    (string) $run['payment_date'],
                    $run['office_id'] === null ? null : (int) $run['office_id'],
                );
                if ($command === PayrollRunCommand::REOPEN) {
                    $snapshot = $this->calculationPipeline
                        ->prepareCorrectionSnapshot(
                            $supplierId,
                            $runId,
                            $snapshot,
                        );
                }
            }
            $counts = $revision === null
                ? ['blockers' => 0, 'unresolved_overrides' => 0]
                : $this->runs->validationCounts(
                    $supplierId,
                    (int) $revision['id'],
                );
            $context = new PayrollRunTransitionContext(
                actorUserId: $actorUserId,
                calculatedBy: $revision['calculated_by'] ?? null,
                reviewedBy: $revision['reviewed_by'] ?? null,
                blockerCount: $counts['blockers'],
                unresolvedOverrideCount: $counts['unresolved_overrides'],
                hasImmutableSnapshot: $snapshot !== null || $revision !== null,
                hasCalculatedResult:
                    $revision !== null && $revision['result_snapshot_json'] !== null,
                reason: $reason,
            );
            $transition = $this->workflow->transition($from, $command, $context);

            if ($command === PayrollRunCommand::LOCK_INPUTS
                || $command === PayrollRunCommand::REOPEN
            ) {
                $revisionNo = (int) $run['current_revision_no'] + 1;
                $previousRevisionId = $revision === null
                    ? null
                    : (int) $revision['id'];
                $revisionId = $this->runs->insertRevision(
                    $supplierId,
                    $runId,
                    $revisionNo,
                    $previousRevisionId,
                    $command === PayrollRunCommand::REOPEN ? 'correction' : 'regular',
                    $snapshot,
                    $keyHashBinary,
                );
                $this->runs->insertSnapshotGraph(
                    $supplierId,
                    $revisionId,
                    $snapshot,
                );
                $this->runs->lockApprovedInputs(
                    $supplierId,
                    $revisionId,
                    (string) $run['period_start'],
                    $run['office_id'] === null ? null : (int) $run['office_id'],
                );
                $revision = $this->runs->revision($supplierId, $revisionId);
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    $revisionNo,
                    $actorUserId,
                );
            } elseif ($command === PayrollRunCommand::CALCULATE) {
                if ($revision === null
                    || !is_array($revision['input_snapshot'] ?? null)
                ) {
                    throw new \DomainException('Mzdový běh nemá vstupní snapshot.');
                }
                $calculation = $this->calculationPipeline->calculate(
                    $revision['input_snapshot'],
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                $this->runs->replaceEnforcementValidations(
                    $supplierId,
                    (int) $revision['id'],
                    $calculation,
                );
                $this->runs->replaceStatutoryValidations(
                    $supplierId,
                    (int) $revision['id'],
                    $calculation,
                );
                $this->runs->saveCalculation(
                    $supplierId,
                    (int) $revision['id'],
                    $calculation,
                    $actorUserId,
                );
                $revision = $this->runs->revision(
                    $supplierId,
                    (int) $revision['id'],
                );
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            } elseif ($command === PayrollRunCommand::REVIEW) {
                if ($revision === null) {
                    throw new \DomainException('Mzdový běh nemá revizi.');
                }
                $this->runs->markRevisionReviewed(
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                $revision = $this->runs->revision(
                    $supplierId,
                    (int) $revision['id'],
                );
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            } elseif ($command === PayrollRunCommand::APPROVE) {
                if ($revision === null) {
                    throw new \DomainException('Mzdový běh nemá revizi.');
                }
                if ($nestedTransaction && $this->approvedPayslips !== null) {
                    throw new \DomainException(
                        'Schválení s generováním výplatních pásek musí proběhnout v samostatné databázové transakci.',
                    );
                }
                $payslipStorageScope = $this->approvedPayslips
                    ?->beginStorageScope();
                $resultSnapshot = self::snapshotObject(
                    $revision['result_snapshot'] ?? null,
                    'výsledný',
                );
                $inputSnapshot = self::snapshotObject(
                    $revision['input_snapshot'] ?? null,
                    'vstupní',
                );
                $this->calculationPipeline->storeApproved(
                    $supplierId,
                    (int) $revision['id'],
                    $resultSnapshot,
                );
                $this->runs->markRevisionApproved(
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                $this->controlTotals?->forApprovedRevision(
                    $supplierId,
                    (int) $revision['id'],
                );
                $this->calculationPipeline
                    ->storeApprovedStatutoryAccumulators(
                        $supplierId,
                        (int) $revision['id'],
                        $actorUserId,
                    );
                $this->calculationPipeline->storeApprovedDeductions(
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                $this->approvedPosting?->post(
                    $supplierId,
                    (int) $revision['id'],
                    $inputSnapshot,
                    $resultSnapshot,
                    $actorUserId,
                );
                $this->approvedPayslips?->generate(
                    $supplierId,
                    $runId,
                    (int) $revision['id'],
                    $actorUserId,
                    $payslipStorageScope,
                );
                $revision = $this->runs->revision(
                    $supplierId,
                    (int) $revision['id'],
                );
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            } else {
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            }

            $revisionId = $revision === null ? null : (int) $revision['id'];
            $resultPayload = [
                'run_id' => $runId,
                'revision_id' => $revisionId,
                'from_status' => $transition->from->value,
                'to_status' => $transition->to->value,
                'row_version' => (int) $run['row_version'],
            ];
            $this->runs->insertEvent(
                $supplierId,
                $runId,
                $revisionId,
                $command->value,
                $transition->from->value,
                $transition->to->value,
                $actorUserId,
                $reason,
                [
                    'idempotency_key_hash' => $keyHashHex,
                    'request_hash' => $requestHash,
                    'row_version' => (int) $run['row_version'],
                ],
            );
            $this->runs->insertCommandReceipt(
                $supplierId,
                $runId,
                $revisionId,
                $command->value,
                $keyHashBinary,
                $requestHash,
                $expectedVersion,
                $transition->from->value,
                $transition->to->value,
                $resultPayload,
                $actorUserId,
            );
            $this->finishCommandTransaction($pdo, $nestedTransaction);
            if ($payslipStorageScope instanceof PayrollDocumentStorageScope) {
                $this->approvedPayslips->commitStorageScope(
                    $payslipStorageScope,
                );
            }
            return new PayrollRunCommandResult(
                $command,
                $transition->from,
                $transition->to,
                $run,
                $revision,
                false,
            );
        } catch (\Throwable $e) {
            $this->rollbackCommandTransaction($pdo, $nestedTransaction);
            if (
                $payslipStorageScope instanceof PayrollDocumentStorageScope
                && $this->approvedPayslips
                    instanceof ApprovedRevisionPayslipBatchService
            ) {
                try {
                    $this->approvedPayslips->cleanupStorageScope(
                        $supplierId,
                        $payslipStorageScope,
                    );
                } catch (\Throwable $cleanupException) {
                    throw new \RuntimeException(
                        'Schválení selhalo a soubory výplatních pásek se nepodařilo uklidit.',
                        previous: $cleanupException,
                    );
                }
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $receipt
     */
    private function replay(
        int $supplierId,
        int $runId,
        PayrollRunCommand $command,
        string $requestHash,
        array $receipt,
    ): PayrollRunCommandResult {
        if ((int) $receipt['run_id'] !== $runId
            || (string) $receipt['command_name'] !== $command->value
            || !hash_equals((string) $receipt['request_hash'], $requestHash)
        ) {
            throw new PayrollRunIdempotencyException();
        }
        $run = $this->runs->find($supplierId, $runId)
            ?? throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
        $revision = $receipt['revision_id'] === null
            ? null
            : $this->runs->revision($supplierId, (int) $receipt['revision_id']);
        return new PayrollRunCommandResult(
            $command,
            PayrollRunStatus::from((string) $receipt['from_status']),
            PayrollRunStatus::from((string) $receipt['to_status']),
            $run,
            $revision,
            true,
        );
    }

    private function assertModuleAvailable(int $supplierId, string $periodStart): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier.payroll_enabled,
                    state.status AS module_status,
                    state.start_period
               FROM supplier
          LEFT JOIN payroll_module_state state ON state.supplier_id = supplier.id
              WHERE supplier.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \OutOfBoundsException('Firma nebyla nalezena.');
        }
        if (!(bool) $row['payroll_enabled']) {
            throw new \DomainException('Firma nemá vedení mezd zapnuté.');
        }
        if ($row['module_status'] === null
            || in_array($row['module_status'], ['disabled', 'suspended'], true)
        ) {
            throw new \DomainException('Plný mzdový modul firmy není aktivní.');
        }
        if ($row['start_period'] !== null
            && (string) $row['start_period'] > $periodStart
        ) {
            throw new \DomainException('Období předchází aktivaci plného mzdového modulu.');
        }
    }

    private function period(string $periodStart): \DateTimeImmutable
    {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($period === false
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
        ) {
            throw new \InvalidArgumentException(
                'Mzdové období musí být první den měsíce.',
            );
        }
        return $period;
    }

    /** @return array<string,mixed> */
    private static function snapshotObject(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException(
                "Mzdový běh nemá uložený {$label} snapshot.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "Mzdový {$label}ní snapshot nemá platné klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function paymentDate(
        string $paymentDate,
        \DateTimeImmutable $period,
    ): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        if ($date === false || $date->format('Y-m-d') !== $paymentDate) {
            throw new \InvalidArgumentException(
                'Datum výplaty musí být platné datum ve formátu YYYY-MM-DD.',
            );
        }
        if ($date < $period) {
            throw new \InvalidArgumentException(
                'Datum výplaty nesmí předcházet mzdovému období.',
            );
        }
        return $date;
    }

    private function assertActor(int $actorUserId): void
    {
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Uživatel příkazu není platný.');
        }
    }

    private function rollbackOwnedTransaction(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function finishCommandTransaction(
        PDO $pdo,
        bool $nestedTransaction,
    ): void {
        if ($nestedTransaction) {
            $pdo->exec('RELEASE SAVEPOINT ' . self::COMMAND_SAVEPOINT);
        } else {
            $pdo->commit();
        }
    }

    private function rollbackCommandTransaction(
        PDO $pdo,
        bool $nestedTransaction,
    ): void {
        if ($nestedTransaction) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::COMMAND_SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::COMMAND_SAVEPOINT);
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
