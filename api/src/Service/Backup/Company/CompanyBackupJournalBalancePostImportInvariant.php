<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Accounting\JournalEntryBalanceInspector;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Odpírá commit obnovy s nevyváženým nebo prázdným zápisem deníku. */
final readonly class CompanyBackupJournalBalancePostImportInvariant implements
    CompanyBackupPostImportInvariant
{
    public const ENTRY_REGISTRY_KEY = 'table:journal_entries';
    public const LINE_REGISTRY_KEY = 'table:journal_entry_lines';

    public function id(): string
    {
        return 'accounting.journal-balance';
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
            throw self::error('post_import_accounting_journal_context_invalid');
        }
        if (!$this->hasValidDefinitions($registry)) {
            return 0;
        }

        try {
            $finding = JournalEntryBalanceInspector::inspect(
                $database,
                $supplierId,
                1,
            );
        } catch (\Throwable $e) {
            throw self::error(
                'post_import_accounting_journal_check_failed',
                $e,
            );
        }
        self::assertTransaction($database);
        if ($finding['count'] > 0) {
            throw self::error('post_import_accounting_journal_unbalanced');
        }
        return 1;
    }

    private function hasValidDefinitions(
        TenantDataRegistrySnapshot $registry,
    ): bool {
        $entry = $registry->registry->definition(self::ENTRY_REGISTRY_KEY);
        $line = $registry->registry->definition(self::LINE_REGISTRY_KEY);
        if ($entry === null && $line === null) {
            return false;
        }
        foreach ([$entry, $line] as $definition) {
            if (!$definition instanceof TenantDataDefinition
                || $definition->kind !== TenantDataObjectKind::Table
                || $definition->policy !== TenantDataPolicy::TenantOwned
                || !$definition->hasProfile(
                    TenantDataRegistry::COMPANY_BACKUP_PROFILE,
                )
            ) {
                throw self::error(
                    'post_import_accounting_journal_registry_invalid',
                );
            }
        }
        return true;
    }

    /** @phpstan-impure Kontrola nesmí ukončit write transakci obnovy. */
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
            self::ENTRY_REGISTRY_KEY,
            $previous,
        );
    }
}
