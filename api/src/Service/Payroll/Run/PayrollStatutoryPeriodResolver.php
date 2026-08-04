<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final class PayrollStatutoryPeriodResolver
{
    public function resolve(
        string $periodStart,
        string $paymentDate,
    ): PayrollStatutoryPeriod {
        $period = $this->date($periodStart, 'Mzdové období');
        if ($period->format('d') !== '01') {
            throw new \InvalidArgumentException(
                'Mzdové období musí začínat prvním dnem měsíce.',
            );
        }
        $payment = $this->date($paymentDate, 'Datum výplaty');
        if ($payment < $period) {
            throw new \InvalidArgumentException(
                'Datum výplaty nesmí předcházet mzdovému období.',
            );
        }
        $periodEnd = $period->modify('last day of this month')->format('Y-m-d');

        return new PayrollStatutoryPeriod(
            $periodStart,
            $periodEnd,
            $paymentDate,
            $periodEnd,
            $periodEnd,
            $periodEnd,
        );
    }

    private function date(string $value, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                "{$label} musí být datum YYYY-MM-DD.",
            );
        }

        return $date;
    }
}
