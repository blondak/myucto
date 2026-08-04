<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayrollSheetDocumentData
{
    /**
     * @param list<string> $previousNames
     * @param list<PayrollSheetMonth> $months
     */
    public function __construct(
        public string $sourceSnapshotSha256,
        public int $taxYear,
        public string $employerName,
        public string $employerIdentificationNumber,
        public string $employerAddress,
        public string $employeeName,
        public array $previousNames,
        public string $personalIdentifierLabel,
        public string $personalIdentifierValue,
        public string $employeeAddress,
        public array $months,
        public string $annualSettlementStatus,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotSha256) !== 1) {
            throw new \InvalidArgumentException('Zdrojový otisk mzdového listu není platný.');
        }
        if ($taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Rok mzdového listu není platný.');
        }
        foreach ([
            $employerName,
            $employerIdentificationNumber,
            $employerAddress,
            $employeeName,
            $personalIdentifierLabel,
            $personalIdentifierValue,
            $employeeAddress,
        ] as $text) {
            if (trim($text) === '' || mb_strlen($text) > 500) {
                throw new \InvalidArgumentException('Identifikace mzdového listu není úplná.');
            }
        }
        if (count($previousNames) > 20) {
            throw new \InvalidArgumentException('Dřívější jména nemají platnou strukturu.');
        }
        foreach ($previousNames as $name) {
            if (trim($name) === '' || mb_strlen($name) > 191) {
                throw new \InvalidArgumentException('Dřívější jméno není platné.');
            }
        }
        if ($months === []) {
            throw new \InvalidArgumentException('Mzdový list nemá žádný schválený měsíc.');
        }
        $seen = [];
        foreach ($months as $month) {
            if (isset($seen[$month->month])) {
                throw new \InvalidArgumentException('Měsíce mzdového listu nejsou jednoznačné.');
            }
            $seen[$month->month] = true;
        }
        if (!in_array($annualSettlementStatus, ['not_performed', 'approved'], true)) {
            throw new \InvalidArgumentException('Stav ročního zúčtování není platný.');
        }
    }

    /** @return array<string,int> */
    public function totals(): array
    {
        $totals = ['source_revision_count' => 0];
        foreach ($this->months as $month) {
            $totals['source_revision_count'] = $this->add(
                $totals['source_revision_count'],
                $month->sourceRevisionCount,
            );
            foreach ($month->amounts() as $key => $amount) {
                $totals[$key] = $this->add($totals[$key] ?? 0, $amount);
            }
        }
        return $totals;
    }

    /** @return array<string,mixed> */
    public function toTemplateData(): array
    {
        return [
            'tax_year' => $this->taxYear,
            'source_snapshot_sha256' => $this->sourceSnapshotSha256,
            'employer' => [
                'name' => $this->employerName,
                'identification_number' => $this->employerIdentificationNumber,
                'address' => $this->employerAddress,
            ],
            'employee' => [
                'name' => $this->employeeName,
                'previous_names' => $this->previousNames,
                'identifier_label' => $this->personalIdentifierLabel,
                'identifier_value' => $this->personalIdentifierValue,
                'address' => $this->employeeAddress,
            ],
            'months' => array_map(
                static fn (PayrollSheetMonth $month): array => $month->toTemplateData(),
                $this->months,
            ),
            'totals' => $this->totals(),
            'annual_settlement_status' => $this->annualSettlementStatus,
        ];
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \OverflowException('Roční součet mzdového listu přetekl.');
        }
        return $left + $right;
    }
}
