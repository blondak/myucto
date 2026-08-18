<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Samostatné potvrzení o průměrném výdělku: hodinový průměr podle § 356 odst. 1
 * zákoníku práce a z něj odvozený hrubý měsíční průměr podle odst. 2. Žádné
 * čisté částky — ty patří výhradně na oddělené potvrzení podle § 313 odst. 2.
 */
final readonly class AverageEarningsStatementDocumentData
{
    public const SCHEMA_VERSION = 'average-earnings-statement-document.v1';

    public function __construct(
        public string $sourceSnapshotSha256,
        public string $averageSnapshotSha256,
        public PayrollDocumentEmployerSnapshot $employer,
        public string $employeeName,
        public string $employeeBirthDate,
        public string $employeeAddress,
        public string $relationshipKind,
        public string $employmentFrom,
        public string $employmentTo,
        public string $averageKind,
        public int $averageApplicableYear,
        public int $averageApplicableQuarter,
        public string $decisiveFrom,
        public string $decisiveTo,
        public int $averageHourlyMinorUnits,
        public bool $minimumWageFloorApplied,
        public int $weeklyHoursMilli,
        public int $grossMonthlyMinorUnits,
        public string $requestedPurpose,
        public string $issuedAt,
    ) {
        foreach ([
            'zdrojový otisk' => $sourceSnapshotSha256,
            'otisk průměrného výdělku' => $averageSnapshotSha256,
        ] as $label => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException(
                    ucfirst($label) . ' není platný.',
                );
            }
        }
        foreach ([
            'jméno zaměstnance' => [$employeeName, 255],
            'adresa zaměstnance' => [$employeeAddress, 500],
            'účel potvrzení' => [$requestedPurpose, 255],
        ] as $label => [$value, $maximum]) {
            self::text($value, $label, $maximum);
        }
        if (!in_array($relationshipKind, ['employment', 'dpc', 'dpp'], true)) {
            throw new \InvalidArgumentException(
                'Druh pracovněprávního vztahu potvrzení není podporovaný.',
            );
        }
        if (!in_array($averageKind, ['actual', 'probable'], true)
            || $averageApplicableYear < 2000
            || $averageApplicableYear > 2100
            || $averageApplicableQuarter < 1
            || $averageApplicableQuarter > 4
            || $averageHourlyMinorUnits <= 0
            || $weeklyHoursMilli <= 0
            || $grossMonthlyMinorUnits <= 0
        ) {
            throw new \InvalidArgumentException(
                'Průměrný výdělek potvrzení není úplný.',
            );
        }
        $birthDate = self::date($employeeBirthDate, 'narození zaměstnance');
        $from = self::date($employmentFrom, 'počátku zaměstnání');
        $to = self::date($employmentTo, 'konce zaměstnání');
        $decisiveStart = self::date($decisiveFrom, 'počátku rozhodného období');
        $decisiveEnd = self::date($decisiveTo, 'konce rozhodného období');
        $issued = self::date($issuedAt, 'vydání potvrzení');
        if ($to < $from
            || $issued < $to
            || $birthDate >= $from
            || $decisiveEnd < $decisiveStart
        ) {
            throw new \InvalidArgumentException(
                'Data potvrzení o průměrném výdělku nejsou v platném pořadí.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function toTemplateData(): array
    {
        return [
            'source_snapshot_sha256' => $this->sourceSnapshotSha256,
            'average_snapshot_sha256' => $this->averageSnapshotSha256,
            'employer' => $this->employer->toArray(),
            'employee' => [
                'name' => $this->employeeName,
                'birth_date' => $this->employeeBirthDate,
                'address' => $this->employeeAddress,
            ],
            'relationship_kind' => $this->relationshipKind,
            'employment_from' => $this->employmentFrom,
            'employment_to' => $this->employmentTo,
            'average_kind' => $this->averageKind,
            'average_applicable_year' => $this->averageApplicableYear,
            'average_applicable_quarter' => $this->averageApplicableQuarter,
            'decisive_from' => $this->decisiveFrom,
            'decisive_to' => $this->decisiveTo,
            'average_hourly_minor_units' => $this->averageHourlyMinorUnits,
            'minimum_wage_floor_applied' => $this->minimumWageFloorApplied,
            'weekly_hours_milli' => $this->weeklyHoursMilli,
            'gross_monthly_minor_units' => $this->grossMonthlyMinorUnits,
            'requested_purpose' => $this->requestedPurpose,
            'issued_at' => $this->issuedAt,
        ];
    }

    private static function text(
        string $value,
        string $label,
        int $maximum,
    ): void {
        if (trim($value) === ''
            || trim($value) !== $value
            || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \InvalidArgumentException("Pole {$label} není platné.");
        }
    }

    private static function date(
        string $value,
        string $label,
    ): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Datum {$label} není platné.");
        }

        return $date;
    }
}
