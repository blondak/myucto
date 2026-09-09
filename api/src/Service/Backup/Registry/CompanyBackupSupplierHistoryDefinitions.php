<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Historická účinnost se přenáší beze změny; aktuální kořen ji nenahrazuje. */
final class CompanyBackupSupplierHistoryDefinitions
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        $columns = [
            'supplier_accounting_modes' => ['id', 'supplier_id', 'effective_from', 'accounting_mode', 'created_at'],
            'supplier_osvc_month_statuses' => ['id', 'supplier_id', 'year', 'month', 'activity_status',
                'social_participates', 'health_minimum_applies', 'state_insured', 'employed',
                'new_osvc', 'assessment_base', 'note'],
            'supplier_vat_status_history' => ['id', 'supplier_id', 'effective_from', 'is_vat_payer',
                'is_identified', 'annual_deduction_percent', 'created_at', 'created_by', 'note'],
            'tax_advance_overrides' => ['id', 'supplier_id', 'taxpayer_type', 'advance_kind',
                'period_year', 'effective_from', 'effective_to', 'amount', 'periodicity', 'note',
                'source', 'created_at', 'updated_at'],
        ];
        $definitions = [];
        foreach ($columns as $table => $dataColumns) {
            $references = [];
            if ($table === 'supplier_vat_status_history') {
                $references[] = [
                    'columns' => ['created_by'], 'target' => 'table:users',
                    'target_columns' => ['id'], 'mapping' => 'actor',
                    'constraint' => 'optional', 'nullable_columns' => ['created_by'],
                    'fallbacks' => ['null', 'restore_actor'],
                ];
            }
            $references[] = [
                'columns' => ['supplier_id'], 'target' => 'table:supplier',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => [], 'fallbacks' => [],
            ];
            $definitions[] = new TenantDataDefinition(
                'table:' . $table, TenantDataObjectKind::Table, TenantDataPolicy::TenantOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'natural_key' => match ($table) {
                        'supplier_osvc_month_statuses' => ['supplier_id', 'year', 'month'],
                        'tax_advance_overrides' => ['supplier_id', 'taxpayer_type', 'advance_kind', 'period_year', 'effective_from'],
                        default => ['supplier_id', 'effective_from'],
                    },
                    'feature_group' => 'tax',
                    'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => $dataColumns, 'generated_columns' => [],
                        'omit_columns' => [], 'embedded_references' => [],
                        'references' => $references, 'restore_overrides' => [],
                    ],
                ],
            );
        }
        return $definitions;
    }
}
