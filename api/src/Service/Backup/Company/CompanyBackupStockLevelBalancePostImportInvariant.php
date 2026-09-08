<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Stock\StockLevelBalanceInspector;
use PDO;

/** Odpírá commit obnovy s materializací odlišnou od skladové knihy. */
final readonly class CompanyBackupStockLevelBalancePostImportInvariant implements
    CompanyBackupPostImportInvariant
{
    public const LEVEL_REGISTRY_KEY = 'table:stock_levels';
    public const DOCUMENT_REGISTRY_KEY = 'table:stock_documents';
    public const LINE_REGISTRY_KEY = 'table:stock_document_lines';

    public function id(): string
    {
        return 'stock.level-balance';
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
            throw self::error('post_import_stock_levels_context_invalid');
        }
        if (!$this->hasValidDefinitions($registry)) {
            return 0;
        }

        try {
            $finding = StockLevelBalanceInspector::inspect(
                $database,
                $supplierId,
                1,
            );
        } catch (\Throwable $e) {
            throw self::error('post_import_stock_levels_check_failed', $e);
        }
        self::assertTransaction($database);
        if ($finding['count'] === 0) {
            return 1;
        }

        $reason = $finding['sample'][0]['reason'] ?? null;
        throw self::error(
            $reason === StockLevelBalanceInspector::REASON_AVERAGE_INVALID
                ? 'post_import_stock_levels_average_invalid'
                : 'post_import_stock_levels_mismatch',
        );
    }

    private function hasValidDefinitions(
        TenantDataRegistrySnapshot $registry,
    ): bool {
        $definitions = [
            $registry->registry->definition(self::LEVEL_REGISTRY_KEY),
            $registry->registry->definition(self::DOCUMENT_REGISTRY_KEY),
            $registry->registry->definition(self::LINE_REGISTRY_KEY),
        ];
        if ($definitions === [null, null, null]) {
            return false;
        }
        foreach ($definitions as $definition) {
            if (!$definition instanceof TenantDataDefinition
                || $definition->kind !== TenantDataObjectKind::Table
                || $definition->policy !== TenantDataPolicy::TenantOwned
                || !$definition->hasProfile(
                    TenantDataRegistry::COMPANY_BACKUP_PROFILE,
                )
            ) {
                throw self::error('post_import_stock_levels_registry_invalid');
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
            self::LEVEL_REGISTRY_KEY,
            $previous,
        );
    }
}
