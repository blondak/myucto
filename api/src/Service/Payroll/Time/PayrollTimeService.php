<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;

final class PayrollTimeService
{
    private const CATEGORIES = [
        'regular',
        'overtime',
        'night',
        'weekend',
        'holiday',
        'difficult_environment',
    ];

    public function __construct(
        private readonly PayrollTimeRepository $repository,
        private readonly PayrollCalendarFundService $fund,
        private readonly PayrollJmhzWorkMonthSummaryBuilder $jmhzWorkSummary,
    ) {}

    /** @return array<string,mixed> */
    public function overview(int $supplierId, string $period, bool $incompleteOnly): array
    {
        [$periodStart, $periodEnd, $startsAtUtc, $endsAtUtc] = $this->periodBounds($period);
        $employments = $this->repository->employments($supplierId, $periodStart, $periodEnd);
        $shifts = $this->groupByEmployment(
            $this->startingInPeriod(
                $this->repository->shifts($supplierId, $startsAtUtc, $endsAtUtc),
                $period,
            ),
        );
        $entries = $this->groupByEmployment(
            $this->startingInPeriod(
                $this->repository->entries($supplierId, $startsAtUtc, $endsAtUtc),
                $period,
            ),
        );
        $states = [];
        foreach ($this->repository->monthStates($supplierId, $periodStart) as $state) {
            $states[PayrollTimeValue::int($state['employment_id'] ?? null, 'employment_id')] = $state;
        }

        $items = [];
        foreach ($employments as $employment) {
            $employmentId = PayrollTimeValue::int($employment['id'] ?? null, 'id');
            $calendarVersions = $this->repository->calendars(
                $supplierId,
                $employmentId,
                $periodStart,
                $periodEnd,
            );
            $calendarDto = null;
            $fundMinutes = 0;
            if ($calendarVersions !== []) {
                $combinedDays = [];
                foreach ($calendarVersions as $version) {
                    $overrides = $this->repository->calendarDays(
                        $supplierId,
                        PayrollTimeValue::int($version['id'] ?? null, 'id'),
                        $periodStart,
                        $periodEnd,
                    );
                    $calendarMonth = $this->fund->month(
                        $period,
                        $this->weekPattern($version['week_pattern'] ?? null),
                        $overrides,
                    );
                    foreach ($calendarMonth['days'] as $day) {
                        $date = PayrollTimeValue::string($day['date'] ?? null, 'date');
                        $validFrom = PayrollTimeValue::string(
                            $version['valid_from'] ?? null,
                            'valid_from',
                        );
                        if ($date < $validFrom) {
                            continue;
                        }
                        $validTo = $version['valid_to'] ?? null;
                        if ($validTo !== null
                            && $date > PayrollTimeValue::string($validTo, 'valid_to')
                        ) {
                            continue;
                        }
                        $combinedDays[$date] = $day;
                    }
                }
                ksort($combinedDays);
                foreach ($combinedDays as $day) {
                    $fundMinutes += PayrollTimeValue::int(
                        $day['planned_minutes'] ?? null,
                        'planned_minutes',
                    );
                }
                $latestCalendar = $calendarVersions[array_key_last($calendarVersions)];
                $calendarDto = $latestCalendar + [
                    'fund_minutes' => $fundMinutes,
                    'days' => array_values($combinedDays),
                    'versions' => array_map(
                        static fn (array $version): array => [
                            'id' => $version['id'],
                            'name' => $version['name'],
                            'valid_from' => $version['valid_from'],
                            'valid_to' => $version['valid_to'],
                            'row_version' => $version['row_version'],
                        ],
                        $calendarVersions,
                    ),
                ];
            }

            $employmentShifts = $shifts[$employmentId] ?? [];
            $employmentEntries = $entries[$employmentId] ?? [];
            $plannedMinutes = 0;
            $hasDraftShift = false;
            foreach ($employmentShifts as &$shift) {
                $minutes = $this->netMinutes($shift);
                $shift['net_minutes'] = $minutes;
                $shift['starts_at'] = $this->displayInstant($shift, 'starts_at_utc');
                $shift['ends_at'] = $this->displayInstant($shift, 'ends_at_utc');
                $plannedMinutes += $minutes;
                $hasDraftShift = $hasDraftShift || $shift['status'] === 'draft';
            }
            unset($shift);

            $categories = array_fill_keys(self::CATEGORIES, 0);
            $hasDraftEntry = false;
            foreach ($employmentEntries as &$entry) {
                $minutes = $this->netMinutes($entry);
                $entry['net_minutes'] = $minutes;
                $entry['starts_at'] = $this->displayInstant($entry, 'starts_at_utc');
                $entry['ends_at'] = $this->displayInstant($entry, 'ends_at_utc');
                $category = PayrollTimeValue::string($entry['category'] ?? null, 'category');
                if (array_key_exists($category, $categories)) {
                    $categories[$category] += $minutes;
                }
                $hasDraftEntry = $hasDraftEntry || $entry['status'] === 'draft';
            }
            unset($entry);
            $actualMinutes = $categories['regular'] + $categories['overtime'];
            $incomplete = $calendarVersions === []
                || $hasDraftShift
                || $hasDraftEntry
                || ($plannedMinutes > 0 && $actualMinutes === 0);

            $state = $states[$employmentId] ?? [
                'id' => null,
                'supplier_id' => $supplierId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'status' => 'open',
                'revision_no' => 1,
                'row_version' => 0,
                'approved_at' => null,
                'reopened_at' => null,
                'reopen_reason' => null,
            ];
            $jmhzRevision = $this->repository->jmhzWorkSummaryRevision(
                $supplierId,
                $employmentId,
                $periodStart,
            );
            $item = [
                'employment' => $employment,
                'calendar' => $calendarDto,
                'month' => $state,
                'summary' => [
                    'fund_minutes' => $fundMinutes,
                    'planned_minutes' => $plannedMinutes,
                    'actual_minutes' => $actualMinutes,
                    'difference_minutes' => $actualMinutes - $plannedMinutes,
                    'category_minutes' => $categories,
                    'incomplete' => $incomplete,
                ],
                'jmhz_work_summary' => [
                    'preview' => $state['status'] === 'open'
                        ? $this->publicJmhzPreview(
                            $this->jmhzWorkSummary->preview(
                                $supplierId,
                                $employmentId,
                                $periodStart,
                            ),
                        )
                        : null,
                    'current_revision' => $jmhzRevision,
                ],
                'shifts' => $employmentShifts,
                'entries' => $employmentEntries,
            ];
            if (!$incompleteOnly || $incomplete) {
                $items[] = $item;
            }
        }

        return [
            'period' => $period,
            'incomplete_only' => $incompleteOnly,
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createCalendar(
        int $supplierId,
        int $employmentId,
        array $input,
        ?int $userId,
    ): array {
        $name = $this->requiredString($input, 'name', 191);
        $timezone = $this->timezone($input['timezone'] ?? 'Europe/Prague');
        $scheduleType = $this->enum(
            $input,
            'schedule_type',
            ['regular', 'irregular', 'shift'],
        );
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('valid_to nesmí předcházet valid_from.');
        }
        $expectedVersion = $this->nonNegativeInt($input, 'row_version');
        $patternInput = $input['week_pattern'] ?? null;
        if (!is_array($patternInput)) {
            throw new \InvalidArgumentException('week_pattern musí obsahovat minuty pro dny 1 až 7.');
        }
        $pattern = [];
        $weeklyMinutes = 0;
        for ($day = 1; $day <= 7; ++$day) {
            $raw = $patternInput[(string) $day] ?? $patternInput[$day] ?? 0;
            if (!is_int($raw) || $raw < 0 || $raw > 1440) {
                throw new \InvalidArgumentException(
                    "week_pattern.{$day} musí být počet minut 0 až 1440."
                );
            }
            $pattern[$day] = $raw;
            $weeklyMinutes += $raw;
        }
        $days = $this->calendarDays($input['days'] ?? []);

        return $this->repository->createCalendarVersion(
            $supplierId,
            $employmentId,
            $name,
            $timezone,
            $scheduleType,
            $pattern,
            $weeklyMinutes,
            $validFrom,
            $validTo,
            $expectedVersion,
            $this->nonNegativeInt($input, 'month_row_version'),
            $days,
            $userId,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array{shift:array<string,mixed>,month:array<string,mixed>}
     */
    public function saveShift(
        int $supplierId,
        array $input,
        ?int $userId,
    ): array {
        $employmentId = $this->positiveInt($input, 'employment_id');
        $timezone = $this->timezone($input['timezone'] ?? null);
        $interval = PayrollTimeInterval::fromIso(
            $this->requiredString($input, 'starts_at', 40),
            $this->requiredString($input, 'ends_at', 40),
            $timezone,
        );
        $breakMinutes = $this->nonNegativeInt($input, 'break_minutes');
        if ($breakMinutes >= $interval->durationMinutes) {
            throw new \InvalidArgumentException('Přestávka musí být kratší než směna.');
        }
        $standbyMinutes = $this->nonNegativeInt($input, 'standby_minutes');
        if ($standbyMinutes > $interval->durationMinutes) {
            throw new \InvalidArgumentException('Pohotovost nesmí přesáhnout délku směny.');
        }
        $publish = $this->bool($input, 'publish');
        $localStart = (new \DateTimeImmutable($this->requiredString($input, 'starts_at', 40)))
            ->setTimezone(new \DateTimeZone($timezone));
        $periodStart = $localStart->format('Y-m-01');

        return $this->repository->saveShift(
            $supplierId,
            $employmentId,
            $periodStart,
            $interval->startsAtUtc,
            $interval->endsAtUtc,
            $interval->timezoneName,
            $breakMinutes,
            $this->bool($input, 'remote_work'),
            $standbyMinutes,
            $publish,
            $this->nullablePositiveInt($input, 'supersedes_id'),
            $this->nullablePositiveInt($input, 'calendar_id'),
            $this->nonNegativeInt($input, 'row_version'),
            $this->nonNegativeInt($input, 'month_row_version'),
            $userId,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array{entry:array<string,mixed>,month:array<string,mixed>}
     */
    public function saveEntry(
        int $supplierId,
        array $input,
        ?int $userId,
        string $sourceKind = 'manual',
        ?string $sourceReference = null,
        ?string $sourceHash = null,
    ): array {
        $employmentId = $this->positiveInt($input, 'employment_id');
        $category = $this->enum($input, 'category', self::CATEGORIES);
        $timezone = $this->timezone($input['timezone'] ?? null);
        $interval = PayrollTimeInterval::fromIso(
            $this->requiredString($input, 'starts_at', 40),
            $this->requiredString($input, 'ends_at', 40),
            $timezone,
        );
        $breakMinutes = $this->nonNegativeInt($input, 'break_minutes');
        if ($breakMinutes >= $interval->durationMinutes) {
            throw new \InvalidArgumentException('Přestávka musí být kratší než časový interval.');
        }
        $localStart = (new \DateTimeImmutable($this->requiredString($input, 'starts_at', 40)))
            ->setTimezone(new \DateTimeZone($timezone));
        $periodStart = $localStart->format('Y-m-01');
        if ($sourceKind === 'manual') {
            $sourceHash = random_bytes(32);
        }
        if ($sourceHash === null || strlen($sourceHash) !== 32) {
            throw new \InvalidArgumentException('Interní deduplikační hash času není platný.');
        }

        return $this->repository->saveEntry(
            $supplierId,
            $employmentId,
            $periodStart,
            $category,
            $interval->startsAtUtc,
            $interval->endsAtUtc,
            $interval->timezoneName,
            $breakMinutes,
            $sourceKind,
            $sourceReference,
            $sourceHash,
            $this->nullablePositiveInt($input, 'supersedes_id'),
            $this->nonNegativeInt($input, 'row_version'),
            $this->nonNegativeInt($input, 'month_row_version'),
            $userId,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function approve(
        int $supplierId,
        string $period,
        array $input,
        ?int $userId,
    ): array {
        [$periodStart] = $this->periodBounds($period);
        $jmhzWorkSummary = null;
        if (array_key_exists('jmhz_work_summary', $input)) {
            if (!is_array($input['jmhz_work_summary'])) {
                throw new \InvalidArgumentException(
                    'Pracovní souhrn JMHZ musí být objekt.',
                );
            }
            $jmhzWorkSummary = $input['jmhz_work_summary'];
        }
        return $this->repository->approveMonth(
            $supplierId,
            $this->positiveInt($input, 'employment_id'),
            $periodStart,
            $this->nonNegativeInt($input, 'row_version'),
            $jmhzWorkSummary,
            $userId,
        );
    }

    /**
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function publicJmhzPreview(array $preview): array
    {
        unset($preview['source_snapshot_json']);
        return $preview;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function reopen(
        int $supplierId,
        string $period,
        array $input,
        ?int $userId,
    ): array {
        [$periodStart] = $this->periodBounds($period);
        $reason = $this->requiredString($input, 'reason', 500);
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('Důvod znovuotevření musí mít alespoň 5 znaků.');
        }
        return $this->repository->reopenMonth(
            $supplierId,
            $this->positiveInt($input, 'employment_id'),
            $periodStart,
            $this->nonNegativeInt($input, 'row_version'),
            $reason,
            $userId,
        );
    }

    /** @return array{string,string,string,string} */
    public function periodBounds(string $period): array
    {
        $start = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $period . '-01',
            new \DateTimeZone('Europe/Prague'),
        );
        if ($start === false || $start->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('period musí být ve formátu YYYY-MM.');
        }
        $end = $start->modify('first day of next month');
        $utc = new \DateTimeZone('UTC');
        return [
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,list<array<string,mixed>>>
     */
    private function groupByEmployment(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $employmentId = PayrollTimeValue::int(
                $row['employment_id'] ?? null,
                'employment_id',
            );
            $grouped[$employmentId][] = $row;
        }
        return $grouped;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function startingInPeriod(array $rows, string $period): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => substr(
                $this->displayInstant($row, 'starts_at_utc'),
                0,
                7,
            ) === $period,
        ));
    }

    /** @param array<string,mixed> $row */
    private function netMinutes(array $row): int
    {
        $start = new \DateTimeImmutable(
            PayrollTimeValue::string($row['starts_at_utc'] ?? null, 'starts_at_utc'),
            new \DateTimeZone('UTC'),
        );
        $end = new \DateTimeImmutable(
            PayrollTimeValue::string($row['ends_at_utc'] ?? null, 'ends_at_utc'),
            new \DateTimeZone('UTC'),
        );
        return max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60)
            - PayrollTimeValue::int($row['break_minutes'] ?? 0, 'break_minutes'));
    }

    /** @param array<string,mixed> $row */
    private function displayInstant(array $row, string $field): string
    {
        $utc = new \DateTimeImmutable(
            PayrollTimeValue::string($row[$field] ?? null, $field),
            new \DateTimeZone('UTC'),
        );
        return $utc->setTimezone(new \DateTimeZone(
            PayrollTimeValue::string($row['timezone_name'] ?? null, 'timezone_name'),
        ))
            ->format('Y-m-d\TH:i:sP');
    }

    /** @return array<int,int> */
    private function weekPattern(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('week_pattern musí být objekt.');
        }
        $result = [];
        foreach ($value as $day => $minutes) {
            if (!is_int($day) || !is_int($minutes)) {
                throw new \UnexpectedValueException('week_pattern obsahuje neplatnou hodnotu.');
            }
            $result[$day] = $minutes;
        }
        return $result;
    }

    /**
     * @param mixed $raw
     * @return list<array{
     *   day_date:string,
     *   day_kind:string,
     *   planned_minutes:int,
     *   holiday_code:?string,
     *   holiday_name:?string,
     *   note:?string
     * }>
     */
    private function calendarDays(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('days musí být pole kalendářních výjimek.');
        }
        $result = [];
        $seen = [];
        foreach ($raw as $index => $day) {
            if (!is_array($day)) {
                throw new \InvalidArgumentException("days.{$index} není platný objekt.");
            }
            $dayInput = [];
            foreach ($day as $key => $value) {
                if (is_string($key)) {
                    $dayInput[$key] = $value;
                }
            }
            $date = $this->date($dayInput['day_date'] ?? null, "days.{$index}.day_date");
            if (isset($seen[$date])) {
                throw new \InvalidArgumentException("Datum {$date} je v kalendáři uvedeno vícekrát.");
            }
            $seen[$date] = true;
            $kind = $this->enum(
                $dayInput,
                'day_kind',
                ['workday', 'non_working', 'holiday'],
            );
            $minutes = $this->nonNegativeInt($dayInput, 'planned_minutes');
            if ($minutes > 1440) {
                throw new \InvalidArgumentException('planned_minutes nesmí být vyšší než 1440.');
            }
            $holidayCode = $this->nullableString($dayInput['holiday_code'] ?? null, 32);
            $holidayName = $this->nullableString($dayInput['holiday_name'] ?? null, 191);
            if ($kind === 'holiday' && ($holidayCode === null || $holidayName === null)) {
                throw new \InvalidArgumentException('Vlastní svátek musí mít kód a název.');
            }
            $result[] = [
                'day_date' => $date,
                'day_kind' => $kind,
                'planned_minutes' => $minutes,
                'holiday_code' => $holidayCode,
                'holiday_name' => $holidayName,
                'note' => $this->nullableString($dayInput['note'] ?? null, 255),
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("{$key} je povinné.");
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException("{$key} je příliš dlouhé.");
        }
        return $value;
    }

    private function nullableString(mixed $raw, int $max): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Textová hodnota není platná.');
        }
        $value = trim($raw);
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException('Textová hodnota je příliš dlouhá.');
        }
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $allowed
     */
    private function enum(array $input, string $key, array $allowed): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "{$key} musí být jedna z hodnot: " . implode(', ', $allowed) . '.'
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function positiveInt(array $input, string $key): int
    {
        $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$key} musí být kladné celé číslo.");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function nullablePositiveInt(array $input, string $key): ?int
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return null;
        }
        return $this->positiveInt($input, $key);
    }

    /** @param array<string,mixed> $input */
    private function nonNegativeInt(array $input, string $key): int
    {
        $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$key} musí být nezáporné celé číslo.");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$key} musí být boolean.");
        }
        return $value;
    }

    private function timezone(mixed $raw): string
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

    private function date(mixed $raw, string $field): string
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException("{$field} musí být datum YYYY-MM-DD.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException("{$field} musí být datum YYYY-MM-DD.");
        }
        return $raw;
    }

    private function nullableDate(mixed $raw, string $field): ?string
    {
        return $raw === null || $raw === '' ? null : $this->date($raw, $field);
    }
}
