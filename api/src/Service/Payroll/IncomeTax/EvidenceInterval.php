<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use DateTimeImmutable;
use InvalidArgumentException;

final class EvidenceInterval
{
    public static function assertValid(
        string $effectiveFrom,
        ?string $effectiveTo,
        TaxEvidenceStatus $status,
        ?string $evidenceReference,
    ): void {
        $from = self::date($effectiveFrom);
        if ($effectiveTo !== null && self::date($effectiveTo) < $from) {
            throw new InvalidArgumentException('Tax evidence effective interval is not ordered.');
        }
    }

    public static function includesMonthStart(
        string $effectiveFrom,
        ?string $effectiveTo,
        string $calculationDate,
    ): bool {
        $monthStart = self::date(substr($calculationDate, 0, 7) . '-01');

        return self::date($effectiveFrom) <= $monthStart
            && ($effectiveTo === null || self::date($effectiveTo) >= $monthStart);
    }

    public static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Invalid tax date {$value}.");
        }

        return $date;
    }
}
