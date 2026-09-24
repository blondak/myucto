<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;

/** Zámek firmy pro převod bez vlastní tabulky běhů. */
final class MigrationCompanyLock
{
    public function __construct(private readonly Connection $db) {}

    public function acquire(string $source, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT GET_LOCK(CONCAT(?, ':', DATABASE(), ':', ?), 0)");
        $stmt->execute([$source, $supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function release(string $source, int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare("SELECT RELEASE_LOCK(CONCAT(?, ':', DATABASE(), ':', ?))");
        $stmt->execute([$source, $supplierId]);
    }
}
