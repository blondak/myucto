<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;

/**
 * Jediné místo, které převádí pořadí dítěte (§ 35c odst. 1 ZDP) na klíč sazby.
 * Volá ho jak měsíční výpočet, tak evidence vyživovaných osob — kdyby si každá
 * strana držela vlastní match, rozejdou se při první změně pořadí sazeb.
 */
final class ChildCreditRateKey
{
    public const FIRST = 'credit.child.first.monthly';
    public const SECOND = 'credit.child.second.monthly';
    public const THIRD_AND_NEXT = 'credit.child.third_and_next.monthly';

    public static function forOrder(int $order): string
    {
        if ($order < 1) {
            throw new InvalidArgumentException('Pořadí dítěte musí být kladné.');
        }

        return match ($order) {
            1 => self::FIRST,
            2 => self::SECOND,
            default => self::THIRD_AND_NEXT,
        };
    }
}
