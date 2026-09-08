<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

/** Hash je obsahová identita pouze uvnitř jednoho vlastnického scope. */
final class BankStatementDeduplication
{
    public static function find(PDO $database, string $hash, ?int $supplierId): ?int
    {
        if ($supplierId !== null && $supplierId < 1) {
            throw new \InvalidArgumentException('Neplatný vlastník bankovního výpisu.');
        }
        $statement = $database->prepare(
            'SELECT id FROM bank_statements WHERE dedup_scope_id = ? AND file_hash = ?',
        );
        $statement->execute([$supplierId ?? 0, $hash]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
