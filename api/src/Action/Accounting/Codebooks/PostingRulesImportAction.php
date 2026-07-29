<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Codebooks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;
use MyInvoice\Service\Accounting\Codebooks\PostingRulesImportService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;

/**
 * Import kontačních pravidel z XLSX/CSV (Epic F5 §4.5).
 *   POST /api/accounting/posting-rules/import  (multipart: file, dry_run)
 */
final class PostingRulesImportAction extends AbstractCodebookImportAction
{
    public function __construct(
        private readonly PostingRulesImportService $service,
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
        return 'posting-rules';
    }
}
