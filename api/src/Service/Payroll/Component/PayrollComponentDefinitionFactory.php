<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

final class PayrollComponentDefinitionFactory
{
    /** @param array<string,mixed> $row */
    public function fromArray(array $row): PayrollComponentDefinition
    {
        return new PayrollComponentDefinition(
            code: $this->string($row, 'code'),
            name: $this->string($row, 'name'),
            kind: PayrollComponentKind::from($this->string($row, 'component_kind')),
            valueKind: PayrollComponentValueKind::from($this->string($row, 'value_kind')),
            frequency: PayrollComponentFrequency::from(
                $this->string($row, 'frequency_kind'),
            ),
            taxTreatment: PayrollComponentTaxTreatment::from(
                $this->string($row, 'tax_treatment'),
            ),
            socialParticipationTreatment: PayrollComponentInclusion::from(
                $this->string($row, 'social_participation_treatment'),
            ),
            socialTreatment: PayrollComponentInclusion::from(
                $this->string($row, 'social_treatment'),
            ),
            healthParticipationTreatment: PayrollComponentInclusion::from(
                $this->string($row, 'health_participation_treatment'),
            ),
            healthTreatment: PayrollComponentInclusion::from(
                $this->string($row, 'health_treatment'),
            ),
            averageEarningTreatment: PayrollComponentInclusion::from(
                $this->string($row, 'average_earning_treatment'),
            ),
            enforcementTreatment: PayrollComponentInclusion::from(
                $this->string($row, 'enforcement_treatment'),
            ),
            jmhzTreatment: PayrollComponentInclusion::from(
                $this->string($row, 'jmhz_treatment'),
            ),
            statisticsTreatment: PayrollComponentInclusion::from(
                $this->string($row, 'statistics_treatment'),
            ),
            accountingDebitCode: $this->nullableString(
                $row,
                'accounting_debit_code',
            ),
            accountingCreditCode: $this->nullableString(
                $row,
                'accounting_credit_code',
            ),
            annualLimitMinor: $this->nullableInt($row, 'annual_limit_minor'),
        );
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Mzdová složka nemá pole {$key}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Mzdová složka nemá pole {$key}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException("Mzdová složka nemá pole {$key}.");
        }
        return (int) $value;
    }
}
