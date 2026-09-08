<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Jedna transakční obnova odvozeného stavu po přemapování všech referencí. */
interface CompanyBackupPostImportMaterializer
{
    /** Stabilní veřejně bezpečný identifikátor materializace. */
    public function id(): string;

    /**
     * Vrátí počet znovu materializovaných řádků. Nesmí převzít ani ukončit
     * transakci vlastněnou koordinátorem obnovy.
     */
    public function materialize(
        PDO $database,
        int $supplierId,
        TenantDataRegistrySnapshot $registry,
    ): int;
}
