<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;

/** Stejná priorita jako runtime čtení importovaných vydaných a přijatých PDF.
 * Vyžaduje explicitní zapojení se skutečnou konfigurací cílové instance.
 */
final readonly class CompanyBackupConfiguredFileAreaRootResolver implements CompanyBackupFileAreaRootResolver
{
    public function __construct(private Config $config) {}

    public function resolve(string $storageSubdirectory): string
    {
        $key = match ($storageSubdirectory) {
            'invoices-imported' => 'invoice.import_archive_storage',
            'purchase-invoices' => 'purchase_invoice.archive_storage',
            default => null,
        };
        $root = '';
        if ($key !== null) {
            $root = (string) $this->config->get($key, '');
            if ($root === '') {
                $uploads = (string) $this->config->get('storage.uploads_dir', '');
                if (str_contains($uploads, "\0")) {
                    throw new \InvalidArgumentException('Kořen archivu není platný.');
                }
                if ($uploads !== '') {
                    $root = dirname($uploads) . '/' . $storageSubdirectory;
                }
            }
        }
        if ($root === '') {
            $root = (new CompanyBackupRuntimeFileAreaRootResolver())->resolve($storageSubdirectory);
        }
        if ($root === '' || str_contains($root, "\0")) {
            throw new \InvalidArgumentException('Kořen archivu není platný.');
        }
        return $root;
    }
}
