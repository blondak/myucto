<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Jednorázová read-only transakce s okamžitým repeatable-read snapshotem. */
final class CompanyBackupSnapshotTransaction
{
    private const ISOLATION_SQL = 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ';
    private const START_SQL = 'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT';

    /**
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    public function run(PDO $pdo, callable $callback): mixed
    {
        if (self::transactionActive($pdo)) {
            throw new CompanyBackupSnapshotException('snapshot_transaction_nested');
        }

        try {
            if ($pdo->exec(self::ISOLATION_SQL) === false
                || $pdo->exec(self::START_SQL) === false
                || !self::transactionActive($pdo)
            ) {
                throw new CompanyBackupSnapshotException('snapshot_start_failed');
            }
        } catch (\Throwable $e) {
            if ($e instanceof CompanyBackupSnapshotException) {
                throw $e;
            }
            throw new CompanyBackupSnapshotException('snapshot_start_failed', $e);
        }

        try {
            $result = $callback($pdo);
            if (!self::transactionActive($pdo)) {
                throw new CompanyBackupSnapshotException('snapshot_transaction_lost');
            }
            if (!$pdo->commit()) {
                throw new CompanyBackupSnapshotException('snapshot_commit_failed');
            }
            return $result;
        } catch (\Throwable $e) {
            if (self::transactionActive($pdo)) {
                try {
                    if (!$pdo->rollBack()) {
                        throw new \RuntimeException('PDO rollback vrátil false.');
                    }
                } catch (\Throwable) {
                    throw new CompanyBackupSnapshotException(
                        'snapshot_rollback_failed',
                        $e,
                    );
                }
            }
            throw $e;
        }
    }

    /** @phpstan-impure Callback, commit i rollback mění stav PDO transakce. */
    private static function transactionActive(PDO $pdo): bool
    {
        return $pdo->inTransaction();
    }
}
