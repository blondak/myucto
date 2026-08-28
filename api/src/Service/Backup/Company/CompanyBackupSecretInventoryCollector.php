<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Spočítá úplný secret inventář uvnitř stejného read view jako strojová data. */
final readonly class CompanyBackupSecretInventoryCollector
{
    public function __construct(
        private CompanyBackupSecretOmissionCountSource $source =
            new CompanyBackupSqlSecretOmissionCountSource(),
    ) {}

    public function collect(
        PDO $snapshot,
        TenantDataRegistrySnapshot $registry,
        int $supplierId,
    ): CompanyBackupSecretInventory {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException(
                'Firma secret inventáře musí mít kladné ID.',
            );
        }
        $byRegistryKey = [];
        foreach (CompanyBackupSecretInventory::requiredDeclarations($registry) as $item) {
            $byRegistryKey[$item->registryKey][] = $item;
        }

        $counts = [];
        foreach ($byRegistryKey as $registryKey => $declarations) {
            $definition = $registry->registry->definition($registryKey);
            if ($definition === null) {
                throw new \LogicException('Secret deklarace ztratila registry objekt.');
            }
            $measured = $this->source->counts(
                $snapshot,
                $supplierId,
                $definition,
                $declarations,
                $registry->registry,
            );
            $expectedSignatures = array_map(
                static fn (CompanyBackupSecretDeclaration $item): string =>
                    $item->signature(),
                $declarations,
            );
            $actualSignatures = array_keys($measured);
            sort($actualSignatures, SORT_STRING);
            if ($actualSignatures !== $expectedSignatures) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_scope_mismatch',
                    $registryKey,
                );
            }
            foreach ($measured as $signature => $count) {
                if ($count < 0 || isset($counts[$signature])) {
                    throw new CompanyBackupDataSourceException(
                        'secret_count_invalid',
                        $registryKey,
                    );
                }
                $counts[$signature] = $count;
            }
        }
        ksort($counts, SORT_STRING);
        return CompanyBackupSecretInventory::fromCounts($counts, $registry);
    }
}
