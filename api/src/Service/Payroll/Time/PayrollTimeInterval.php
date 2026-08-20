<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

/**
 * SSOT pro převod „uživatelem zadaný okamžik + IANA zóna" na uložitelný UTC
 * instant. Používá ji docházka (směna, odpracovaný čas) i evidence pracovních
 * cest — jediný rozdíl je strop délky intervalu, protože směna delší než týden
 * je překlep, kdežto pracovní cesta na tři týdny je běžná.
 */
final readonly class PayrollTimeInterval
{
    /** Strop pro směnu a odpracovaný čas. */
    public const MAX_DAYS_SHIFT = 7;

    public function __construct(
        public string $startsAtUtc,
        public string $endsAtUtc,
        public string $timezoneName,
        public int $durationMinutes,
    ) {}

    /**
     * Povinná IANA zóna z požadavku. SSOT — volá ji docházka i evidence
     * pracovních cest, aby „timezone" znamenalo v obou agendách totéž.
     */
    public static function timezoneName(mixed $raw): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new \InvalidArgumentException('timezone je povinné.');
        }
        try {
            return (new \DateTimeZone(trim($raw)))->getName();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('timezone musí být platný IANA název.');
        }
    }

    public static function fromIso(
        string $startsAt,
        string $endsAt,
        string $timezoneName,
        int $maxDays = self::MAX_DAYS_SHIFT,
        string $startField = 'starts_at',
        string $endField = 'ends_at',
    ): self {
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('timezone musí být platný IANA název.');
        }

        $start = self::parseInstant($startsAt, $startField);
        $end = self::parseInstant($endsAt, $endField);
        self::assertTimezoneOffset($start, $timezone, $startField, str_ends_with($startsAt, 'Z'));
        self::assertTimezoneOffset($end, $timezone, $endField, str_ends_with($endsAt, 'Z'));

        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0) {
            throw new \InvalidArgumentException(
                "{$endField} musí označovat okamžik po {$startField}; interval přes půlnoc musí obsahovat další datum."
            );
        }
        if ($seconds % 60 !== 0) {
            throw new \InvalidArgumentException('Časové intervaly musí být zadány na celé minuty.');
        }
        if ($maxDays < 1) {
            throw new \InvalidArgumentException('Strop délky intervalu musí být aspoň 1 den.');
        }
        if ($seconds > $maxDays * 24 * 3600) {
            throw new \InvalidArgumentException(
                "Jeden časový interval nesmí být delší než {$maxDays} dní."
            );
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
