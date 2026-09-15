<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;
use PDOException;

/** Neaktivní SQL guard vazby veřejného odkazu na firmu, klienta a zakázku. */
final class CompanyBackupWorkReportLinkRelationGuard
{
    /**
     * Volat po přemapování ID proti cílovému PDO, případně nad zdrojovými ID
     * proti zdrojovému PDO. Archivní ID nelze ověřovat proti cílovým tabulkám.
     * Guard nečte token ani revokaci a nemění historii odkazu.
     *
     * @param array<string,mixed> $metadata Jen supplier_id, client_id, project_id, scope.
     */
    public static function assertValid(PDO $pdo, #[\SensitiveParameter] array $metadata): void
    {
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        if ($keys !== ['client_id', 'project_id', 'scope', 'supplier_id']
            || !self::positiveId($metadata['supplier_id'])
            || !self::positiveId($metadata['client_id'])
        ) {
            throw self::invalid('work_report_link_relation_invalid');
        }
        CompanyBackupWorkReportLinksProjection::validateRow($metadata);
        $scope = $metadata['scope'];
        $projectId = $metadata['project_id'];

        try {
            if ($scope === 'client') {
                $statement = $pdo->prepare(
                    'SELECT 1 FROM clients c WHERE c.id = ? AND c.supplier_id = ? LIMIT 1',
                );
                $params = [$metadata['client_id'], $metadata['supplier_id']];
            } else {
                $statement = $pdo->prepare(
                    'SELECT 1 FROM clients c
                     INNER JOIN projects p ON p.client_id = c.id
                     WHERE c.id = ? AND c.supplier_id = ? AND p.id = ? LIMIT 1',
                );
                $params = [$metadata['client_id'], $metadata['supplier_id'], $projectId];
            }
            if ($statement === false || !$statement->execute($params)) {
                throw self::failed();
            }
            if ($statement->fetchColumn() === false) {
                throw self::invalid('work_report_link_relation_mismatch');
            }
        } catch (PDOException) {
            throw self::failed();
        }
    }

    private static function positiveId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function invalid(string $code): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            $code, CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY, 'scope',
        );
    }

    private static function failed(): CompanyBackupPreflightException
    {
        return self::invalid('work_report_link_relation_lookup_failed');
    }
}
