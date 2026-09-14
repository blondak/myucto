<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/**
 * Kontrakt historické gateway relace; do registru jej lze zapnout až po
 * vyřešení globálního app_token a návazné pojistky submission_outbox.
 */
final class CompanyBackupIsdsGatewaySessionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'environment',
            'outbox_id',
            'user_id',
            'app_token',
            'state',
            'concept_id',
            'concept_dm_id',
            'concept_status_code',
            'concept_status_message',
            'payload_sha256',
            'correlation_reference',
            'error_code',
            'error_message',
            'expires_at',
            'started_at',
            'concept_pushed_at',
            'finished_at',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<string> */
    public static function generatedColumns(): array
    {
        return ['active_outbox_id'];
    }

    /** @return list<string> */
    public static function preservedIdentifiers(): array
    {
        return ['concept_dm_id', 'concept_id'];
    }

    /** @return array<string,array{policy:string,reason:string}> */
    public static function secretPolicies(): array
    {
        return [
            'app_token' => [
                'policy' => TenantSecretPolicy::NotSecret->value,
                'reason' => 'isds_callback_correlation_not_authentication',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['supplier_id', 'outbox_id'],
                'target' => 'table:submission_outbox',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['user_id'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function restoreOverrides(): array
    {
        return CompanyBackupIsdsRestorePolicy::gatewayOverrides();
    }

}
