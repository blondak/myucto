<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Bank\Match\BankMatchArchivedDocuments;
use PDO;

/** Pouze exportní transformace nad týmž snapshotem; nikdy nepřepisuje zdrojovou DB. */
final readonly class CompanyBackupBankHistoryRowSource implements CompanyBackupDataRowSource
{
    public function __construct(private CompanyBackupDataRowSource $source, private string $backupId) {}

    /** @return iterable<mixed,array<string,mixed>> */
    public function rows(PDO $snapshot, int $supplierId, TenantDataDefinition $definition): iterable
    {
        $rows = $this->source->rows($snapshot, $supplierId, $definition);
        if (!in_array($definition->key, ['table:bank_match_audit', 'table:bank_match_suggestions'], true)) {
            return $rows;
        }
        return $this->archiveRows($snapshot, $supplierId, $definition, $rows);
    }

    /**
     * @param iterable<mixed,array<string,mixed>> $rows
     * @return iterable<int,array<string,mixed>>
     */
    private function archiveRows(PDO $snapshot, int $supplierId, TenantDataDefinition $definition, iterable $rows): iterable
    {
        foreach ($rows as $row) {
            try {
                if (($row['supplier_id'] ?? null) !== $supplierId) {
                    throw new \InvalidArgumentException('Historie má jiného vlastníka.');
                }
                yield BankMatchArchivedDocuments::detachMissing(substr($definition->key, 6), $row,
                    $this->backupId, static function (string $table, int $id) use ($snapshot): ?int {
                        if (!in_array($table, ['invoices', 'purchase_invoices'], true)) {
                            throw new \LogicException('Nepovolený cíl historického dokladu.');
                        }
                        $query = $snapshot->prepare('SELECT supplier_id FROM `' . $table . '` WHERE id = ?');
                        $query->execute([$id]);
                        $owner = $query->fetchColumn();
                        $query->closeCursor();
                        return $owner === false ? null : (int) $owner;
                    });
            } catch (\Throwable $e) {
                throw new CompanyBackupDataSourceException('data_bank_history_invalid',
                    $definition->key, BankMatchArchivedDocuments::COLUMN, $e);
            }
        }
    }
}
