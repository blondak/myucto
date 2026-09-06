<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Opakovatelný stream souborů z technicky ověřeného inventáře archivu. */
interface CompanyBackupImportFileSource
{
    public function fileInventory(): CompanyBackupFileInventory;

    /** @param callable(string):void $chunkVisitor */
    public function consumeFile(
        string $archivePath,
        callable $chunkVisitor,
    ): int;
}
