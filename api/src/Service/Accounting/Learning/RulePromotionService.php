<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Learning;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;

final class RulePromotionService
{
    public const MIN_HITS = 5;
    public const CLEAN_APPROVES = 5;

    public function __construct(
        private readonly BankPostingRuleRepository $rules,
        private readonly CorrectionRecorder $corrections,
        private readonly ActivityLogger $activity,
        private readonly Connection $db,
    ) {}

    /** @param array<string,mixed> $rule */
    public static function isCandidate(array $rule): bool
    {
        return (bool) ($rule['is_active'] ?? false)
            && (string) ($rule['mode'] ?? '') === 'suggest'
            && (int) ($rule['hit_count'] ?? 0) >= self::MIN_HITS
            && (int) ($rule['rejected_streak'] ?? 0) === 0
            && (int) ($rule['approved_streak'] ?? 0) >= self::CLEAN_APPROVES
            && ($rule['amount_min'] ?? null) !== null
            && ($rule['amount_max'] ?? null) !== null;
    }

    /** @param array<string,mixed> $rule */
    public function onApprove(int $supplierId, array $rule, bool $clean, ?int $userId): void
    {
        $result = $this->rules->recordApprove((int) $rule['id'], $clean);
        if (!$clean || $result['approved_streak'] !== self::CLEAN_APPROVES) return;
        $fresh = $this->rules->find($supplierId, (int) $rule['id']);
        if ($fresh === null || !self::isCandidate($fresh)) return;
        $this->corrections->ruleEvent(
            $supplierId,
            'rule_promotion_suggested',
            (int) $rule['id'],
            $userId,
            '5_clean_approves',
        );
        $this->activity->log(
            'bank_rule.promotion_suggested',
            $userId,
            'bank_posting_rule',
            (int) $rule['id'],
            ['approved_streak' => self::CLEAN_APPROVES],
            supplierId: $supplierId,
        );
    }

    /** @return array<string,mixed> */
    public function promote(int $supplierId, int $ruleId, ?int $userId): array
    {
        return $this->transactional(function () use ($supplierId, $ruleId, $userId): array {
            $rule = $this->rules->findForUpdate($supplierId, $ruleId);
            if ($rule === null) throw new PostingException('not_found', 'Pravidlo nenalezeno.', 404);
            if (!(bool) $rule['is_active'] || (string) $rule['mode'] !== 'suggest') {
                throw new PostingException('rule_not_suggest', 'Pravidlo není aktivní návrhové pravidlo.', 409);
            }
            if ($rule['amount_min'] === null || $rule['amount_max'] === null) {
                throw new PostingException('amount_band_required', 'Plná automatika vyžaduje rozsah částky.', 422);
            }
            if (!self::isCandidate($rule)) {
                throw new PostingException('rule_not_ready', 'Pravidlo ještě nemá pět čistých potvrzení.', 409);
            }
            $this->rules->update($supplierId, $ruleId, ['mode' => 'auto']);
            $this->corrections->ruleEvent($supplierId, 'rule_promoted', $ruleId, $userId);
            $this->activity->log('bank_rule.promoted', $userId, 'bank_posting_rule', $ruleId, null, supplierId: $supplierId);
            return $this->rules->find($supplierId, $ruleId) ?? $rule;
        });
    }

    /** @return array<string,mixed> */
    public function demote(int $supplierId, int $ruleId, ?int $userId, string $reason): array
    {
        return $this->transactional(function () use ($supplierId, $ruleId, $userId, $reason): array {
            $rule = $this->rules->findForUpdate($supplierId, $ruleId);
            if ($rule === null) throw new PostingException('not_found', 'Pravidlo nenalezeno.', 404);
            if ((string) $rule['mode'] !== 'auto') {
                throw new PostingException('rule_not_auto', 'Pravidlo není v automatickém režimu.', 409);
            }
            $this->rules->update($supplierId, $ruleId, ['mode' => 'suggest']);
            $this->rules->resetApprovedStreak($ruleId);
            $this->corrections->ruleEvent($supplierId, 'rule_demoted', $ruleId, $userId, $reason);
            $this->activity->log(
                'bank_rule.demoted',
                $userId,
                'bank_posting_rule',
                $ruleId,
                ['reason' => $reason],
                supplierId: $supplierId,
            );
            return $this->rules->find($supplierId, $ruleId) ?? $rule;
        });
    }

    /** @template T @param callable():T $callback @return T */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $result = $callback();
            if ($own) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
