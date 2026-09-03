<?php

declare(strict_types=1);

/** Worker jediného uživatelsky založeného exportu přenositelné zálohy firmy. */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Backup\Company\CompanyBackupJobException;
use MyInvoice\Service\Backup\Company\CompanyBackupJobStatus;
use MyInvoice\Service\Backup\Company\CompanyBackupManifestHeader;
use MyInvoice\Service\Backup\Company\CompanyBackupWorker;

$arguments = $argv ?? $_SERVER['argv'] ?? [];
$backupId = null;
foreach (array_slice($arguments, 1) as $argument) {
    if (is_string($argument) && str_starts_with($argument, '--backup-id=')) {
        if ($backupId !== null) {
            fwrite(STDERR, "--backup-id lze uvést pouze jednou.\n");
            exit(2);
        }
        $backupId = substr($argument, strlen('--backup-id='));
        continue;
    }
    fwrite(STDERR, "Použití: php api/bin/company-backup-worker.php --backup-id=UUID\n");
    exit(2);
}
if (!is_string($backupId)
    || !CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
) {
    fwrite(STDERR, "Chybí platné --backup-id=UUID.\n");
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

try {
    $container = Bootstrap::buildContainer();
    $worker = $container->get(CompanyBackupWorker::class);
    if (!$worker instanceof CompanyBackupWorker) {
        throw new \RuntimeException('DI nevrátilo worker zálohy firmy.');
    }
    $status = $worker->run($backupId);
    fwrite(
        STDOUT,
        '[' . date('Y-m-d H:i:s') . '] company-backup '
            . $backupId . ': ' . $status->value . "\n",
    );
    exit(match ($status) {
        CompanyBackupJobStatus::Completed,
        CompanyBackupJobStatus::Queued,
        CompanyBackupJobStatus::Checking,
        CompanyBackupJobStatus::Snapshotting,
        CompanyBackupJobStatus::Packaging => 0,
        CompanyBackupJobStatus::Cancelled => 5,
        CompanyBackupJobStatus::Failed,
        CompanyBackupJobStatus::Expired => 1,
    });
} catch (CompanyBackupJobException $e) {
    fwrite(STDERR, 'Company backup worker selhal (' . $e->errorCode . ").\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "Company backup worker selhal (worker_failed).\n");
    exit(1);
}
