<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\BackgroundProcess;
use Psr\Log\LoggerInterface;

/** Multiplatformní launcher sdílející ověřený BackgroundProcess mechanismus. */
final readonly class BackgroundCompanyBackupWorkerLauncher implements
    CompanyBackupWorkerLauncher
{
    public function __construct(private LoggerInterface $logger) {}

    public function launch(string $backupId): bool
    {
        if (!CompanyBackupManifestHeader::isCanonicalBackupId($backupId)) {
            throw new \InvalidArgumentException(
                'Identifikátor spouštěného zálohového jobu není platný.',
            );
        }

        $root = Bootstrap::rootDir();
        $script = $root . '/api/bin/company-backup-worker.php';
        if (!is_file($script)) {
            $this->logger->error(
                'Worker zálohy firmy nemá spustitelný vstupní bod.',
                ['backup_id' => $backupId],
            );
            return false;
        }

        $logDirectory = RuntimePaths::log();
        if (!is_dir($logDirectory)
            && !@mkdir($logDirectory, 0750, true)
            && !is_dir($logDirectory)
        ) {
            $this->logger->error(
                'Worker zálohy firmy nemá dostupný adresář logu.',
                ['backup_id' => $backupId],
            );
            return false;
        }

        $spawned = BackgroundProcess::spawnPhp(
            $script,
            ['--backup-id=' . $backupId],
            RuntimePaths::log('company-backup-worker.log'),
            $root,
            $diagnostic,
        );
        if (!$spawned) {
            $this->logger->error(
                'Spuštění workeru zálohy firmy selhalo.',
                ['backup_id' => $backupId, 'diagnostic' => $diagnostic],
            );
        }
        return $spawned;
    }
}
