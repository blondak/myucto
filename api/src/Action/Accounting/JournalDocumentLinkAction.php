<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Měkká vazba účetního zápisu na existující doklad.
 *
 *   GET    /api/accounting/journal/link-candidates?q=&types=  — našeptávač dokladů
 *   GET    /api/accounting/journal/{id}/links                 — vazby zápisu
 *   POST   /api/accounting/journal/{id}/links                 — navázat doklad
 *   DELETE /api/accounting/journal/{id}/links/{linkId}        — zrušit vazbu
 *
 * Klíčem je VŽDY id zápisu ověřené proti tenantovi; `doc_id` z requestu se nikdy
 * nedůvěřuje, existenci dokladu u TOHOTO dodavatele ověřuje repository
 * ({@see JournalEntryDocumentLinkRepository::documentExists()}). Bez toho by šlo
 * navázat cizí doklad a přes panel „Souvisí" si přečíst jeho popisná data (IDOR).
 *
 * ZÁMĚRNĚ BEZ guardu uzavřeného období — shodně s poznámkami a přílohami (§33a).
 * Vazba není účetní zápis: nemění částky ani obraty, jen dokumentuje souvislost.
 * Blokovat ji v uzavřeném období by featuru zabilo přesně tam, kde je nejpotřebnější
 * (dohledávání souvislostí při kontrole hotového účetnictví).
 *
 * Práva řeší RoutePermissionMap: GET /api/accounting/journal(/|$) → 'accounting' READ,
 * ostatní metody → 'accounting.journal.write' WRITE.
 */
final class JournalDocumentLinkAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly JournalEntryDocumentLinkRepository $links,
        private readonly JournalLinkService $graph,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $entryId = (int) ($args['id'] ?? 0);
        if ($this->journal->find($entryId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        return Json::ok($response, [
            'entry_id' => $entryId,
            'items'    => $this->itemsFor($supplierId, $entryId),
        ]);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $entryId = (int) ($args['id'] ?? 0);
        if ($this->journal->find($entryId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $link = self::validateLink($body, $supplierId, $this->links, $code, $message);
        if ($link === null) {
            return Json::error($response, $code, $message, $code === 'not_found' ? 404 : 422);
        }

        if ($this->links->countForEntry($entryId, $supplierId) >= JournalEntryDocumentLinkRepository::MAX_LINKS_PER_ENTRY) {
            return Json::error(
                $response,
                'too_many_links',
                'Zápis už má maximální počet vazeb (' . JournalEntryDocumentLinkRepository::MAX_LINKS_PER_ENTRY . ').',
                409
            );
        }

        $userId = $this->userId($request);
        $linkId = $this->links->add($entryId, $supplierId, $link['doc_type'], $link['doc_id'], $link['note'], $userId);

        $this->logger->log('accounting.journal_link_created', $userId, 'journal_entry', $entryId, [
            'link_id'  => $linkId,
            'doc_type' => $link['doc_type'],
            'doc_id'   => $link['doc_id'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, [
            'entry_id' => $entryId,
            'link'     => $this->links->find($linkId, $entryId, $supplierId),
            'items'    => $this->itemsFor($supplierId, $entryId),
        ], 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $entryId = (int) ($args['id'] ?? 0);
        $linkId  = (int) ($args['linkId'] ?? 0);
        if ($this->journal->find($entryId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        $link = $this->links->find($linkId, $entryId, $supplierId);
        if ($link === null) {
            return Json::error($response, 'not_found', 'Vazba nenalezena.', 404);
        }
        $this->links->delete($linkId, $entryId, $supplierId);

        $userId = $this->userId($request);
        $this->logger->log('accounting.journal_link_deleted', $userId, 'journal_entry', $entryId, [
            'link_id'  => $linkId,
            'doc_type' => $link['doc_type'],
            'doc_id'   => $link['doc_id'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, [
            'entry_id' => $entryId,
            'deleted'  => $linkId,
            'items'    => $this->itemsFor($supplierId, $entryId),
        ]);
    }

    /** Našeptávač dokladů k navázání. Scoped tenantem, readonly+. */
    public function candidates(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $q     = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $types = trim((string) ($request->getQueryParams()['types'] ?? ''));
        $typeList = $types === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $types))));

        return Json::ok($response, [
            'query' => $q,
            'items' => $this->links->searchCandidates($supplierId, $q, $typeList),
        ]);
    }

    /**
     * Validace jedné vazby z požadavku. Sdílí ji i {@see JournalAction::create()},
     * kde vazby chodí rovnou se zakládaným zápisem — jedna cesta validace znamená,
     * že se obě místa nemůžou rozejít v tom, co je platná vazba.
     *
     * @param  array<string,mixed> $body
     * @return array{doc_type:string, doc_id:int, note:?string}|null
     */
    public static function validateLink(
        array $body,
        int $supplierId,
        JournalEntryDocumentLinkRepository $links,
        ?string &$code,
        ?string &$message
    ): ?array {
        $docType = trim((string) ($body['doc_type'] ?? ''));
        $docId   = (int) ($body['doc_id'] ?? 0);

        if (!in_array($docType, JournalEntryDocumentLinkRepository::DOC_TYPES, true)) {
            $code = 'validation_failed';
            $message = 'doc_type musí být jeden z: ' . implode(', ', JournalEntryDocumentLinkRepository::DOC_TYPES) . '.';
            return null;
        }
        if ($docId <= 0) {
            $code = 'validation_failed';
            $message = 'doc_id musí být kladné číslo.';
            return null;
        }
        if (!$links->documentExists($supplierId, $docType, $docId)) {
            // Cizí doklad a neexistující doklad se ZÁMĚRNĚ nerozlišují — jinak by
            // odpověď prozradila, že doklad s tím id existuje u jiného tenanta.
            $code = 'not_found';
            $message = 'Doklad nenalezen.';
            return null;
        }

        $note = $body['note'] ?? null;
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > JournalEntryDocumentLinkRepository::MAX_NOTE_LENGTH) {
            $code = 'validation_failed';
            $message = 'note může mít nejvýš ' . JournalEntryDocumentLinkRepository::MAX_NOTE_LENGTH . ' znaků.';
            return null;
        }

        $code = null;
        $message = null;
        return ['doc_type' => $docType, 'doc_id' => $docId, 'note' => $note];
    }

    /**
     * Vazby zápisu s popisnými daty dokladu. Tvar drží {@see JournalLinkService},
     * ať se odpověď /links nemůže rozejít s tím, co k vazbám vrací detail zápisu.
     *
     * @return list<array<string,mixed>>
     */
    private function itemsFor(int $supplierId, int $entryId): array
    {
        return $this->graph->documentLinks($supplierId, $entryId);
    }
}
