<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;

/** Povinná DB coverage brána spuštěná uvnitř konzistentního snapshotu. */
interface CompanyBackupDatabaseCoverageGate
{
    public function assertSafe(PDO $pdo, TenantDataRegistry $registry): void;
}
