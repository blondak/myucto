<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

final readonly class PayrollTimeInterval
{
    public function __construct(
        public string $startsAtUtc,
        public string $endsAtUtc,
        public string $timezoneName,
        public int $durationMinutes,
    ) {}

    public static function fromIso(
        string $startsAt,
        string $endsAt,
        string $timezoneName,
    ): self {
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('timezone musí být platný IANA název.');
        }

        $start = self::parseInstant($startsAt, 'starts_at');
        $end = self::parseInstant($endsAt, 'ends_at');
        self::assertTimezoneOffset($start, $timezone, 'starts_at', str_ends_with($startsAt, 'Z'));
        self::assertTimezoneOffset($end, $timezone, 'ends_at', str_ends_with($endsAt, 'Z'));

        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0) {
            throw new \InvalidArgumentException(
                'ends_at musí označovat okamžik po starts_at; směna přes půlnoc musí obsahovat další datum.'
            );
        }
        if ($seconds % 60 !== 0) {
            throw new \InvalidArgumentException('Časové intervaly musí být zadány na celé minuty.');
        }
        if ($seconds > 7 * 24 * 3600) {
            throw new \InvalidArgumentException('Jeden časový interval nesmí být delší než 7 dní.');
        }

        $utc = new \DateTimeZone('UTC');
        return new self(
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            $timezoneName,
            intdiv($seconds, 60),
        );
    }

    private static function parseInstant(string $value, string $field): \DateTimeImmutable
    {
        if (!preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D',
            $value,
        )) {
            throw new \InvalidArgumentException(
                "{$field} musí být ISO 8601 včetně data a UTC offsetu."
            );
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException("{$field} není platný časový okamžik.");
        }
    }

    private static function assertTimezoneOffset(
        \DateTimeImmutable $instant,
        \DateTimeZone $timezone,
        string $field,
        bool $isUtcInstant,
    ): void {
        if ($isUtcInstant) {
            return;
        }
        $declaredOffset = $instant->format('P');
        $effectiveOffset = $instant->setTimezone($timezone)->format('P');
        if ($declaredOffset !== $effectiveOffset) {
            throw new \InvalidArgumentException(
                "{$field} má UTC offset {$declaredOffset}, který v timezone {$timezone->getName()} v daném okamžiku neplatí."
            );
        }
    }
}
