<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Jedna konkrétní oznamovací povinnost: co, kdy vzniklo, komu se to hlásí,
 * do kdy, z čeho to plyne — a jestli se za ni vůbec podává.
 */
final readonly class HealthNotificationDuty
{
    public function __construct(
        public HealthNotificationDutyKind $kind,
        public int $employmentId,
        public int $employeeId,
        public string $insurerCode,
        public string $occurredOn,
        public bool $reportedByEmployer,
        public HealthNotificationDutyRule $rule,
        public ?HealthNotificationDeadlineWindow $deadline,
    ) {}

    public function subjectReference(): string
    {
        return 'employment:' . $this->employmentId;
    }

    public function sourceEventReference(): string
    {
        return sprintf(
            'payroll_health_notification:%d:%s:%s',
            $this->employmentId,
            $this->kind->value,
            $this->occurredOn,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'employment_id' => $this->employmentId,
            'employee_id' => $this->employeeId,
            'insurer_code' => $this->insurerCode,
            'occurred_on' => $this->occurredOn,
            'reported_by_employer' => $this->reportedByEmployer,
            'rule' => $this->rule->toArray(),
            'deadline' => $this->deadline?->toArray(),
        ];
    }
}
