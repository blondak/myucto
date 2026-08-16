<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Jeden nález nad limity § 93. Nese jen fakta — do věty pro uživatele ho
 * převádí {@see OvertimeLimitEvaluator}, do validace mzdového běhu
 * {@see PayrollOvertimeLimitService}.
 */
final readonly class OvertimeLimitFinding
{
    public const CODE_WEEKLY = 'overtime_ordered_weekly_limit_exceeded';
    public const CODE_YEARLY = 'overtime_ordered_annual_limit_exceeded';
    public const CODE_AVERAGING = 'overtime_averaging_period_limit_exceeded';
    public const CODE_YEARLY_APPROACHING = 'overtime_annual_limit_approaching';

    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public int $actualMinutes,
        public int $limitMinutes,
        public string $scopeFrom,
        public string $scopeTo,
        public bool $consentEvidenced,
    ) {
        if (!in_array($severity, ['warning', 'info'], true)) {
            throw new \InvalidArgumentException(
                'Nález limitu přesčasu smí být jen varování nebo informace.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'actual_minutes' => $this->actualMinutes,
            'limit_minutes' => $this->limitMinutes,
            'scope_from' => $this->scopeFrom,
            'scope_to' => $this->scopeTo,
            'consent_evidenced' => $this->consentEvidenced,
        ];
    }
}
