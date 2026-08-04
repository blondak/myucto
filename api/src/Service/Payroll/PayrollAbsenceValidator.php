<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollAbsenceValidator
{
    private const TYPES = [
        'vacation', 'dpn', 'quarantine', 'ocr', 'long_term_care', 'ppm',
        'paternity', 'parental', 'unpaid_leave', 'employee_obstacle',
        'employer_obstacle', 'other',
    ];

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function absence(array $body): array
    {
        $employmentId = $this->positiveInt($body, 'employment_id');
        $type = trim((string) ($body['absence_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Druh absence není platný.');
        }
        $from = $this->date($body['date_from'] ?? null, 'date_from');
        $to = $this->date($body['date_to'] ?? null, 'date_to');
        if ($to < $from) {
            throw new \InvalidArgumentException('Konec absence nesmí předcházet začátku.');
        }
        if (!str_starts_with($from, '2026-') || !str_starts_with($to, '2026-')) {
            throw new \InvalidArgumentException(
                'Výpočtová podpora absencí je nyní připnutá pouze k rulesetu 2026.'
            );
        }
        $timezone = trim((string) ($body['timezone_name'] ?? 'Europe/Prague'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Časové pásmo není platné.');
        }
        $policy = match ($type) {
            'dpn', 'quarantine' => 'dpn',
            'vacation' => 'average_100',
            'employee_obstacle', 'employer_obstacle' => 'statutory_manual_review',
            default => 'none',
        };
        if (in_array($policy, ['average_100', 'statutory_manual_review'], true)
            && $this->calendarQuarter($from) !== $this->calendarQuarter($to)
        ) {
            throw new \InvalidArgumentException(
                'Absenci s náhradou mzdy rozděl podle kalendářních čtvrtletí.'
            );
        }
        $averageId = $this->nullablePositiveInt($body['average_snapshot_id'] ?? null, 'average_snapshot_id');
        if (in_array($type, ['dpn', 'quarantine', 'vacation', 'employee_obstacle', 'employer_obstacle'], true)
            && $averageId === null
        ) {
            throw new \InvalidArgumentException('Tento druh absence vyžaduje schválený snapshot průměru.');
        }
        return [
            'employment_id' => $employmentId,
            'absence_type' => $type,
            'date_from' => $from,
            'date_to' => $to,
            'timezone_name' => $timezone,
            'partial_first_minutes' => $this->nullablePositiveInt(
                $body['partial_first_minutes'] ?? null,
                'partial_first_minutes',
            ),
            'partial_last_minutes' => $this->nullablePositiveInt(
                $body['partial_last_minutes'] ?? null,
                'partial_last_minutes',
            ),
            'note' => $this->nullableText($body['note'] ?? null, 1000),
            'compensation_policy' => $policy,
            'compensation_rate_basis_points' => match ($policy) {
                'none' => null,
                'dpn' => 6_000,
                default => 10_000,
            },
            'average_snapshot_id' => $averageId,
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function average(array $body): array
    {
        $year = $this->positiveInt($body, 'applicable_year');
        $quarter = $this->positiveInt($body, 'applicable_quarter');
        if ($year !== 2026 || $quarter > 4) {
            throw new \InvalidArgumentException('Průměr lze nyní založit jen pro ruleset 2026 a čtvrtletí 1–4.');
        }
        $from = $this->date($body['decisive_from'] ?? null, 'decisive_from');
        $to = $this->date($body['decisive_to'] ?? null, 'decisive_to');
        if ($to < $from) {
            throw new \InvalidArgumentException('Rozhodné období průměru není platné.');
        }
        $applicationStart = new \DateTimeImmutable(sprintf(
            '%04d-%02d-01',
            $year,
            (($quarter - 1) * 3) + 1,
        ));
        $expectedFrom = $applicationStart->modify('-3 months')->format('Y-m-d');
        $expectedTo = $applicationStart->modify('-1 day')->format('Y-m-d');
        if ($from !== $expectedFrom || $to !== $expectedTo) {
            throw new \InvalidArgumentException(
                "Rozhodné období pro {$year}/Q{$quarter} musí být {$expectedFrom} až {$expectedTo}."
            );
        }
        return [
            'employment_id' => $this->positiveInt($body, 'employment_id'),
            'applicable_year' => $year,
            'applicable_quarter' => $quarter,
            'decisive_from' => $from,
            'decisive_to' => $to,
            'gross_earnings_minor' => $this->nonNegativeInt($body, 'gross_earnings_minor'),
            'longer_period_allocated_minor' => $this->nonNegativeInt($body, 'longer_period_allocated_minor'),
            'worked_minutes' => $this->nonNegativeInt($body, 'worked_minutes'),
            'worked_days' => $this->nonNegativeInt($body, 'worked_days'),
            'probable_hourly_minor' => $this->nullablePositiveInt(
                $body['probable_hourly_minor'] ?? null,
                'probable_hourly_minor',
            ),
            'rationale' => $this->nullableText($body['rationale'] ?? null, 1000),
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function entitlement(array $body): array
    {
        $rationale = trim((string) ($body['rationale'] ?? ''));
        if ($rationale === '' || mb_strlen($rationale) > 1000) {
            throw new \InvalidArgumentException('Odůvodnění nároku je povinné a smí mít nejvýše 1000 znaků.');
        }
        $year = $this->positiveInt($body, 'leave_year');
        if ($year !== 2026) {
            throw new \InvalidArgumentException('Nárok dovolené lze nyní založit jen pro ruleset 2026.');
        }
        return [
            'employment_id' => $this->positiveInt($body, 'employment_id'),
            'leave_year' => $year,
            'weekly_minutes' => $this->positiveInt($body, 'weekly_minutes'),
            'entitlement_weeks' => $this->positiveInt($body, 'entitlement_weeks'),
            'continuous_calendar_days' => $this->positiveInt($body, 'continuous_calendar_days'),
            'worked_equivalent_minutes' => $this->positiveInt($body, 'worked_equivalent_minutes'),
            'rationale' => $rationale,
        ];
    }

    private function date(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new \InvalidArgumentException("{$field} musí být platné datum YYYY-MM-DD.");
        }
        return $text;
    }

    /** @param array<string,mixed> $body */
    private function positiveInt(array $body, string $field): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$field} musí být kladné celé číslo.");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $body */
    private function nonNegativeInt(array $body, string $field): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$field} musí být nezáporné celé číslo.");
        }
        return (int) $value;
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new \InvalidArgumentException("{$field} musí být kladné celé číslo.");
        }
        return (int) $result;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new \InvalidArgumentException("Text smí mít nejvýše {$max} znaků.");
        }
        return $text;
    }

    private function calendarQuarter(string $date): string
    {
        $value = new \DateTimeImmutable($date);
        return $value->format('Y') . '-Q' . (intdiv((int) $value->format('n') - 1, 3) + 1);
    }
}
