<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/**
 * Jediná povolená dopředná povinná vazba při INSERTu kořene firmy.
 * Na rozdíl od ArchiveRestoreService má company import předem rezervovanou
 * cílovou měnu; nevkládá zdrojové ID a nepotřebuje následný opravný UPDATE.
 */
final class CompanyBackupSupplierCurrencyCycle
{
    public static function matches(CompanyBackupImportDependency $dependency): bool
    {
        return $dependency->sourceRegistryKey === 'table:supplier'
            && $dependency->targetRegistryKey === 'table:currencies'
            && $dependency->kind === CompanyBackupImportDependencyKind::Column
            && $dependency->signature === 'default_currency_id->currencies:id'
            && !$dependency->deferred;
    }

    /** @param callable():void $insert Pouze jeden připravený INSERT nového supplier. */
    public static function insert(PDO $database, callable $insert): void
    {
        if (!$database->inTransaction() || $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new CompanyBackupImportWriteException('import_supplier_cycle_transaction_required', 'table:supplier');
        }
        if (!self::checksEnabled($database)) {
            throw new CompanyBackupImportWriteException('import_supplier_cycle_checks_disabled', 'table:supplier');
        }
        try {
            if ($database->exec('SET SESSION FOREIGN_KEY_CHECKS = 0') === false) {
                throw new CompanyBackupImportWriteException('import_supplier_cycle_checks_unavailable', 'table:supplier');
            }
            $insert();
        } finally {
            try {
                if ($database->exec('SET SESSION FOREIGN_KEY_CHECKS = 1') === false
                    || !self::checksEnabled($database)) {
                    throw new \RuntimeException('FK kontroly nebyly obnoveny.');
                }
            } catch (\Throwable $e) {
                self::rollbackIfActive($database);
                throw new CompanyBackupImportWriteException('import_supplier_cycle_checks_restore_failed',
                    'table:supplier', previous: $e);
            }
        }
    }

    public static function assertComplete(PDO $database, int $supplierId, CompanyBackupTableReferenceSchema $references): void
    {
        if (!$database->inTransaction()
            || !self::checksEnabled($database)) {
            throw new CompanyBackupImportWriteException('import_supplier_cycle_checks_disabled', 'table:supplier');
        }
        $query = $database->prepare('SELECT c.supplier_id FROM supplier s
            LEFT JOIN currencies c ON c.id = s.default_currency_id WHERE s.id = ?');
        $query->execute([$supplierId]);
        $owner = $query->fetchColumn();
        $query->closeCursor();
        if ($owner === false || $owner === null || (int) $owner !== $supplierId) {
            throw new CompanyBackupImportWriteException('import_supplier_currency_unresolved', 'table:supplier');
        }
        // Zapnutí FOREIGN_KEY_CHECKS nevaliduje zpětně řádky vložené s vypnutou kontrolou.
        foreach ($references->foreignKeys as $reference) {
            $columns = array_map(static fn (string $column): string => '`' . $column . '`', $reference->columns);
            $query = $database->prepare('SELECT ' . implode(', ', $columns) . ' FROM supplier WHERE id = ?');
            $query->execute([$supplierId]);
            $values = $query->fetch(PDO::FETCH_NUM);
            $query->closeCursor();
            if (!is_array($values)) {
                throw new CompanyBackupImportWriteException('import_supplier_currency_unresolved', 'table:supplier');
            }
            if (in_array(null, $values, true)) {
                continue;
            }
            $conditions = [];
            foreach ($reference->columns as $index => $column) {
                $conditions[] = '`' . $reference->targetColumns[$index] . '` = ?';
            }
            // Current read a sdílený zámek nahradí ochranu cíle přeskočenou při INSERTu.
            // Prostý snapshotový SELECT by mohl potvrdit už souběžně smazaný číselník.
            try {
                $query = $database->prepare('SELECT 1 FROM `' . $reference->targetTable . '` WHERE '
                    . implode(' AND ', $conditions) . ' LOCK IN SHARE MODE');
                $query->execute($values);
                $invalid = $query->fetchColumn() === false;
                $query->closeCursor();
            } catch (\PDOException $e) {
                throw new CompanyBackupImportWriteException('import_supplier_foreign_key_check_failed',
                    'table:supplier', $reference->columns[0], previous: $e);
            }
            if ($invalid) {
                throw new CompanyBackupImportWriteException('import_supplier_foreign_key_unresolved',
                    'table:supplier', $reference->columns[0]);
            }
        }
    }

    /** @phpstan-impure */
    private static function checksEnabled(PDO $database): bool
    {
        $query = $database->query('SELECT @@SESSION.foreign_key_checks');
        if ($query === false) {
            throw new CompanyBackupImportWriteException('import_supplier_cycle_checks_unavailable', 'table:supplier');
        }
        $value = $query->fetchColumn();
        $query->closeCursor();
        return (int) $value === 1;
    }

    private static function rollbackIfActive(PDO $database): void
    {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
    }
}
