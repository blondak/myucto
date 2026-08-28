<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Ověřený runtime tvar jedné fyzické tabulky MariaDB. */
final readonly class CompanyBackupTableSchema
{
    /**
     * @param list<string> $columns
     * @param list<string> $generatedColumns
     * @param list<string> $primaryKey
     * @param list<string> $binaryColumns
     */
    public function __construct(
        public array $columns,
        public array $generatedColumns,
        public array $primaryKey,
        public array $binaryColumns,
    ) {}
}
