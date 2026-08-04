<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class EmploymentCertificateDocumentData
{
    public const SCHEMA_VERSION = 'employment-certificate-document.v1';

    /**
     * @param list<string> $exposureFacts
     * @param list<EmploymentCertificateDeduction> $deductions
     * @param list<array{category:string,from:string,to:string}> $pre1993PensionCategoryPeriods
     */
    public function __construct(
        public string $sourceSnapshotSha256,
        public PayrollDocumentEmployerSnapshot $employer,
        public string $employeeName,
        public string $employeeBirthDate,
        public string $employeeAddress,
        public string $relationshipKind,
        public string $employmentFrom,
        public string $employmentTo,
        public string $workDescription,
        public string $achievedQualification,
        public bool $exposureAssessmentComplete,
        public array $exposureFacts,
        public bool $deductionAssessmentComplete,
        public array $deductions,
        public bool $pensionCategoryAssessmentComplete,
        public array $pre1993PensionCategoryPeriods,
        public string $issuedAt,
        public ?string $dppIssuanceBasis = null,
    ) {
        self::hash($sourceSnapshotSha256, 'zdrojový otisk');
        foreach ([
            'jméno zaměstnance' => [$employeeName, 255],
            'adresa zaměstnance' => [$employeeAddress, 500],
            'druh práce' => [$workDescription, 500],
            'dosažená kvalifikace' => [$achievedQualification, 500],
        ] as $label => [$value, $maximum]) {
            self::text($value, $label, $maximum);
        }
        if (!in_array($relationshipKind, ['employment', 'dpc', 'dpp'], true)) {
            throw new \InvalidArgumentException(
                'Druh pracovněprávního vztahu potvrzení není podporovaný.',
            );
        }
        if ($relationshipKind === 'dpp'
            && !in_array($dppIssuanceBasis, [
                'sickness_insurance',
                'wage_deductions',
            ], true)
        ) {
            throw new \InvalidArgumentException(
                'Potvrzení pro DPP vyžaduje doložený zákonný důvod vydání.',
            );
        }
        if ($relationshipKind !== 'dpp' && $dppIssuanceBasis !== null) {
            throw new \InvalidArgumentException(
                'Důvod vydání pro DPP nepatří k tomuto vztahu.',
            );
        }
        $birthDate = self::date($employeeBirthDate, 'narození zaměstnance');
        $from = self::date($employmentFrom, 'počátku zaměstnání');
        $to = self::date($employmentTo, 'konce zaměstnání');
        $issued = self::date($issuedAt, 'vydání potvrzení');
        if ($to < $from || $issued < $to || $birthDate >= $from) {
            throw new \InvalidArgumentException(
                'Data potvrzení o zaměstnání nejsou v platném pořadí.',
            );
        }
        if (!$exposureAssessmentComplete) {
            throw new \InvalidArgumentException(
                'Posouzení expoziční doby není úplné.',
            );
        }
        foreach ($exposureFacts as $fact) {
            self::text($fact, 'expoziční skutečnost', 1000);
        }
        if (!$deductionAssessmentComplete) {
            throw new \InvalidArgumentException(
                'Posouzení pokračujících srážek není úplné.',
            );
        }
        if (!$pensionCategoryAssessmentComplete) {
            throw new \InvalidArgumentException(
                'Posouzení pracovních kategorií před rokem 1993 není úplné.',
            );
        }
        $lastTo = null;
        foreach ($pre1993PensionCategoryPeriods as $period) {
            if (!in_array($period['category'], ['I', 'II'], true)) {
                throw new \InvalidArgumentException(
                    'Důchodová pracovní kategorie nemá platnou strukturu.',
                );
            }
            $periodFrom = self::date(
                $period['from'],
                'počátku pracovní kategorie',
            );
            $periodTo = self::date(
                $period['to'],
                'konce pracovní kategorie',
            );
            if ($periodTo < $periodFrom
                || $periodTo >= new \DateTimeImmutable('1993-01-01')
                || ($lastTo !== null && $periodFrom <= $lastTo)
            ) {
                throw new \InvalidArgumentException(
                    'Důchodové pracovní kategorie se překrývají nebo nejsou před rokem 1993.',
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
            'employer' => $this->employer->toArray(),
            'employee' => [
                'name' => $this->employeeName,
                'birth_date' => $this->employeeBirthDate,
                'address' => $this->employeeAddress,
            ],
            'relationship_kind' => $this->relationshipKind,
            'employment_from' => $this->employmentFrom,
            'employment_to' => $this->employmentTo,
            'work_description' => $this->workDescription,
            'achieved_qualification' => $this->achievedQualification,
            'exposure_facts' => $this->exposureFacts,
            'deductions' => array_map(
                static fn (EmploymentCertificateDeduction $deduction): array =>
                    $deduction->toArray(),
                $this->deductions,
            ),
            'pre1993_pension_category_periods' =>
                $this->pre1993PensionCategoryPeriods,
            'issued_at' => $this->issuedAt,
            'dpp_issuance_basis' => $this->dppIssuanceBasis,
        ];
    }

    private static function hash(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                ucfirst($label) . ' není platný.',
            );
        }
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
