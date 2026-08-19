<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class AverageEarningsCertificateDocumentData
{
    public const SCHEMA_VERSION = 'average-earnings-certificate-document.v1';

    /** Doména způsobů skončení, kterou doklad tiskne. */
    public const TERMINATION_REASONS = [
        'none',
        'gross_breach',
        'sickness_regime_breach',
        'organizational',
        'health',
        'employer_breach',
        'employee_unilateral',
        'agreement',
    ];

    /**
     * @param list<array{from:string,to:string}> $pensionInsurancePeriods
     */
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
        public array $pensionInsurancePeriods,
        public string $averageKind,
        public int $averageApplicableYear,
        public int $averageApplicableQuarter,
        public int $averageMonthlyNetMinorUnits,
        public string $terminationReasonKind,
        public ?string $employeeStatedReason,
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
            || $averageMonthlyNetMinorUnits <= 0
            || $averageMonthlyNetMinorUnits % 100 !== 0
        ) {
            throw new \InvalidArgumentException(
                'Průměrný měsíční čistý výdělek není úplný nebo je v nesprávných jednotkách.',
            );
        }
        if (!in_array(
            $terminationReasonKind,
            self::TERMINATION_REASONS,
            true,
        )) {
            throw new \InvalidArgumentException(
                'Důvod skončení pro Úřad práce není podporovaný.',
            );
        }
        if ($employeeStatedReason !== null) {
            self::text(
                $employeeStatedReason,
                'důvod uvedený zaměstnancem',
                1000,
            );
            if (!in_array($terminationReasonKind, [
                'employee_unilateral',
                'agreement',
            ], true)) {
                throw new \InvalidArgumentException(
                    'Textový důvod zaměstnance neodpovídá způsobu skončení.',
                );
            }
        }
        $birthDate = self::date($employeeBirthDate, 'narození zaměstnance');
        $from = self::date($employmentFrom, 'počátku zaměstnání');
        $to = self::date($employmentTo, 'konce zaměstnání');
        $issued = self::date($issuedAt, 'vydání potvrzení');
        if ($to < $from || $issued < $to || $birthDate >= $from) {
            throw new \InvalidArgumentException(
                'Data potvrzení pro Úřad práce nejsou v platném pořadí.',
            );
        }
        $lastTo = null;
        foreach ($pensionInsurancePeriods as $period) {
            $periodFrom = self::date(
                $period['from'],
                'počátku důchodového pojištění',
            );
            $periodTo = self::date(
                $period['to'],
                'konce důchodového pojištění',
            );
            if ($periodFrom < $from
                || $periodTo > $to
                || $periodTo < $periodFrom
                || ($lastTo !== null && $periodFrom <= $lastTo)
            ) {
                throw new \InvalidArgumentException(
                    'Intervaly důchodového pojištění se překrývají nebo přesahují zaměstnání.',
                );
            }
            $lastTo = $periodTo;
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
            'pension_insurance_periods' => $this->pensionInsurancePeriods,
            'average_kind' => $this->averageKind,
            'average_applicable_year' => $this->averageApplicableYear,
            'average_applicable_quarter' => $this->averageApplicableQuarter,
            'average_monthly_net_minor_units' =>
                $this->averageMonthlyNetMinorUnits,
            'termination_reason_kind' => $this->terminationReasonKind,
            'employee_stated_reason' => $this->employeeStatedReason,
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
            throw new \InvalidArgumentException(
                "Pole {$label} není platné.",
            );
        }
    }

    private static function date(
        string $value,
        string $label,
    ): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                "Datum {$label} není platné.",
            );
        }

        return $date;
    }
}
