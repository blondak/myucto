<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Learning;

use MyInvoice\Repository\AccountingCorrectionRepository;

final class CorrectionRecorder
{
    private const SOURCES = ['rule', 'learned', 'knn', 'llm', 'detector', 'manual', 'payment_match', 'schedule'];

    public function __construct(private readonly AccountingCorrectionRepository $repo) {}

    /** @param array<string,mixed> $data */
    public function record(int $supplierId, array $data): int
    {
        if (isset($data['suggestion_source']) && !in_array($data['suggestion_source'], self::SOURCES, true)) {
            $data['suggestion_source'] = null;
        }
        return $this->repo->insert($supplierId, $data);
    }

    /** @param array<string,mixed> $suggestion */
    public function fromSuggestion(
        int $supplierId,
        string $eventType,
        array $suggestion,
        ?string $finalDebit,
        ?string $finalCredit,
        ?int $userId,
        ?string $reason = null,
    ): int {
        return $this->record($supplierId, [
            'event_type' => $eventType,
            'entity_type' => 'bank_transaction',
            'entity_id' => (int) $suggestion['bank_transaction_id'],
            'suggestion_id' => (int) $suggestion['id'],
            'suggestion_source' => (string) $suggestion['source'],
            'rule_id' => $suggestion['rule_id'] === null ? null : (int) $suggestion['rule_id'],
            'suggested_debit' => $suggestion['debit_account_code'] ?? null,
            'suggested_credit' => $suggestion['credit_account_code'] ?? null,
            'final_debit' => $finalDebit,
            'final_credit' => $finalCredit,
            'amount' => isset($suggestion['amount']) ? abs((float) $suggestion['amount']) : null,
            'reason' => $reason,
            'created_by' => $userId,
        ]);
    }

    public function ruleEvent(
        int $supplierId,
        string $eventType,
        int $ruleId,
        ?int $userId,
        ?string $reason = null,
    ): int {
        return $this->record($supplierId, [
            'event_type' => $eventType,
            'entity_type' => 'bank_posting_rule',
            'entity_id' => $ruleId,
            'rule_id' => $ruleId,
            'reason' => $reason,
            'created_by' => $userId,
        ]);
    }
}
