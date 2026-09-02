<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní odpověď pro neplatný nebo neuspokojitelný HTTP Range. */
final class CompanyBackupDownloadRangeException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(public readonly int $totalBytes)
    {
        $this->errorCode = 'range_not_satisfiable';
        parent::__construct('Požadovaný rozsah archivu nelze poskytnout.');
    }

    public function contentRange(): string
    {
        return 'bytes */' . $this->totalBytes;
    }
}
