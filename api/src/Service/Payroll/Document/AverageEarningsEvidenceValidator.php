<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Podklad, který k výstupním potvrzením o průměrném výdělku nelze odvodit
 * z dat — způsob skončení podle § 313 odst. 2 zákoníku práce, doby důchodového
 * pojištění a důvod opravy. Vše ostatní staví builder ze schválených zdrojů.
 *
 * @phpstan-type PensionInsurancePeriod array{from:string,to:string}
 * @phpstan-type AverageEarningsCertificateEvidence array{
 *   termination_assessment_complete:bool,
 *   termination_reason_kind:string,
 *   employee_stated_reason:?string,
 *   pension_insurance_periods:list<PensionInsurancePeriod>,
 *   correction_reason:?string
 * }
 * @phpstan-type AverageEarningsStatementEvidence array{
 *   requested_purpose:string,
 *   correction_reason:?string
 * }
 */
final class AverageEarningsEvidenceValidator
{
    private const CERTIFICATE_KEYS = [
        'termination_assessment_complete',
        'termination_reason_kind',
        'employee_stated_reason',
        'pension_insurance_periods',
        'correction_reason',
    ];

    private const STATEMENT_KEYS = [
        'requested_purpose',
        'correction_reason',
    ];

    /**
     * @param array<string,mixed> $input
     * @return AverageEarningsCertificateEvidence
     */
    public function validateCertificate(array $input): array
    {
        $this->assertKeys($input, self::CERTIFICATE_KEYS);
        if (!is_bool($input['termination_assessment_complete'] ?? null)
            || $input['termination_assessment_complete'] !== true
        ) {
            throw new EmploymentExitReadinessException(
                'termination_assessment_incomplete',
                'Posouzení způsobu skončení pro Úřad práce není dokončené.',
            );
        }
        $reason = $this->text(
            $input['termination_reason_kind'] ?? null,
            'termination_reason_kind',
            48,
        );
        if (!in_array(
            $reason,
            AverageEarningsCertificateDocumentData::TERMINATION_REASONS,
            true,
        )) {
            throw new \InvalidArgumentException(
                'Způsob skončení pro Úřad práce není podporovaný.',
            );
        }
        $stated = $this->nullableText(
            $input['employee_stated_reason'] ?? null,
            'employee_stated_reason',
            1000,
        );
        if ($stated !== null
            && !in_array($reason, ['employee_unilateral', 'agreement'], true)
        ) {
            throw new \InvalidArgumentException(
                'Textový důvod zaměstnance neodpovídá způsobu skončení.',
            );
        }

        return [
            'termination_assessment_complete' => true,
            'termination_reason_kind' => $reason,
            'employee_stated_reason' => $stated,
            'pension_insurance_periods' => $this->periods(
                $input['pension_insurance_periods'] ?? null,
            ),
            'correction_reason' => $this->nullableText(
                $input['correction_reason'] ?? null,
                'correction_reason',
                1000,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return AverageEarningsStatementEvidence
     */
    public function validateStatement(array $input): array
    {
        $this->assertKeys($input, self::STATEMENT_KEYS);

        return [
            'requested_purpose' => $this->text(
                $input['requested_purpose'] ?? null,
                'requested_purpose',
                255,
            ),
            'correction_reason' => $this->nullableText(
                $input['correction_reason'] ?? null,
                'correction_reason',
                1000,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $keys
     */
    private function assertKeys(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys) !== []) {
            throw new \InvalidArgumentException(
                'Podklad potvrzení obsahuje nepodporované pole.',
            );
        }
    }

    /** @return list<array{from:string,to:string}> */
    private function periods(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(
                'Pole pension_insurance_periods musí být seznam.',
            );
        }
        $result = [];
        foreach ($value as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \InvalidArgumentException(
                    "Pole pension_insurance_periods.{$index} musí být objekt.",
                );
            }
            if (array_diff(array_keys($row), ['from', 'to']) !== []) {
                throw new \InvalidArgumentException(
                    'Interval důchodového pojištění obsahuje nepodporovaný údaj.',
                );
            }
            $result[] = [
                'from' => $this->date(
                    $row['from'] ?? null,
                    "pension_insurance_periods.{$index}.from",
                ),
                'to' => $this->date(
                    $row['to'] ?? null,
                    "pension_insurance_periods.{$index}.to",
                ),
            ];
        }

        return $result;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být datum YYYY-MM-DD.",
            );
        }

        return $value;
    }

    private function text(mixed $value, string $field, int $maximum): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být text.");
        }
        $trimmed = trim($value);
        if ($trimmed === ''
            || $trimmed !== $value
            || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \InvalidArgumentException("Pole {$field} není platné.");
        }

        return $value;
    }

    private function nullableText(
        mixed $value,
        string $field,
        int $maximum,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->text($value, $field, $maximum);
    }
}
