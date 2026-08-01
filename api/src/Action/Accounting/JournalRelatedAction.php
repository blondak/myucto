<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\JournalLinkService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/accounting/journal/{id}/related — protějšky zápisu (doklad ↔ úhrada)
 * i s jejich zaúčtováním, pro panel „Souvisí" v deníku a v náhledovém draweru.
 *
 * Klíčem je ID ZÁPISU, ne dvojice (source_type, source_id) z query — stejný
 * bezpečnostní důvod jako u {@see JournalSourceAction}: kdyby si source_id určoval
 * klient, šlo by přes něj tahat cizí doklady (IDOR). Zápis se nejdřív ověří proti
 * tenantovi a hrany grafu se hledají VÝHRADNĚ z ověřeného DB řádku.
 *
 * Právo řeší RoutePermissionMap: GET /api/accounting/journal(/|$) → 'accounting' READ.
 */
final class JournalRelatedAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly JournalLinkService $links,
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

        $related = $this->links->related($supplierId, $entry);

        return Json::ok($response, [
            'entry_id'  => $entryId,
            'items'     => $related['items'],
            'truncated' => $related['truncated'],
        ]);
    }
}
