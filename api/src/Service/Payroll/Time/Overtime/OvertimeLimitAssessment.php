<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Výsledek posouzení limitů § 93 pro jeden pracovní vztah a jedno mzdové období.
 *
 * Kromě nálezů nese i holá čísla, aby docházka mohla ukázat stav i tehdy, když
 * se ještě nic nepřekročilo — smysl kontroly je přijít na to VČAS, ne až po
 * překročení.
 */
final readonly class OvertimeLimitAssessment
{
    /**
     * @param list<OvertimeLimitFinding> $findings
     * @param list<array{week_start:string,week_end:string,minutes:int}> $weeks
     */
    public function __construct(
        public int $employmentId,
        public array $findings,
        public array $weeks,
        public int $orderedYearMinutes,
        public int $orderedYearLimitMinutes,
        public int $agreedYearMinutes,
        public ?string $averagingFrom,
        public ?string $averagingTo,
        public int $averagingWeeks,
        public int $averagingMinutes,
        public int $averagingLimitMinutes,
        public bool $consentEvidenced,
        public bool $limitsFromRuleset,
    ) {}

    public function hasWarnings(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === 'warning') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'employment_id' => $this->employmentId,
            'findings' => array_map(
                static fn (OvertimeLimitFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'weeks' => $this->weeks,
            'ordered_year_minutes' => $this->orderedYearMinutes,
            'ordered_year_limit_minutes' => $this->orderedYearLimitMinutes,
            'agreed_year_minutes' => $this->agreedYearMinutes,
            'averaging_from' => $this->averagingFrom,
            'averaging_to' => $this->averagingTo,
            'averaging_weeks' => $this->averagingWeeks,
            'averaging_minutes' => $this->averagingMinutes,
            'averaging_limit_minutes' => $this->averagingLimitMinutes,
            'consent_evidenced' => $this->consentEvidenced,
            'limits_from_ruleset' => $this->limitsFromRuleset,
        ];
    }
}
