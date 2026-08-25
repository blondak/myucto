<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HealthMinimumReductionInterval
{
    public function __construct(
        public string $from,
        public string $to,
        public HealthMinimumReductionReason $reason,
        public ?string $evidenceReference,
    ) {
        $start = self::date($from);
        $end = self::date($to);
        if ($end < $start) {
            throw new InvalidArgumentException('Health minimum reduction end cannot precede its start.');
        }
        if (
            $reason !== HealthMinimumReductionReason::Unverified
            && $evidenceReference !== null
            && !self::isEvidenceReference($evidenceReference)
        ) {
            throw new InvalidArgumentException(
                'Health minimum reduction evidence reference is not canonical.',
            );
        }
        if ($reason === HealthMinimumReductionReason::Unverified && $evidenceReference !== null) {
            throw new InvalidArgumentException(
                'An unverified health minimum reduction cannot carry verified evidence.',
            );
        }
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Health insurance dates must use YYYY-MM-DD.');
        }

        return $date;
    }

    private static function isEvidenceReference(?string $value): bool
    {
        return $value !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $value) === 1;
    }
}
