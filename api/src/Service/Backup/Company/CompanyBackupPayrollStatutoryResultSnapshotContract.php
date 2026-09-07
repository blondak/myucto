<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Sdílené identity uvnitř výsledků zákonného výpočtu jedné osoby. */
final class CompanyBackupPayrollStatutoryResultSnapshotContract
{
    /**
     * Přímý payload jedné hodnoty `calculation_kind` v person results.
     *
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    public static function directEmbeddedReferences(array $rootPath = []): array
    {
        return self::sortReferences([
            self::tenant(
                [...$rootPath, 'deductions', '*', 'deduction_reference'],
                'payroll_deduction_agreements',
                valuePrefix: 'agreement:',
            ),
            self::tenant(
                [...$rootPath, 'employee_reference'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
            ...self::relationshipReferences([
                ...$rootPath,
                'relationships',
                '*',
            ]),
            self::tenant(
                [...$rootPath, 'payer_reference'],
                'supplier',
                valuePrefix: 'supplier:',
            ),
            self::tenant(
                [...$rootPath, 'person_id'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
            self::tenant(
                [...$rootPath, 'person_reference'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
        ]);
    }

    /**
     * Samostatně uložený výsledek vztahu sdílí stejné identity jako jeho
     * kopie v person a run snapshotu; blocked větev navíc nese osobu.
     *
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    public static function relationshipEmbeddedReferences(
        array $rootPath = [],
    ): array {
        return self::sortReferences([
            ...self::relationshipReferences($rootPath),
            self::tenant(
                [...$rootPath, 'person_reference'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
        ]);
    }

    /**
     * Obálka osoby uvnitř výsledku celé revize obsahuje všechny čtyři druhy.
     *
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    public static function personEnvelopeEmbeddedReferences(array $rootPath): array
    {
        return self::sortReferences([
            ...self::insuranceReferences(
                [...$rootPath, 'health_insurance'],
            ),
            ...self::incomeTaxReferences([...$rootPath, 'income_tax']),
            ...self::netPayReferences([...$rootPath, 'net_pay']),
            self::tenant(
                [...$rootPath, 'person_reference'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
            ...self::insuranceReferences(
                [...$rootPath, 'social_insurance'],
            ),
        ]);
    }

    /**
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    private static function insuranceReferences(array $rootPath): array
    {
        return [
            ...self::insuranceRelationshipReferences([
                ...$rootPath,
                'relationships',
                '*',
            ]),
            self::tenant(
                [...$rootPath, 'person_id'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
            self::tenant(
                [...$rootPath, 'person_reference'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
        ];
    }

    /**
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    private static function relationshipReferences(array $rootPath): array
    {
        return [
            ...self::insuranceRelationshipReferences($rootPath),
            self::tenant(
                [...$rootPath, 'relationship_reference'],
                'payroll_employments',
                valuePrefix: 'employment:',
            ),
        ];
    }

    /**
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    private static function insuranceRelationshipReferences(
        array $rootPath,
    ): array {
        return [
            ...self::componentReferences($rootPath),
            self::tenant(
                [
                    ...$rootPath,
                    'participation',
                    'relationship_id',
                ],
                'payroll_employments',
                valuePrefix: 'employment:',
            ),
            self::tenant(
                [...$rootPath, 'relationship_id'],
                'payroll_employments',
                valuePrefix: 'employment:',
            ),
        ];
    }

    /**
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    private static function componentReferences(array $rootPath): array
    {
        $references = [];
        foreach ([
            'excluded_assessment_base_components',
            'excluded_participation_components',
            'included_assessment_base_components',
            'included_participation_components',
        ] as $field) {
            $references[] = self::tenant(
                [...$rootPath, $field, '*'],
                'payroll_inputs',
                valuePrefix: 'input.',
                valueSuffixSeparator: '.',
            );
        }
        return $references;
    }

    /**
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    private static function incomeTaxReferences(array $rootPath): array
    {
        return [
            self::tenant(
                [...$rootPath, 'employee_reference'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
            self::tenant(
                [...$rootPath, 'payer_reference'],
                'supplier',
                valuePrefix: 'supplier:',
            ),
            self::tenant(
                [...$rootPath, 'person_reference'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
            self::tenant(
                [
                    ...$rootPath,
                    'relationships',
                    '*',
                    'relationship_reference',
                ],
                'payroll_employments',
                valuePrefix: 'employment:',
            ),
        ];
    }

    /**
     * @param list<string> $rootPath
     * @return list<array<string,mixed>>
     */
    private static function netPayReferences(array $rootPath): array
    {
        return [
            self::tenant(
                [...$rootPath, 'deductions', '*', 'deduction_reference'],
                'payroll_deduction_agreements',
                valuePrefix: 'agreement:',
            ),
            self::tenant(
                [...$rootPath, 'person_reference'],
                'payroll_employees',
                valuePrefix: 'employee:',
            ),
            self::tenant(
                [
                    ...$rootPath,
                    'relationships',
                    '*',
                    'relationship_reference',
                ],
                'payroll_employments',
                valuePrefix: 'employment:',
            ),
        ];
    }

    /**
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function tenant(
        array $path,
        string $target,
        ?string $valuePrefix = null,
        ?string $valueSuffixSeparator = null,
    ): array {
        return [
            'column' => 'result_snapshot_json',
            'condition' => null,
            'fallbacks' => [],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'path' => $path,
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            ...($valuePrefix === null ? [] : ['value_prefix' => $valuePrefix]),
            ...($valueSuffixSeparator === null ? [] : [
                'value_suffix_separator' => $valueSuffixSeparator,
            ]),
        ];
    }

    /**
     * @param list<array<string,mixed>> $references
     * @return list<array<string,mixed>>
     */
    private static function sortReferences(array $references): array
    {
        usort(
            $references,
            static fn (array $left, array $right): int => strcmp(
                CompanyBackupEmbeddedReference::fromArray(
                    $left,
                    'table:payroll_statutory_person_results',
                )->signature(),
                CompanyBackupEmbeddedReference::fromArray(
                    $right,
                    'table:payroll_statutory_person_results',
                )->signature(),
            ),
        );
        return $references;
    }
}
