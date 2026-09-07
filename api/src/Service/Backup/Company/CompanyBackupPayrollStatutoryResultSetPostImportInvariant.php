<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Ověří kořenové pečetě zákonných výsledků nad obnovenými fyzickými řádky. */
final readonly class CompanyBackupPayrollStatutoryResultSetPostImportInvariant
    implements CompanyBackupPostImportInvariant
{
    public function __construct(
        private CompanyBackupDataRowSource $rows = new CompanyBackupSqlRowSource(),
        private CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    public function id(): string
    {
        return 'payroll.statutory-result-set';
    }

    public function validate(
        PDO $database,
        int $supplierId,
        TenantDataRegistrySnapshot $registry,
    ): int {
        self::assertTransaction($database);
        if ($supplierId < 1
            || $registry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
        ) {
            throw self::error('post_import_payroll_statutory_context_invalid');
        }

        $definitions = $this->definitions($registry);
        if ($definitions === null) {
            return 0;
        }

        $index = null;
        $checkCount = null;
        $failure = null;
        try {
            $index = new CompanyBackupPayrollStatutoryResultSetSourceIndex(
                $database,
                $this->limits,
            );
            $checkedRows = 0;
            foreach ($this->rows->rows(
                $database,
                $supplierId,
                $definitions['person'],
            ) as $row) {
                $this->countRow($checkedRows);
                $index->addPerson($row);
            }
            foreach ($this->rows->rows(
                $database,
                $supplierId,
                $definitions['relationship'],
            ) as $row) {
                $this->countRow($checkedRows);
                $index->addRelationship($row);
            }
            $index->seal();

            $rootCount = 0;
            foreach ($this->rows->rows(
                $database,
                $supplierId,
                $definitions['root'],
            ) as $row) {
                $this->countRow($checkedRows);
                $index->assertSourceHeader($row);
                $rootCount++;
            }
            $index->finish($rootCount);
            self::assertTransaction($database);
            $checkCount = $checkedRows;
        } catch (\Throwable $e) {
            $failure = $e instanceof CompanyBackupPostImportException
                ? $e
                : self::error(
                    'post_import_payroll_statutory_result_set_invalid',
                    $e,
                );
        }

        if ($index instanceof CompanyBackupPayrollStatutoryResultSetSourceIndex) {
            try {
                $index->close();
            } catch (\Throwable $e) {
                $failure ??= self::error(
                    'post_import_payroll_statutory_cleanup_failed',
                    $e,
                );
            }
        }
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        self::assertTransaction($database);
        if (!is_int($checkCount)) {
            throw new \LogicException(
                'Post-import kontrola zákonných výsledků nevytvořila výsledek.',
            );
        }
        return $checkCount;
    }

    /**
     * @return null|array{
     *   root:TenantDataDefinition,
     *   person:TenantDataDefinition,
     *   relationship:TenantDataDefinition
     * }
     */
    private function definitions(
        TenantDataRegistrySnapshot $registry,
    ): ?array {
        $root = $registry->registry->definition(
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
        );
        $person = $registry->registry->definition(
            CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY,
        );
        $relationship = $registry->registry->definition(
            CompanyBackupPayrollStatutoryResultSetAssembler::RELATIONSHIP_REGISTRY_KEY,
        );
        if ($root === null && $person === null && $relationship === null) {
            return null;
        }
        if (!$root instanceof TenantDataDefinition
            || !$person instanceof TenantDataDefinition
            || !$relationship instanceof TenantDataDefinition
        ) {
            throw self::error('post_import_payroll_statutory_registry_invalid');
        }
        foreach ([$root, $person, $relationship] as $definition) {
            if ($definition->kind !== TenantDataObjectKind::Table
                || $definition->policy !== TenantDataPolicy::TenantOwned
                || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            ) {
                throw self::error(
                    'post_import_payroll_statutory_registry_invalid',
                );
            }
        }
        return [
            'root' => $root,
            'person' => $person,
            'relationship' => $relationship,
        ];
    }

    private function countRow(int &$count): void
    {
        if ($count >= $this->limits->maxSourceIdentities) {
            throw self::error('post_import_payroll_statutory_limit_exceeded');
        }
        $count++;
    }

    /** @phpstan-impure Temporary DDL ani čtení nesmí ukončit write transakci. */
    private static function assertTransaction(PDO $database): void
    {
        if (!$database->inTransaction()) {
            throw self::error('post_import_transaction_lost');
        }
    }

    private static function error(
        string $errorCode,
        ?\Throwable $previous = null,
    ): CompanyBackupPostImportException {
        return new CompanyBackupPostImportException(
            $errorCode,
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
            $previous,
        );
    }
}
