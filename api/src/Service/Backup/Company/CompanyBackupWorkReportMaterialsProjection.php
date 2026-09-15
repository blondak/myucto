<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Neaktivní kontrakt řádků materiálu bez přepočtu uložených hodnot. */
final class CompanyBackupWorkReportMaterialsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'work_report_id', 'description', 'quantity', 'unit',
            'unit_price', 'total_amount', 'order_index',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            self::tenant('work_report_id', 'work_reports'),
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function tenant(string $column, string $target): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
