<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use Psr\Clock\ClockInterface;

final class PayrollDeadlineAssessmentService
{
    private const OBLIGATION_STATUSES = [
        'open',
        'prepared',
        'submitted',
        'fulfilled',
        'overdue',
        'cancelled',
        'manual_review',
    ];
    private const SUBMISSION_STATUSES = [
        'draft',
        'validated',
        'prepared',
        'ready',
        'submitted',
        'processing',
        'accepted',
        'partially_accepted',
        'rejected',
        'waiting_for_identity',
        'correction_required',
        'superseded',
        'cancelled_in_time',
    ];
    private const ACTION_SUBMISSION_STATUSES = [
        'partially_accepted',
        'rejected',
        'waiting_for_identity',
        'correction_required',
        'superseded',
    ];
    private const PENDING_SUBMISSION_STATUSES = [
        'submitted',
        'processing',
    ];

    public function __construct(private readonly ClockInterface $clock) {}

    public function assess(
        string $earliestSubmissionOn,
        string $dueOn,
        string $obligationStatus,
        ?string $latestSubmissionStatus,
    ): PayrollDeadlineAssessment {
        $earliest = $this->date($earliestSubmissionOn, 'počátek lhůty');
        $due = $this->date($dueOn, 'konec lhůty');
        if ($due < $earliest) {
            throw new \InvalidArgumentException(
                'Konec lhůty podání nesmí předcházet jejímu počátku.',
            );
        }
        if (!in_array(
            $obligationStatus,
            self::OBLIGATION_STATUSES,
            true,
        )) {
            throw new \InvalidArgumentException(
                'Stav povinnosti podání není podporovaný.',
            );
        }
        if ($latestSubmissionStatus !== null
            && !in_array(
                $latestSubmissionStatus,
                self::SUBMISSION_STATUSES,
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Stav podání není podporovaný.',
            );
        }

        $today = \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->setTime(0, 0);
        $daysToDue = (int) $today->diff($due)->format('%r%a');

        if ($obligationStatus === 'cancelled'
            || $latestSubmissionStatus === 'cancelled_in_time'
        ) {
            return $this->result('cancelled', $daysToDue, false, false);
        }
        if ($obligationStatus === 'manual_review'
            || in_array(
                $latestSubmissionStatus,
                self::ACTION_SUBMISSION_STATUSES,
                true,
            )
        ) {
            return $this->result(
                'action_required',
                $daysToDue,
                true,
                $due < $today,
            );
        }
        if ($obligationStatus === 'fulfilled'
            || $latestSubmissionStatus === 'accepted'
        ) {
            return $this->result('fulfilled', $daysToDue, false, false);
        }
        if ($today < $earliest) {
            return $this->result('not_open', $daysToDue, false, false);
        }
        if ($due < $today) {
            return $this->result('overdue', $daysToDue, true, true);
        }
        if ($latestSubmissionStatus !== null
            && in_array(
                $latestSubmissionStatus,
                self::PENDING_SUBMISSION_STATUSES,
                true,
            )
        ) {
            return $this->result(
                'awaiting_result',
                $daysToDue,
                false,
                false,
            );
        }
        if ($daysToDue === 0) {
            return $this->result('due_today', 0, true, false);
        }
        if ($daysToDue <= 5) {
            return $this->result('due_soon', $daysToDue, true, false);
        }

        return $this->result('open', $daysToDue, true, false);
    }

    private function date(string $value, string $field): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException(
                "Datum {$field} není platné.",
            );
        }

        return $date;
    }

    private function result(
        string $phase,
        int $daysToDue,
        bool $isActionRequired,
        bool $isOverdue,
    ): PayrollDeadlineAssessment {
        return new PayrollDeadlineAssessment(
            $phase,
            $daysToDue,
            $isActionRequired,
            $isOverdue,
        );
    }
}
