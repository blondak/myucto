<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataCoverageIssue;
use MyInvoice\Service\Backup\Registry\TenantDataCoverageReport;

/** Fail-closed chyba cílového registru před otevřením nahraného archivu. */
final class CompanyBackupTechnicalValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly TenantDataCoverageReport $coverage,
    ) {
        parent::__construct(
            $errorCode . ': ' . implode(', ', array_map(
                static fn (TenantDataCoverageIssue $issue): string => $issue->code
                    . ':' . $issue->object,
                $coverage->issues,
            )),
        );
    }
}
