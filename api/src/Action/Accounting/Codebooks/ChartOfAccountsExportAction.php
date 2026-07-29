<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Codebooks;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\Codebooks\CodebookXlsxExporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Export účtové osnovy do XLSX (Epic F5 §4.3). Export = čtení → bez requireWrite
 * (readonly smí). Strom zploštělý (syntetické v pořadí kódů, analytiky pod rodičem),
 * exportují se i neaktivní účty (round-trip úplný).
 *
 *   GET /api/accounting/accounts/export
 */
final class ChartOfAccountsExportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
        private readonly CodebookXlsxExporter $exporter,
        private readonly Connection $db,
    ) {}

    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $out = $this->exporter->chartOfAccounts($this->accounts->listForTenant($supplierId, true));

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
