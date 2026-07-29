<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Codebooks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;
use MyInvoice\Service\Accounting\Codebooks\AssetImportService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;

/**
 * Import karet majetku z XLSX/CSV (Epic F5 §4.5).
 *   POST /api/accounting/assets/import  (multipart: file, dry_run)
 */
final class AssetsImportAction extends AbstractCodebookImportAction
{
    public function __construct(
        private readonly AssetImportService $service,
        ActivityLogger $logger,
        IpMatcher $ipMatcher,
        Connection $db,
    ) {
        parent::__construct($logger, $ipMatcher, $db);
    }

    protected function importService(): AbstractCodebookImportService
    {
        return $this->service;
    }

    protected function kind(): string
    {
        return 'assets';
    }
}
