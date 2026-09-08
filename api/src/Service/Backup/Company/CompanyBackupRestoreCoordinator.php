<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;
use PDOStatement;

/**
 * Vlastní jedinou write transakci obnovy a koordinuje archivní session,
 * staging i živé soubory tak, aby před commitem byla hotová každá kontrola.
 */
final readonly class CompanyBackupRestoreCoordinator
{
    /** @var list<string> */
    private const MYSQL_ISOLATION_LEVELS = [
        'READ-UNCOMMITTED',
        'READ-COMMITTED',
        'REPEATABLE-READ',
        'SERIALIZABLE',
    ];

    private CompanyBackupDatabaseImport $importer;

    private CompanyBackupPostImportValidator $postImport;

    private CompanyBackupFileRestoreStager $stager;

    private CompanyBackupFilePublisher $publisher;

    private CompanyBackupPostImportMaterializerRegistry $materializers;

    public function __construct(
        private PDO $database,
        ?CompanyBackupDatabaseImport $importer = null,
        ?CompanyBackupPostImportValidator $postImport = null,
        ?CompanyBackupFileRestoreStager $stager = null,
        ?CompanyBackupFilePublisher $publisher = null,
        ?CompanyBackupPostImportMaterializerRegistry $materializers = null,
    ) {
        $this->importer = $importer
            ?? new CompanyBackupDatabaseImporter($database);
        $this->postImport = $postImport
            ?? new CompanyBackupRegistryPostImportValidator();
        $this->stager = $stager ?? new CompanyBackupFileRestoreStager();
        $this->publisher = $publisher ?? new CompanyBackupFilePublisher();
        $this->materializers = $materializers
            ?? CompanyBackupPostImportMaterializerRegistry::production();
    }

    public function restore(
        CompanyBackupImportSource $source,
        string $backupId,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupReferenceDecisionPlan $decisions,
        PayrollSensitiveData $sensitiveData,
    ): CompanyBackupRestoreResult {
        if ($this->database->inTransaction()) {
            throw self::error('restore_transaction_nested');
        }

        $staged = null;
        $published = null;
        $transactionStarted = false;
        $transactionAttempted = false;
        $commitSucceeded = false;
        $sourceClosed = false;
        $previousMysqlIsolation = null;
        try {
            $staged = $this->stager->stage($source, $backupId);
            $transactionAttempted = true;
            $this->beginTransaction($previousMysqlIsolation);
            $transactionStarted = true;

            $databaseResult = $this->importer->restore(
                $source,
                $preflight,
                $decisions,
                $sensitiveData,
            );
            $this->assertTransaction('restore_transaction_lost');
            $this->materializers->materialize(
                $this->database,
                $databaseResult->supplierId,
                $source->targetRegistry(),
            );
            $this->assertTransaction('restore_transaction_lost');
            $source->close();
            $sourceClosed = true;
            $this->assertTransaction('restore_transaction_lost');
            $published = $this->publisher->publish(
                $databaseResult->filePublicationPlan,
                $staged,
            );
            $postImport = $this->postImport->validate(
                $this->database,
                $source,
                $preflight,
                $databaseResult,
            );
            $this->assertTransaction('restore_transaction_lost');
            $staged->close();
            $this->assertTransaction('restore_transaction_lost');
            $published->verify();
            $this->assertTransaction('restore_transaction_lost');
            $result = new CompanyBackupRestoreResult(
                $databaseResult,
                $postImport,
                $published->count(),
            );
            $this->restoreMysqlSessionIsolation($previousMysqlIsolation);
            $previousMysqlIsolation = null;
            $this->assertTransaction('restore_transaction_lost');
            if (!$this->database->commit()) {
                throw self::error('restore_commit_failed');
            }
            $commitSucceeded = true;
            $published->release();
            return $result;
        } catch (\Throwable $failure) {
            $databaseSafe = !$transactionStarted;
            $cleanupFailed = false;
            if ($transactionStarted && !$commitSucceeded) {
                $databaseSafe = $this->rollbackStartedTransaction();
            } elseif ($transactionAttempted) {
                $cleanupFailed = !$this->rollbackFailedStart();
            }
            if ($previousMysqlIsolation !== null) {
                try {
                    $this->restoreMysqlSessionIsolation(
                        $previousMysqlIsolation,
                    );
                    $previousMysqlIsolation = null;
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }

            if ($published instanceof CompanyBackupPublishedFileSet) {
                try {
                    if ($databaseSafe && !$commitSucceeded) {
                        $published->rollback();
                    } else {
                        // Nejasný DB výsledek: soubory mohou být již referencované.
                        $published->release();
                    }
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }
            if ($staged instanceof CompanyBackupStagedFileSet) {
                try {
                    $staged->close();
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }
            if (!$sourceClosed) {
                try {
                    $source->close();
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }

            if (!$databaseSafe || $commitSucceeded) {
                throw self::error(
                    'restore_transaction_outcome_unknown',
                    $failure,
                );
            }
            if ($cleanupFailed) {
                throw self::error('restore_cleanup_failed', $failure);
            }
            throw $failure;
        }
    }

    /**
     * Potvrdí rollback známé write transakce. Její předčasné ukončení může
     * znamenat commit, proto se stav bez aktivní transakce nepovažuje za safe.
     */
    private function rollbackStartedTransaction(): bool
    {
        try {
            if (!$this->transactionActive()
                || !$this->database->rollBack()
            ) {
                return false;
            }
            return !$this->transactionActive();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Po neúspěšném startu nebyl import spuštěn; uklidí jen případný begin. */
    private function rollbackFailedStart(): bool
    {
        try {
            if (!$this->transactionActive()) {
                return true;
            }
            if (!$this->database->rollBack()) {
                return false;
            }
            return !$this->transactionActive();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @phpstan-impure PDO transakci mohou změnit volané importní fáze. */
    private function transactionActive(): bool
    {
        return $this->database->inTransaction();
    }

    private function beginTransaction(?string &$previousMysqlIsolation): void
    {
        try {
            $driver = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
            ) {
                throw self::error('restore_transaction_driver_unsupported');
            }
            if ($driver === 'mysql') {
                $isolation = $this->mysqlSessionIsolation();
                if ($isolation !== 'REPEATABLE-READ') {
                    $previousMysqlIsolation = $isolation;
                    if ($this->database->exec(
                        'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                    ) === false
                        || $this->mysqlSessionIsolation()
                            !== 'REPEATABLE-READ'
                    ) {
                        throw self::error('restore_transaction_start_failed');
                    }
                }
            }
            if (!$this->database->beginTransaction()
                || !$this->database->inTransaction()
            ) {
                throw self::error('restore_transaction_start_failed');
            }
        } catch (CompanyBackupRestoreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error('restore_transaction_start_failed', $e);
        }
    }

    private function restoreMysqlSessionIsolation(?string $isolation): void
    {
        if ($isolation === null) {
            return;
        }
        if (!in_array($isolation, self::MYSQL_ISOLATION_LEVELS, true)) {
            throw self::error('restore_transaction_isolation_restore_failed');
        }
        $sqlLevel = str_replace('-', ' ', $isolation);
        try {
            if ($this->database->exec(
                'SET SESSION TRANSACTION ISOLATION LEVEL ' . $sqlLevel,
            ) === false
                || $this->mysqlSessionIsolation() !== $isolation
            ) {
                throw self::error(
                    'restore_transaction_isolation_restore_failed',
                );
            }
        } catch (CompanyBackupRestoreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error(
                'restore_transaction_isolation_restore_failed',
                $e,
            );
        }
    }

    private function mysqlSessionIsolation(): string
    {
        $statement = $this->database->query(
            'SELECT @@SESSION.transaction_isolation',
        );
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException(
                'Izolaci databázové session nelze přečíst.',
            );
        }
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            throw new \RuntimeException(
                'Izolace databázové session není platná.',
            );
        }
        $isolation = strtoupper($value);
        if (!in_array($isolation, self::MYSQL_ISOLATION_LEVELS, true)) {
            throw new \RuntimeException(
                'Izolace databázové session není podporovaná.',
            );
        }
        return $isolation;
    }

    /** @phpstan-impure Importní fáze nesmí ukončit koordinátorovu transakci. */
    private function assertTransaction(string $errorCode): void
    {
        if (!$this->database->inTransaction()) {
            throw self::error($errorCode);
        }
    }

    private static function error(
        string $errorCode,
        ?\Throwable $previous = null,
    ): CompanyBackupRestoreException {
        return new CompanyBackupRestoreException($errorCode, $previous);
    }
}
