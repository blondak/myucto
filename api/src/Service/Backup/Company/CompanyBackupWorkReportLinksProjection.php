<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/**
 * Neaktivní kontrakt trvalých veřejných odkazů na výkazy práce. Token je
 * autentizační schopnost a patří pouze do šifrované secret envelope.
 */
final class CompanyBackupWorkReportLinksProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'supplier_id', 'scope', 'client_id', 'project_id',
            'created_by_user_id', 'created_at', 'last_sent_at', 'last_viewed_at',
            'revoked_at',
        ];
    }

    /** @return array<string,array{policy:string,storage:string}> */
    public static function secrets(): array
    {
        return ['token' => [
            'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
            'storage' => CompanyBackupSecretStorage::Raw->value,
        ]];
    }

    /**
     * @return list<array{
     *   materializer:string,secret_column:string,tenant_id_column:string,
     *   nullable:bool,bytes:int
     * }>
     */
    public static function protectedSecretMaterializations(): array
    {
        return [[
            'materializer' => 'raw_bytes_v1',
            'secret_column' => 'token',
            'tenant_id_column' => 'supplier_id',
            'nullable' => false,
            'bytes' => 48,
        ]];
    }

    /**
     * Všechny reference jsou logické: fyzická tabulka nemá FK. Scalar scope
     * kontrola neprokazuje, že projekt patří danému klientovi a oba cílové
     * záznamy dané firmě. To musí před aktivací ověřit relační SQL preflight.
     * Token zůstává původní včetně revokace a časových údajů; kolize řeší
     * samostatná politika svázaná s preflightem a INSERT.
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
            self::tenant('client_id', 'clients'),
            self::actor('created_by_user_id'),
            self::tenant('project_id', 'projects', true),
            self::tenant('supplier_id', 'supplier'),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function validateRow(array $row): void
    {
        $scope = $row['scope'] ?? null;
        if ($scope !== 'client' && $scope !== 'project') {
            throw self::invalid('work_report_link_scope_invalid', 'scope');
        }
        $projectId = $row['project_id'] ?? null;
        if (($scope === 'client' && $projectId !== null)
            || ($scope === 'project' && (!is_int($projectId) || $projectId < 1))
        ) {
            throw self::invalid('work_report_link_scope_invalid', 'project_id');
        }
    }

    public static function validateToken(#[\SensitiveParameter] mixed $token): void
    {
        CompanyBackupWorkReportLinkPolicy::restoreToken($token, false);
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function tenant(string $column, string $target, bool $nullable = false): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function actor(string $column): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => [$column],
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }

    private static function invalid(string $code, string $column): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            $code, CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY, $column,
        );
    }
}
