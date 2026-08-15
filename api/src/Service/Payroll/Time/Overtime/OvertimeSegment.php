<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Jeden evidovaný kus přesčasu, už převedený na MÍSTNÍ datum a čisté minuty.
 *
 * Datum je datum ZAČÁTKU intervalu v jeho vlastní časové zóně — stejnou
 * konvenci používá zbytek modulu docházky (`PayrollTimeService::startingInPeriod`
 * i JMHZ souhrn), takže se záznam přes půlnoc nikdy nerozpadne do dvou týdnů
 * jinak, než jak ho vidí měsíční přehled.
 */
final readonly class OvertimeSegment
{
    public function __construct(
        public string $date,
        public int $minutes,
    ) {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Datum přesčasu musí být YYYY-MM-DD.');
        }
        if ($minutes < 0) {
            throw new \InvalidArgumentException('Minuty přesčasu nesmí být záporné.');
        }
    }
}
