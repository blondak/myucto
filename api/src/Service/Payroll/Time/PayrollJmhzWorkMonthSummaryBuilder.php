<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PDO;

final class PayrollJmhzWorkMonthSummaryBuilder
{
    public const DERIVATION_VERSION = 'jmhz-work-month-core.v1';

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> */
    public function preview(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        bool $lockSources = false,
    ): array
    {
        $period = self::period($periodStart);
        $periodEnd = $period->modify('first day of next month');
        $employment = $this->employment(
            $supplierId,
            $employmentId,
            $periodStart,
            $lockSources,
        );
        $calendars = $this->calendars(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd->format('Y-m-d'),
            $lockSources,
        );
        $entries = $this->entries(
            $supplierId,
            $employmentId,
            $periodStart,
            $lockSources,
        );
        $absences = $this->absences(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd->format('Y-m-d'),
            $lockSources,
        );
        [$evidenceFrom, $evidenceTo, $evidenceDays] = self::evidenceInterval(
            $employment,
            $period,
            $periodEnd,
        );
        [$agreedMinutes, $calendarIssues] = self::agreedFundMinutes(
            $calendars,
            $evidenceFrom,
            $evidenceTo,
        );
        $employmentIssues = ($employment['term_values_consistent'] ?? false) === true
            ? []
            : [[
                'code' => 'employment_terms_not_unique_for_month',
                'message' => 'Měsíc nemá jedinou konzistentní verzi týdenní pracovní doby.',
            ]];
        [$workedMinutes, $entryIssues] = self::workedMinutes($entries, $periodStart);
        $source = [
            'schema_version' => self::DERIVATION_VERSION,
            'specification' => self::specification(),
            'supplier_id' => $supplierId,
            'employment' => $employment,
            'period_start' => $periodStart,
            'calendars' => $calendars,
            'time_entries' => $entries,
            'absences' => $absences,
        ];
        $sourceJson = CanonicalJson::encode($source);

        return [
            'derivation_version' => self::DERIVATION_VERSION,
            'source_snapshot_json' => $sourceJson,
            'source_snapshot_sha256' => hash('sha256', $sourceJson),
            'suggestions' => [
                'standard_fund_hours' => null,
                'agreed_fund_hours' => self::minutesSuggestion($agreedMinutes),
                'weekly_work_hours' => $employment['weekly_hours'],
                'evidence_days' => $evidenceDays,
                'worked_hours' => self::minutesSuggestion($workedMinutes),
            ],
            'issues' => array_merge($employmentIssues, $calendarIssues, $entryIssues),
            'requires_unworked_hours_followup' => $absences !== [],
        ];
    }

