<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;

final class PayrollRecurringMaterializer
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRecurringComponentRepository $recurring,
        private readonly PayrollRecurringAmountCalculator $calculator,
    ) {
    }

    /** @return array<string,mixed> */
    public function materialize(int $supplierId, string $period, ?int $userId): array
    {
        $periodStart = $this->period($period);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $created = [];
            $replayed = [];
            $manualReview = [];
            foreach ($this->recurring->effectiveForPeriod($supplierId, $periodStart) as $row) {
                if (!PayrollTimeValue::bool(
                    $row['component_is_active'] ?? null,
                    'component_is_active',
                )) {
                    $manualReview[] = $this->blocked($row, 'Mzdová složka není aktivní.');
                    continue;
                }
                $calculation = $this->calculator->calculate($row, $periodStart);
                if ($calculation['status'] !== 'supported') {
                    $blocker = $calculation['blocker'];
                    if (!is_string($blocker)) {
                        throw new \UnexpectedValueException(
                            'Ručně posuzovaný předpis nemá důvod blokace.'
                        );
                    }
                    $manualReview[] = $this->blocked(
                        $row,
                        $blocker,
                    );
                    continue;
                }
                $draft = $this->recurring->createDraftInput(
                    $supplierId,
                    $periodStart,
                    $row,
                    $calculation,
                    $userId,
                );
                $item = [
                    'recurring_component_id' => PayrollTimeValue::int(
                        $row['id'] ?? null,
                        'recurring_component_id',
                    ),
                    'input_id' => $draft['input_id'],
                    'amount_minor' => PayrollTimeValue::int(
                        $calculation['amount_minor'] ?? null,
                        'amount_minor',
                    ),
                ];
                if ($draft['created']) {
                    $created[] = $item;
                } else {
                    $replayed[] = $item;
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return [
            'period' => substr($periodStart, 0, 7),
            'created_count' => count($created),
            'replayed_count' => count($replayed),
            'manual_review_count' => count($manualReview),
            'created' => $created,
            'replayed' => $replayed,
            'manual_review' => $manualReview,
        ];
    }

    private function period(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('Období musí být měsíc YYYY-MM.');
        }
        return $value . '-01';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{recurring_component_id:int,employment_id:int,component_id:int,reason:string}
     */
    private function blocked(array $row, string $reason): array
    {
        return [
            'recurring_component_id' => PayrollTimeValue::int(
                $row['id'] ?? null,
                'recurring_component_id',
            ),
            'employment_id' => PayrollTimeValue::int(
                $row['employment_id'] ?? null,
                'employment_id',
            ),
            'component_id' => PayrollTimeValue::int(
                $row['component_id'] ?? null,
                'component_id',
            ),
            'reason' => $reason,
        ];
    }

    private function rollbackOwned(\PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
