<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Runtime metadata cílové tabulky nutná pro bezpečnou rezervaci klíčů. */
final readonly class CompanyBackupImportTableMetadata
{
    public function __construct(
        public ?CompanyBackupAutoIncrementColumn $autoIncrement,
    ) {}
}
