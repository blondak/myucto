<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\CompanyBackupSubmissionRecipientsDefinition;

/** Jediná výjimka ze scope podle řádku: vlastní příjemce a použitý systémový číselník. */
final class CompanyBackupSubmissionRecipientsProjection
{
    public const REGISTRY_KEY = 'table:submission_recipients';

    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'supplier_id', 'code', 'name', 'business_id', 'address',
            'kind', 'isds_box_id', 'source_url', 'source_note', 'is_active',
            'verified_in_isds_at', 'created_by', 'created_at', 'updated_at',
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['created_by'], 'target' => 'table:users',
                'target_columns' => ['id'], 'mapping' => 'actor',
                'constraint' => 'required', 'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['supplier_id'], 'target' => 'table:supplier',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => ['supplier_id'],
                'fallbacks' => [],
            ],
        ];
    }

    public static function assertDefinition(TenantDataDefinition $definition): void
    {
        $expected = CompanyBackupSubmissionRecipientsDefinition::definition();
        if ($definition->key !== self::REGISTRY_KEY
            || $definition->policy !== TenantDataPolicy::TenantOwned
            || !hash_equals(
                CanonicalJson::sha256($definition->toArray()),
                CanonicalJson::sha256($expected->toArray()),
            )
        ) {
            throw new CompanyBackupDataSourceException(
                'data_submission_recipients_contract_invalid',
                $definition->key,
            );
        }
    }

    /** @param array<string,mixed> $row */
    public static function rowPolicy(array $row, string $registryKey): TenantDataPolicy
    {
        if ($registryKey !== self::REGISTRY_KEY
            || !array_key_exists('supplier_id', $row)
        ) {
            throw new CompanyBackupDataSourceException(
                'data_submission_recipient_scope_invalid', $registryKey, 'supplier_id',
            );
        }
        if ($row['supplier_id'] === null) {
            return TenantDataPolicy::GlobalReference;
        }
        if (is_int($row['supplier_id']) && $row['supplier_id'] > 0) {
            return TenantDataPolicy::TenantOwned;
        }
        throw new CompanyBackupDataSourceException(
            'data_submission_recipient_scope_invalid', $registryKey, 'supplier_id',
        );
    }
}
