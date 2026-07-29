<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Note;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryNoteRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/accounting/journal/{id}/notes/{noteId} — SOFT delete poznámky.
 *
 * Řádek zůstává v tabulce s `deleted_at`, z API zmizí. Tabulka není
 * system-versioned (viz migrace 1129), takže dohledatelnost drží právě soft delete.
 */
final class DeleteJournalNoteAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly JournalEntryNoteRepository $notes,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;

        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $entryId = (int) ($args['id'] ?? 0);
        $noteId  = (int) ($args['noteId'] ?? 0);

        if ($this->journal->find($entryId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        $note = $this->notes->find($noteId, $entryId, $supplierId);
        if ($note === null) {
            return Json::error($response, 'not_found', 'Poznámka nenalezena.', 404);
        }

        $userId = $this->userId($request);
        $this->notes->softDelete($noteId, $entryId, $supplierId, $userId);

        $this->logger->log('accounting.journal_note_deleted', $userId, 'journal_entry', $entryId, [
            'note_id' => $noteId,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['deleted' => $noteId]);
    }
}
