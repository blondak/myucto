<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

final class PayrollSubmissionStateMachine
{
    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'draft' => ['validated', 'cancelled_in_time'],
        'validated' => ['draft', 'prepared', 'ready', 'cancelled_in_time'],
        'prepared' => ['validated', 'ready', 'cancelled_in_time'],
        'ready' => ['validated', 'submitted', 'cancelled_in_time'],
        'submitted' => [
            'processing',
            'partially_accepted',
            'rejected',
            'waiting_for_identity',
            'correction_required',
        ],
        'processing' => [
            'accepted',
            'partially_accepted',
            'rejected',
            'waiting_for_identity',
            'correction_required',
        ],
        'waiting_for_identity' => [
            'processing',
            'rejected',
            'correction_required',
        ],
        'partially_accepted' => ['correction_required', 'superseded'],
        'rejected' => ['correction_required', 'superseded'],
        'correction_required' => ['superseded', 'cancelled_in_time'],
        'accepted' => ['superseded'],
        'superseded' => [],
        'cancelled_in_time' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function assertTransition(string $from, string $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(
                "Stav podání nelze změnit z {$from} na {$to}.",
            );
        }
    }

    public function isKnownStatus(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }
}
