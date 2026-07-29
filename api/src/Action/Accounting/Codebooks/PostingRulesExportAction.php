<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Codebooks;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Codebooks\CodebookXlsxExporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Export kontačních pravidel do XLSX (Epic F5 §4.3). Efektivní pohled firmy
 * (globální seed překrytý overridy). Export = čtení → bez requireWrite.
 *
 *   GET /api/accounting/posting-rules/export
 */
final class PostingRulesExportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly PostingRuleRepository $rules,
        private readonly CodebookXlsxExporter $exporter,
        private readonly Connection $db,
    ) {}

    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $rules = array_values($this->rules->effectiveMap($supplierId));
        usort($rules, fn ($a, $b) => strcmp((string) $a['rule_key'], (string) $b['rule_key']));
        $out = $this->exporter->postingRules($rules);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
