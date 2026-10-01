<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Service\Payroll\Deadline\PayrollLevyPaymentDate;

/**
 * Datum úhrady mzdové platební dávky.
 *
 * Výchozí datum („podle splatnosti") je poslední den, kdy příkaz ještě splní
 * lhůtu: u čistě odvodové dávky o rezervu na mezibankovní převod dřív než
 * zákonný termín ({@see PayrollLevyPaymentDate}), jinak přímo splatnost
 * závazků. Účetní si může zvolit dřívější datum - zaplatit dřív se smí vždy.
 * Pozdější datum znamená platbu po splatnosti (penále u pojistného, úrok
 * z prodlení u daně a mzdy), proto projde jen s výslovným potvrzením.
 *
 * Minulé datum se odmítá: banka by příkaz se zpětným datem odmítla nebo ho
 * provedla k dnešku a datum v exportu by lhalo.
 */
final class PayrollPaymentDatePolicy
{
    public static function defaultPlannedDate(
        string $statutoryDueOn,
        bool $onlyLevies,
    ): string {
        return $onlyLevies
            ? PayrollLevyPaymentDate::forDueOn($statutoryDueOn)
            : $statutoryDueOn;
    }

    /**
     * @param string|null $requested `null` = podle splatnosti (výchozí chování)
     */
    public static function resolve(
        ?string $requested,
        string $defaultPlannedDate,
        string $today,
        bool $acceptLatePayment,
    ): string {
        if ($requested === null) {
            return $defaultPlannedDate;
        }
        self::assertDate($requested);
        if ($requested < $today) {
            throw new \DomainException(
                'Datum úhrady nemůže být v minulosti. Zvolte dnešní nebo pozdější datum.',
            );
        }
        if ($requested > $defaultPlannedDate && !$acceptLatePayment) {
            throw new PayrollPaymentLateDateException(
                $requested,
                $defaultPlannedDate,
            );
        }

        return $requested;
    }

    public static function assertDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                'Datum úhrady musí mít tvar RRRR-MM-DD.',
            );
        }
    }

    public static function today(\DateTimeInterface $now): string
    {
        return \DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
    }
}
