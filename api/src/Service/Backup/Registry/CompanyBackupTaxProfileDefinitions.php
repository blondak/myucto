<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Roční vstupy bez přepočtu odpočtů, příjmů nebo měsíčního pořadí dětí. */
final class CompanyBackupTaxProfileDefinitions
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        $columns = [
            'tax_profiles' => ['id', 'supplier_id', 'year', 'activity_rate', 'use_actual_expenses',
                'actual_expenses', 'flat_tax_band', 'is_secondary', 'spouse_credit', 'children_count',
                'mortgage_interest', 'mortgage_pre_2021', 'mortgage_months', 'pension_contrib',
                'life_insurance', 'dip_contrib', 'long_term_care', 'disability_12_months',
                'disability_3_months', 'ztpp_months', 'donations', 'created_at', 'updated_at',
                'sickness_insured', 'sickness_monthly_base'],
            'tax_profile_activities' => ['id', 'supplier_id', 'year', 'name', 'nace_code', 'expense_mode',
                'expense_rate', 'income_amount', 'expense_amount', 'active_months', 'allocation_note', 'order_index'],
            // evidence_ref je uživatelský volný text, nikoli ID či spravovaná cesta DMS.
            'tax_profile_children' => ['id', 'supplier_id', 'year', 'first_name', 'last_name', 'birth_number',
                'birth_date', 'shared_household_proved', 'other_parent_not_claimed_proved', 'evidence_ref', 'order_index'],
            'tax_profile_child_months' => ['child_id', 'month', 'child_order', 'ztpp', 'claimed'],
            'tax_profile_spouse_claims' => ['id', 'supplier_id', 'year', 'first_name', 'last_name',
                'birth_number', 'birth_date', 'eligible_months', 'ztpp', 'own_income', 'income_proved',
                'shared_household_proved', 'child_under_three_proved', 'evidence_ref'],
        ];
        $definitions = [];
        foreach ($columns as $table => $dataColumns) {
            $months = $table === 'tax_profile_child_months';
            $naturalKey = in_array($table, ['tax_profiles', 'tax_profile_spouse_claims'], true)
                ? ['supplier_id', 'year'] : null;
            $definitions[] = new TenantDataDefinition(
                'table:' . $table, TenantDataObjectKind::Table,
                $months ? TenantDataPolicy::TenantOwnedIndirect : TenantDataPolicy::TenantOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => $months ? ['child_id', 'month'] : ['id'],
                    ...($naturalKey === null ? [] : ['natural_key' => $naturalKey]),
                    'feature_group' => 'tax',
                    // Rok je hodnota profilu, ne ID: historické osiřelé vstupy se nezahazují.
                    'ownership' => $months ? ['strategy' => 'foreign_key_path', 'path' => [
                        ['from_column' => 'child_id', 'to_table' => 'tax_profile_children', 'to_column' => 'id'],
                        ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
                    ]] : ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => $dataColumns, 'generated_columns' => [],
                        'omit_columns' => [], 'embedded_references' => [], 'restore_overrides' => [],
                        'references' => [[
                            'columns' => [$months ? 'child_id' : 'supplier_id'],
                            'target' => $months ? 'table:tax_profile_children' : 'table:supplier',
                            'target_columns' => ['id'], 'mapping' => 'tenant_id',
                            'constraint' => 'required', 'nullable_columns' => [], 'fallbacks' => [],
                        ]],
                    ],
                ],
            );
        }
        return $definitions;
    }
}
