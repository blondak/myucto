<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Kalendář zdaňovacího/účetního období — čistá hodnota bez DB závislostí.
 *
 * Řeší mapování datum ↔ label období (`fiscal_year`) a hranice období pro
 * kalendářní i hospodářský rok (§21a ZDP). Konvence labelu je shodná se
 * systémem (F4 {@see \MyInvoice\Service\Accounting\Closing\ClosingService}
 * a {@see \MyInvoice\Repository\AccountingPeriodRepository::ensureOpenPeriodFor}):
 * **label = kalendářní rok počátku období** (`year(starts_on)`).
 *
 * Hospodářský rok dle §21a vždy začíná PRVNÍM dnem měsíce jiného než leden,
 * takže `startDay` je pro účely měsíčního bucketingu vždy 1 (žádná nejednoznačnost
 * uvnitř měsíce). Default = kalendářní rok (1. 1.) → chování shodné s dřívějším
 * `substr($date, 0, 4)`, kalendářní poplatníci se tím nemění.
 *
 * `monthIndex` = absolutní index měsíce `rok*12 + (měsíc-1)`, jak jej používají
 * měsíční strategie odpisů.
 */
final class FiscalCalendar
{
    /**
     * @param int $startMonth 1..12 — měsíc počátku období
     * @param int $startDay   1..31 — den počátku (u hospodářského roku vždy 1)
     */
    public function __construct(
        private readonly int $startMonth = 1,
        private readonly int $startDay = 1,
    ) {}

    public static function calendar(): self
    {
        return new self(1, 1);
    }

    /**
     * Odvodí kalendář z hranic období počátku (starts_on ve formátu Y-m-d).
     * Hospodářský rok dle §21a začíná vždy 1. dnem měsíce ≠ leden → `startDay`
     * se normalizuje na 1; leden/neplatné → kalendářní rok.
     */
    public static function fromPeriodStart(string $startsOn): self
    {
        $month = (int) substr($startsOn, 5, 2);
        if ($month < 2 || $month > 12) {
            return self::calendar();
        }
        return new self($month, 1);
    }

    /**
     * Určí režim firmy (kalendářní vs hospodářský rok) dle TVARU období, ne dle
     * jednoho kotevního data. Hledá reprezentativní ~roční hospodářské období
     * (začíná 1. dnem měsíce ≠ leden, končí jiným dnem než 31. 12., délka ~12 měsíců).
     * Zkrácené první období (končící 31. 12.) i kalendářní roky → kalendář.
     *
     * @param list<array<string,mixed>> $periods řádky accounting_periods
     */
    public static function forPeriods(array $periods): self
    {
        foreach ($periods as $p) {
            $starts = (string) ($p['starts_on'] ?? '');
            $ends = (string) ($p['ends_on'] ?? '');
            if (self::isFiscalYearShape($starts, $ends)) {
                return self::fromPeriodStart($starts);
            }
        }
        return self::calendar();
    }

    /** Období je hospodářský rok (ne kalendář, ne zkrácené období končící 31. 12.). */
    public static function isFiscalYearShape(string $startsOn, string $endsOn): bool
    {
        if (strlen($startsOn) < 10 || strlen($endsOn) < 10) {
            return false;
        }
        // Období končící 31. 12. je kalendářní režim (i zkrácený první rok).
        if (substr($endsOn, 5) === '12-31') {
            return false;
        }
        // Musí začínat 1. dnem měsíce ≠ leden.
        if (substr($startsOn, 8, 2) !== '01' || substr($startsOn, 5, 2) === '01') {
            return false;
        }
        // Délka ~12 měsíců (přechodná období mimo → řeší se zvlášť, v2.1).
        $days = (int) (new \DateTimeImmutable($endsOn))->diff(new \DateTimeImmutable($startsOn))->days;
        return $days >= 350 && $days <= 380;
    }

    public function isCalendar(): bool
    {
        return $this->startMonth === 1 && $this->startDay === 1;
    }

    public function startMonth(): int
    {
        return $this->startMonth;
    }

    /** Label (fiscal_year) období, do něhož spadá dané datum (Y-m-d). */
    public function fiscalYearOfDate(string $date): int
    {
        $year = (int) substr($date, 0, 4);
        if ($this->isCalendar()) {
            return $year;
        }
        $boundary = sprintf('%04d-%02d-%02d', $year, $this->startMonth, $this->startDay);
        return $date < $boundary ? $year - 1 : $year;
    }

    /** Label období, do něhož spadá absolutní index měsíce. */
    public function fiscalYearOfMonthIndex(int $monthIndex): int
    {
        $year = intdiv($monthIndex, 12);
        $month = ($monthIndex % 12) + 1;
        return $month < $this->startMonth ? $year - 1 : $year;
    }

    /** První absolutní index měsíce období daného labelu. */
    public function firstMonthIndex(int $fiscalYear): int
    {
        return $fiscalYear * 12 + ($this->startMonth - 1);
    }

    /** Poslední absolutní index měsíce období daného labelu. */
    public function lastMonthIndex(int $fiscalYear): int
    {
        return $this->firstMonthIndex($fiscalYear + 1) - 1;
    }

    /** Počátek období (Y-m-d) daného labelu. */
    public function periodStart(int $fiscalYear): string
    {
        return sprintf('%04d-%02d-%02d', $fiscalYear, $this->startMonth, $this->startDay);
    }

    /** Konec období (Y-m-d) daného labelu = počátek následujícího − 1 den. */
    public function periodEnd(int $fiscalYear): string
    {
        $nextStart = new \DateTimeImmutable($this->periodStart($fiscalYear + 1));
        return $nextStart->modify('-1 day')->format('Y-m-d');
    }
}
