<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * @phpstan-type DeductionEvidence array{
 *   source_claim_id:int,
 *   beneficiary:string,
 *   ordering_authority:string,
 *   decision_reference:string
 * }
 * @phpstan-type PensionCategoryPeriod array{category:string,from:string,to:string}
 * @phpstan-type EmploymentCertificateEvidence array{
 *   work_description:string,
 *   achieved_qualification:string,
 *   exposure_assessment_complete:bool,
 *   exposure_facts:list<string>,
 *   deduction_assessment_complete:bool,
 *   deductions:list<DeductionEvidence>,
 *   pension_category_assessment_complete:bool,
 *   pre1993_pension_category_periods:list<PensionCategoryPeriod>,
 *   dpp_issuance_basis:?string,
 *   correction_reason:?string
 * }
 */
final class EmploymentCertificateEvidenceValidator
{
    private const KEYS = [
        'work_description',
        'achieved_qualification',
        'exposure_assessment_complete',
        'exposure_facts',
        'deduction_assessment_complete',
        'deductions',
        'pension_category_assessment_complete',
        'pre1993_pension_category_periods',
        'dpp_issuance_basis',
        'correction_reason',
    ];

    /**
     * @param array<string,mixed> $input
     * @return EmploymentCertificateEvidence
     */
    public function validate(array $input): array
    {
        $unknown = array_diff(array_keys($input), self::KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Podklad potvrzení obsahuje nepodporované pole.',
            );
        }

        $exposureComplete = $this->bool(
            $input,
            'exposure_assessment_complete',
        );
        if (!$exposureComplete) {
            throw new EmploymentExitReadinessException(
                'exposure_assessment_incomplete',
                'Posouzení expozičních skutečností není dokončené.',
            );
        }
        $deductionComplete = $this->bool(
            $input,
            'deduction_assessment_complete',
        );
        if (!$deductionComplete) {
            throw new EmploymentExitReadinessException(
                'deduction_assessment_incomplete',
                'Posouzení pokračujících srážek není dokončené.',
            );
        }
        $pensionCategoryComplete = $this->bool(
            $input,
            'pension_category_assessment_complete',
        );
        if (!$pensionCategoryComplete) {
            throw new EmploymentExitReadinessException(
                'pension_category_assessment_incomplete',
                'Posouzení pracovních kategorií před rokem 1993 není dokončené.',
            );
        }

        $basis = $this->nullableText(
            $input['dpp_issuance_basis'] ?? null,
            'dpp_issuance_basis',
            32,
        );
        if ($basis !== null
            && !in_array($basis, [
                'sickness_insurance',
                'wage_deductions',
            ], true)
        ) {
            throw new \InvalidArgumentException(
                'Důvod vydání potvrzení pro DPP není podporovaný.',
            );
        }

        return [
            'work_description' => $this->text(
                $input['work_description'] ?? null,
                'work_description',
                500,
            ),
            'achieved_qualification' => $this->text(
                $input['achieved_qualification'] ?? null,
                'achieved_qualification',
                500,
            ),
            'exposure_assessment_complete' => true,
            'exposure_facts' => $this->textList(
                $input['exposure_facts'] ?? null,
                'exposure_facts',
                1000,
            ),
            'deduction_assessment_complete' => true,
            'deductions' => $this->deductions($input['deductions'] ?? null),
            'pension_category_assessment_complete' => true,
            'pre1993_pension_category_periods' => $this->pensionPeriods(
                $input['pre1993_pension_category_periods'] ?? null,
            ),
            'dpp_issuance_basis' => $basis,
            'correction_reason' => $this->nullableText(
                $input['correction_reason'] ?? null,
                'correction_reason',
                1000,
            ),
        ];
    }

    /** @return list<array{source_claim_id:int,beneficiary:string,ordering_authority:string,decision_reference:string}> */
    private function deductions(mixed $value): array
    {
        $rows = $this->list($value, 'deductions');
        $result = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \InvalidArgumentException(
                    "Pole deductions.{$index} musí být objekt.",
                );
            }
            $unknown = array_diff(array_keys($row), [
                'source_claim_id',
                'beneficiary',
                'ordering_authority',
                'decision_reference',
            ]);
            if ($unknown !== []) {
                throw new \InvalidArgumentException(
                    "Pole deductions.{$index} obsahuje nepodporovaný údaj.",
                );
            }
            $claimId = $this->positiveInt(
                $row['source_claim_id'] ?? null,
                "deductions.{$index}.source_claim_id",
            );
            if (isset($seen[$claimId])) {
                throw new \InvalidArgumentException(
                    'Pokračující srážka je v podkladu uvedená vícekrát.',
                );
            }
            $seen[$claimId] = true;
            $result[] = [
                'source_claim_id' => $claimId,
                'beneficiary' => $this->text(
                    $row['beneficiary'] ?? null,
                    "deductions.{$index}.beneficiary",
                    255,
                ),
                'ordering_authority' => $this->text(
                    $row['ordering_authority'] ?? null,
                    "deductions.{$index}.ordering_authority",
                    255,
                ),
                'decision_reference' => $this->text(
                    $row['decision_reference'] ?? null,
                    "deductions.{$index}.decision_reference",
                    255,
                ),
            ];
        }

        return $result;
    }

    /** @return list<array{category:string,from:string,to:string}> */
    private function pensionPeriods(mixed $value): array
    {
        $rows = $this->list($value, 'pre1993_pension_category_periods');
        $result = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \InvalidArgumentException(
                    "Pole pre1993_pension_category_periods.{$index} musí být objekt.",
                );
            }
            $unknown = array_diff(array_keys($row), ['category', 'from', 'to']);
            if ($unknown !== []) {
                throw new \InvalidArgumentException(
                    'Interval pracovní kategorie obsahuje nepodporovaný údaj.',
                );
            }
            $category = $this->text(
                $row['category'] ?? null,
                "pre1993_pension_category_periods.{$index}.category",
                2,
            );
            if (!in_array($category, ['I', 'II'], true)) {
                throw new \InvalidArgumentException(
                    'Pracovní kategorie musí být I nebo II.',
                );
            }
            $result[] = [
                'category' => $category,
                'from' => $this->date(
                    $row['from'] ?? null,
                    "pre1993_pension_category_periods.{$index}.from",
                ),
                'to' => $this->date(
                    $row['to'] ?? null,
                    "pre1993_pension_category_periods.{$index}.to",
                ),
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private function textList(mixed $value, string $field, int $maximum): array
    {
        $rows = $this->list($value, $field);
        $result = [];
        foreach ($rows as $index => $row) {
            $result[] = $this->text(
                $row,
                "{$field}.{$index}",
                $maximum,
            );
        }

        return $result;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být seznam.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function bool(array $input, string $field): bool
    {
        if (!is_bool($input[$field] ?? null)) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být boolean.",
            );
        }

        return $input[$field];
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být kladné celé číslo.",
            );
        }

        return $value;
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
