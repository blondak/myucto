<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\JournalSourceSummaryService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/accounting/journal/{id}/source — read-only shrnutí zdrojového dokladu
 * pro náhledový drawer v deníku.
 *
 * JEDEN generický endpoint místo endpointu na typ dokladu, a klíčem je ID ZÁPISU,
 * ne dvojice (source_type, source_id) z query. Důvod je bezpečnostní: kdyby si
 * volitelné source_id určoval klient, šlo by přes něj tahat cizí doklady (IDOR).
 * Takhle se zápis nejdřív ověří proti tenantovi a source_type/source_id se berou
 * VÝHRADNĚ z ověřeného DB řádku.
 *
 * Právo řeší RoutePermissionMap: GET /api/accounting/journal(/|$) → 'accounting' READ.
 */
final class JournalSourceAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly JournalSourceSummaryService $summaries,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $entryId = (int) ($args['id'] ?? 0);

        $entry = $this->journal->find($entryId, $supplierId);
        if ($entry === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        return Json::ok($response, array_merge(
            ['entry_id' => $entryId],
            $this->summaries->summarize($supplierId, $entry)
        ));
    }
}
