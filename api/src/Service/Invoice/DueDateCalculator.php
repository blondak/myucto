<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

final class DueDateCalculator
{
    public static function calculate(string $issueDate, int $value, string $unit): string
    {
        $date = new \DateTimeImmutable($issueDate);
        if ($unit !== 'month') {
            return $date->modify("+{$value} days")->format('Y-m-d');
        }

        $monthIndex = (int) $date->format('Y') * 12
            + (int) $date->format('n') - 1
            + $value;
        $year = intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;
        $lastDay = (int) $date->setDate($year, $month, 1)->format('t');

        return $date
            ->setDate($year, $month, min((int) $date->format('j'), $lastDay))
            ->format('Y-m-d');
    }
}
