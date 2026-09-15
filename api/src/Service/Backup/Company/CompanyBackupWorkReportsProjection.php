<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Neaktivní kontrakt výkazů práce; aktivace vyžaduje úplný graf faktur. */
final class CompanyBackupWorkReportsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'invoice_id', 'project_id', 'title', 'total_hours',
            'total_amount', 'vat_rate_id', 'material_title', 'material_total',
            'material_vat_rate_id', 'created_at', 'updated_at',
        ];
    }

    /**
     * Vlastnictví vede přes invoice_id. Před aktivací musí import ověřit také
     * shodu klienta zakázky a faktury jako SaveWorkReportAction a
     * SaveWorkReportMaterialsAction; samotné tenantové FK tuto shodu nezaručí.
     * Uložené součty se nepřepočítávají, obě sazby se mapují globálním klíčem.
     *
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            self::reference('invoice_id', 'invoices', CompanyBackupReferenceMapping::TenantId),
            self::reference('material_vat_rate_id', 'vat_rates', CompanyBackupReferenceMapping::GlobalNaturalKey, true),
            self::reference('project_id', 'projects', CompanyBackupReferenceMapping::TenantId, true),
            self::reference('vat_rate_id', 'vat_rates', CompanyBackupReferenceMapping::GlobalNaturalKey, true),
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function reference(
        string $column,
        string $target,
        CompanyBackupReferenceMapping $mapping,
        bool $nullable = false,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => $mapping->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }
}
