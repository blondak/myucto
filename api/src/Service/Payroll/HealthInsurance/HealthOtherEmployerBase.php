<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HealthOtherEmployerBase
{
    public function __construct(
        public string $employerReference,
        public int $assessmentBaseMinorUnits,
        public string $employmentFrom,
        public ?string $employmentTo,
        public string $evidenceReference,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $employerReference) !== 1) {
            throw new InvalidArgumentException('Other employer reference is not canonical.');
        }
        if ($assessmentBaseMinorUnits < 0) {
            throw new InvalidArgumentException('Other employer assessment base cannot be negative.');
        }
        $start = self::date($employmentFrom);
        if ($employmentTo !== null && self::date($employmentTo) < $start) {
            throw new InvalidArgumentException('Other employer coverage end cannot precede its start.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $evidenceReference) !== 1) {
            throw new InvalidArgumentException('Other employer base requires canonical evidence.');
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
}
