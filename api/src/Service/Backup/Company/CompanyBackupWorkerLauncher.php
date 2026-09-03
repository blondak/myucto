<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Odpojí konkrétní perzistentní job do worker procesu. */
interface CompanyBackupWorkerLauncher
{
    public function launch(string $backupId): bool;
}
