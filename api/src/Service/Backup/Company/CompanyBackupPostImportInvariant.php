<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Jedna read-only doménová kontrola obnovené firmy před DB commitem. */
interface CompanyBackupPostImportInvariant
{
    /** Stabilní veřejně bezpečný identifikátor agendy a kontroly. */
    public function id(): string;

    /**
     * Vrátí počet provedených logických kontrol bez business hodnot. Metoda
     * nesmí commitnout ani rollbacknout transakci vlastněnou koordinátorem.
     */
    public function validate(
        PDO $database,
        int $supplierId,
        TenantDataRegistrySnapshot $registry,
    ): int;
}
