<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Náhradní volno poskytnuté za práci přesčas (§ 93 odst. 5 zákoníku práce).
 *
 * `date` je den, na který PŘESČAS připadl — ne den, kdy si zaměstnanec volno
 * vybral. Zákon vyjímá z vyrovnávacího období „práci přesčas, za kterou bylo
 * poskytnuto náhradní volno", takže se odečítá na straně přesčasu.
 */
final readonly class OvertimeCompensation
{
    public function __construct(
        public string $date,
        public int $minutes,
    ) {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Datum přesčasu musí být YYYY-MM-DD.');
        }
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('Náhradní volno musí být kladné.');
        }
    }
}
