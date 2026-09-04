<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final class CompanyBackupImportPlanException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $registryKey = null,
        public readonly ?string $targetRegistryKey = null,
        ?\Throwable $previous = null,
    ) {
        $parts = [$errorCode];
        if ($registryKey !== null) {
            $parts[] = $registryKey;
        }
        if ($targetRegistryKey !== null) {
            $parts[] = $targetRegistryKey;
        }
        parent::__construct(implode(': ', $parts), 0, $previous);
    }
}