    /**
     * @param array<string,mixed> $preview
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function confirm(array $preview, array $input): array
    {
        $expectedHash = $input['source_snapshot_sha256'] ?? null;
        if (!is_string($expectedHash)
            || preg_match('/^[0-9a-f]{64}$/D', $expectedHash) !== 1
            || !hash_equals((string) $preview['source_snapshot_sha256'], $expectedHash)
        ) {
            throw new PayrollJmhzWorkSummaryConflictException();
        }
        if (($preview['issues'] ?? []) !== []) {
            throw new \InvalidArgumentException(
                'Pracovní souhrn obsahuje neúplné nebo nejednoznačné zdroje.',
            );
        }
        $values = [
            'standard_fund_millihours' => self::scaledDecimal(
                $input['standard_fund_hours'] ?? null,
                'standard_fund_hours',
                3,
                7,
            ),
            'agreed_fund_millihours' => self::scaledDecimal(
                $input['agreed_fund_hours'] ?? null,
                'agreed_fund_hours',
                3,
                7,
            ),
            'weekly_work_centihours' => self::scaledDecimal(
                $input['weekly_work_hours'] ?? null,
                'weekly_work_hours',
                2,
                7,
            ),
            'evidence_days' => self::nonNegativeInt(
                $preview['suggestions']['evidence_days'] ?? null,
                'evidence_days',
            ),
            'worked_millihours' => self::scaledDecimal(
                $input['worked_hours'] ?? null,
                'worked_hours',
                3,
                8,
            ),
        ];
        $note = $input['confirmation_note'] ?? null;
        if (!is_string($note) || mb_strlen(trim($note)) < 5 || mb_strlen(trim($note)) > 500) {
            throw new \InvalidArgumentException(
                'Poznámka ke zdroji potvrzených hodnot musí mít 5 až 500 znaků.',
            );
        }
        $note = trim($note);
        $provenance = [
            'attributes' => [
                '10259' => 'explicit_confirmation',
                '10260' => 'explicit_confirmation_with_calendar_suggestion',
                '10261' => 'explicit_confirmation_with_term_suggestion',
                '10265' => 'employment_interval_derivation',
                '10268' => 'explicit_confirmation_with_time_entry_suggestion',
            ],
            'suggestions' => $preview['suggestions'],
            'requires_unworked_hours_followup' =>
                (bool) ($preview['requires_unworked_hours_followup'] ?? false),
            'decimal_policy' => 'exact_user_confirmed_value_without_rounding',
        ];
        $summaryPayload = [
            'derivation_version' => self::DERIVATION_VERSION,
            'specification' => self::specification(),
            'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
            'values' => $values,
            'provenance' => $provenance,
            'confirmation_note' => $note,
        ];

        return $summaryPayload + [
            'source_snapshot_json' => $preview['source_snapshot_json'],
            'summary_sha256' => hash('sha256', CanonicalJson::encode($summaryPayload)),
        ];
    }

    /** @return array<string,mixed> */
    private function employment(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        bool $lockSources,
    ): array
    {
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.supplier_id, employment.relation_type,
                    employment.start_date, employment.actual_start_date, employment.end_date,
                    terms.id AS term_id, terms.row_version AS term_row_version,
                    terms.effective_from AS term_effective_from,
                    terms.effective_to AS term_effective_to, terms.weekly_hours
               FROM payroll_employments employment
               LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= LAST_DAY(?)
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ? AND employment.id = ?
              ORDER BY terms.effective_from, terms.id' . $lock
        );
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('first day of next month')
            ->modify('-1 day')
            ->format('Y-m-d');
        $stmt->execute([$periodEnd, $periodStart, $supplierId, $employmentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
        $termVersions = [];
        $weeklyHours = [];
        foreach ($rows as $termRow) {
            if ($termRow['term_id'] === null) {
                continue;
            }
            $term = [
                'id' => (int) $termRow['term_id'],
                'row_version' => (int) $termRow['term_row_version'],
                'effective_from' => (string) $termRow['term_effective_from'],
                'effective_to' => $termRow['term_effective_to'],
                'weekly_hours' => $termRow['weekly_hours'] === null
                    ? null
                    : (string) $termRow['weekly_hours'],
            ];
            $termVersions[] = $term;
            $weeklyHours[json_encode($term['weekly_hours'], JSON_THROW_ON_ERROR)] =
                $term['weekly_hours'];
        }
        $termValuesConsistent = count($weeklyHours) <= 1;
        return [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'relation_type' => (string) $row['relation_type'],
            'start_date' => $row['start_date'],
            'actual_start_date' => $row['actual_start_date'],
            'end_date' => $row['end_date'],
            'term_id' => count($termVersions) === 1 ? $termVersions[0]['id'] : null,
            'term_row_version' => count($termVersions) === 1
                ? $termVersions[0]['row_version']
                : null,
            'weekly_hours' => $termVersions !== [] && $termValuesConsistent
                ? $termVersions[0]['weekly_hours']
                : null,
            'term_values_consistent' => $termValuesConsistent,
            'term_versions' => $termVersions,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function calendars(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        bool $lockSources,
    ): array {
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, timezone_name, schedule_type, week_pattern,
                    weekly_minutes, valid_from, valid_to, row_version
               FROM payroll_work_calendars
              WHERE supplier_id = ? AND employment_id = ?
                AND valid_from < ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from, id' . $lock
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        $calendars = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $calendarId = (int) $row['id'];
            $dayStmt = $this->db->pdo()->prepare(
                'SELECT day_date, day_kind, planned_minutes, holiday_code, holiday_name, note, row_version
                   FROM payroll_calendar_days
                  WHERE supplier_id = ? AND calendar_id = ?
                    AND day_date >= ? AND day_date < ? ORDER BY day_date, id' . $lock
            );
            $dayStmt->execute([$supplierId, $calendarId, $periodStart, $periodEnd]);
            $days = [];
            foreach ($dayStmt->fetchAll(PDO::FETCH_ASSOC) as $day) {
                $day['planned_minutes'] = (int) $day['planned_minutes'];
                $day['row_version'] = (int) $day['row_version'];
                $days[] = $day;
            }
            $pattern = json_decode((string) $row['week_pattern'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($pattern)) {
                throw new \UnexpectedValueException('Týdenní vzor kalendáře je neplatný.');
            }
            $row['id'] = $calendarId;
            $row['weekly_minutes'] = (int) $row['weekly_minutes'];
            $row['row_version'] = (int) $row['row_version'];
            $row['week_pattern'] = $pattern;
            $row['days'] = $days;
            $calendars[] = $row;
        }
        return $calendars;
    }

    /** @return list<array<string,mixed>> */
    private function entries(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        bool $lockSources,
    ): array {
        [$from, $to] = self::utcBounds($periodStart);
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, series_key, revision_no, category, starts_at_utc, ends_at_utc,
                    timezone_name, break_minutes, source_kind, source_reference,
                    LOWER(HEX(source_hash)) AS source_sha256
              FROM payroll_time_entries
              WHERE supplier_id = ? AND employment_id = ? AND status <> 'superseded'
                AND ends_at_utc > ? AND starts_at_utc < ?
              ORDER BY starts_at_utc, id" . $lock
        );
        $stmt->execute([$supplierId, $employmentId, $from, $to]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['revision_no'] = (int) $row['revision_no'];
            $row['break_minutes'] = (int) $row['break_minutes'];
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function absences(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        bool $lockSources,
    ): array {
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, absence_type, date_from, date_to, timezone_name,
                    partial_first_minutes, partial_last_minutes, compensation_policy,
                    compensation_rate_basis_points, average_snapshot_id, support_status,
                    status, correction_pending, row_version
               FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ?
                AND status IN ('requested','approved')
                AND date_from < ? AND date_to >= ? ORDER BY date_from, id" . $lock
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach ([
                'id', 'partial_first_minutes', 'partial_last_minutes',
                'compensation_rate_basis_points', 'average_snapshot_id', 'row_version',
            ] as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            $row['correction_pending'] = (bool) $row['correction_pending'];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $employment
     * @return array{?\DateTimeImmutable,?\DateTimeImmutable,int}
     */
    private static function evidenceInterval(
        array $employment,
        \DateTimeImmutable $period,
        \DateTimeImmutable $periodEnd,
    ): array {
        if (in_array($employment['relation_type'], ['dpp', 'dpc'], true)) {
            return [null, null, 0];
        }
        $startRaw = $employment['actual_start_date'] ?? $employment['start_date'];
        if (!is_string($startRaw) || $startRaw === '') {
            return [null, null, 0];
        }
        $start = max(new \DateTimeImmutable($startRaw), $period);
        $end = $periodEnd->modify('-1 day');
        if (is_string($employment['end_date']) && $employment['end_date'] !== '') {
            $end = min($end, new \DateTimeImmutable($employment['end_date']));
        }
        if ($end < $start) {
            return [null, null, 0];
        }
        return [$start, $end, $start->diff($end)->days + 1];
    }

    /**
     * @param list<array<string,mixed>> $calendars
     * @return array{int,list<array<string,string>>}
     */
    private static function agreedFundMinutes(
        array $calendars,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): array {
        if ($from === null || $to === null) {
            return [0, []];
        }
        $minutes = 0;
        $issues = [];
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $iso = $date->format('Y-m-d');
            $matching = array_values(array_filter(
                $calendars,
                static fn (array $calendar): bool => $calendar['valid_from'] <= $iso
                    && ($calendar['valid_to'] === null || $calendar['valid_to'] >= $iso),
            ));
            if (count($matching) !== 1) {
                $issues[] = [
                    'code' => 'calendar_day_not_uniquely_covered',
                    'message' => "Datum {$iso} není pokryto právě jedním pracovním kalendářem.",
                ];
                continue;
            }
            $calendar = $matching[0];
            $overrides = [];
            foreach ($calendar['days'] as $day) {
                $overrides[$day['day_date']] = $day;
            }
            $override = $overrides[$iso] ?? null;
            $weekday = $date->format('N');
            $planned = (int) ($calendar['week_pattern'][$weekday]
                ?? $calendar['week_pattern'][(int) $weekday]
                ?? 0);
            if (is_array($override) && $override['day_kind'] !== 'holiday') {
                $planned = (int) $override['planned_minutes'];
            }
            $minutes += $planned;
        }
        return [$minutes, $issues];
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array{int,list<array<string,string>>}
     */
    private static function workedMinutes(array $entries, string $periodStart): array
    {
        $minutes = 0;
        $issues = [];
        $intervals = [];
        foreach ($entries as $entry) {
            if (!in_array($entry['category'], ['regular', 'overtime'], true)) {
                continue;
            }
            $timezone = new \DateTimeZone((string) $entry['timezone_name']);
            $utc = new \DateTimeZone('UTC');
            $start = new \DateTimeImmutable((string) $entry['starts_at_utc'], $utc);
            $end = new \DateTimeImmutable((string) $entry['ends_at_utc'], $utc);
            $periodMonth = substr($periodStart, 0, 7);
            $startMonth = $start->setTimezone($timezone)->format('Y-m');
            $endMonth = $end->setTimezone($timezone)->format('Y-m');
            if ($startMonth !== $periodMonth && $endMonth !== $periodMonth) {
                if ($startMonth === $endMonth) {
                    continue;
                }
            }
            if ($startMonth !== $periodMonth || $endMonth !== $periodMonth) {
                $issues[] = [
                    'code' => 'worked_interval_crosses_month',
                    'message' => 'Odpracovaný interval překračuje místní hranici měsíce.',
                ];
                continue;
            }
            foreach ($intervals as [$seenStart, $seenEnd]) {
                if ($start < $seenEnd && $end > $seenStart) {
                    $issues[] = [
                        'code' => 'worked_intervals_overlap',
                        'message' => 'Základní a přesčasové intervaly se překrývají.',
                    ];
                    break;
                }
            }
            $intervals[] = [$start, $end];
            $net = intdiv($end->getTimestamp() - $start->getTimestamp(), 60)
                - (int) $entry['break_minutes'];
            if ($net < 0) {
                $issues[] = [
                    'code' => 'worked_interval_negative',
                    'message' => 'Přestávka je delší než evidovaný pracovní interval.',
                ];
                continue;
            }
            $minutes += $net;
        }
        return [$minutes, $issues];
    }

    private static function period(string $periodStart): \DateTimeImmutable
    {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($period === false
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
        ) {
            throw new \InvalidArgumentException('period_start musí být první den měsíce.');
        }
        return $period;
    }

    /** @return array{string,string} */
    private static function utcBounds(string $periodStart): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($periodStart, $utc);
        $end = $start->modify('first day of next month');
        return [
            $start->modify('-1 day')->format('Y-m-d H:i:s'),
            $end->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }

    private static function minutesSuggestion(int $minutes): ?string
    {
        $millihoursNumerator = $minutes * 1000;
        if ($millihoursNumerator % 60 !== 0) {
            return null;
        }
        $millihours = intdiv($millihoursNumerator, 60);
        $whole = intdiv($millihours, 1000);
        $fraction = $millihours % 1000;
        return $fraction === 0
            ? (string) $whole
            : rtrim(sprintf('%d.%03d', $whole, $fraction), '0');
    }

    private static function scaledDecimal(
        mixed $value,
        string $field,
        int $scale,
        int $totalDigits,
    ): int {
        if (!is_string($value)
            || preg_match('/^(0|[1-9]\d*)(?:\.(\d+))?$/D', trim($value), $matches) !== 1
        ) {
            throw new \InvalidArgumentException("{$field} musí být nezáporné desetinné číslo.");
        }
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $scale) {
            throw new \InvalidArgumentException("{$field} smí mít nejvýše {$scale} desetinná místa.");
        }
        $digits = ltrim($matches[1], '0') . $fraction;
        if (strlen(ltrim($digits, '0')) > $totalDigits) {
            throw new \InvalidArgumentException("{$field} překračuje rozsah JMHZ.");
        }
        return ((int) $matches[1] * (10 ** $scale))
            + (int) str_pad($fraction, $scale, '0');
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        throw new \InvalidArgumentException("{$field} musí být nezáporné celé číslo.");
    }

    /** @return array<string,string> */
    private static function specification(): array
    {
        return [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
        ];
    }
}
