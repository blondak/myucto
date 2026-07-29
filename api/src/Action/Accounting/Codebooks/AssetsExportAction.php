<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Codebooks;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Service\Accounting\Codebooks\CodebookXlsxExporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Export karet majetku do XLSX (Epic F5 §4.3). Export = čtení → bez requireWrite.
 *
 *   GET /api/accounting/assets/export
 */
final class AssetsExportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly AssetRepository $assets,
        private readonly CodebookXlsxExporter $exporter,
        private readonly Connection $db,
    ) {}

    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $items = [];
        $page = 1;
        do {
            $res = $this->assets->list($supplierId, ['per_page' => 200, 'page' => $page]);
            foreach ($res['items'] as $item) {
                $items[] = $item;
            }
            $fetched = $page * 200;
            $page++;
        } while ($fetched < (int) $res['total']);

        $out = $this->exporter->assets($items);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
