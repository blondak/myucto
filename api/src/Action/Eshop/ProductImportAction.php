<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\Codebooks\AbstractCodebookImportAction;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\ProductImportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Import zboží z XLSX/CSV (Epic ESHOP F3).
 *   POST /api/eshop/products/import  (multipart: file, dry_run)
 *
 * Reuse hardened AbstractCodebookImportAction (requireWrite, whitelist xlsx|csv,
 * ≤2 MB, MIME sniff, dry-run default). Navíc opt-in guard stock_enabled.
 */
final class ProductImportAction extends AbstractCodebookImportAction
{
    use GuardsStockEnabled;

    public function __construct(
        private readonly ProductImportService $service,
        private readonly Connection $db,
        ActivityLogger $logger,
        IpMatcher $ipMatcher,
    ) {
        parent::__construct($logger, $ipMatcher);
    }

    public function import(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        return parent::import($request, $response);
    }

    protected function importService(): AbstractCodebookImportService
    {
        return $this->service;
    }

    protected function kind(): string
    {
        return 'eshop-products';
    }
}
