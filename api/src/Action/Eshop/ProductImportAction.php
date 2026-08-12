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
 *
 * `Connection` se NEsmí deklarovat znovu — báze ji drží jako `protected readonly`
 * a překrytí `private` je fatál už při načtení třídy (endpoint pak spadne na 500
 * dřív, než se dostane k první řádce kódu).
 */
final class ProductImportAction extends AbstractCodebookImportAction
{
    use GuardsStockEnabled;

    public function __construct(
        private readonly ProductImportService $service,
        Connection $db,
        ActivityLogger $logger,
        IpMatcher $ipMatcher,
    ) {
        parent::__construct($logger, $ipMatcher, $db);
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

    /**
     * Katalog zboží je e-shopová věc, ne účetní — daňová evidence ho musí
     * naimportovat stejně jako podvojné účetnictví. Přístup hlídá
     * `guardStockEnabled()` výše, ne účetní režim.
     */
    protected function requiresDoubleEntry(): bool
    {
        return false;
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
