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
 * PATCH /api/accounting/journal/{id}/notes/{noteId} — editace textu / připnutí.
 *
 * Částečná: pošli `body`, `pinned` nebo obojí. Historie se drží přes
 * `updated_at`/`updated_by` (tabulka není system-versioned — viz migrace 1129).
 * Bez guardu uzavřeného období, ze stejného důvodu jako u vytvoření.
 */
final class PatchJournalNoteAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;
    use JournalNoteActionSupport;

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

        $payload = (array) ($request->getParsedBody() ?? []);
        $hasBody = array_key_exists('body', $payload);
        $pinned  = $this->optionalBool($payload, 'pinned');

        if (!$hasBody && $pinned === null) {
            return Json::error($response, 'validation_failed', 'Nebylo co změnit — pošli body nebo pinned.', 422);
        }

        $body = null;
        if ($hasBody) {
            $body = $this->validateBody($payload['body'], $response, $err);
            if ($body === null) return $err;
        }

        $userId = $this->userId($request);
        $this->notes->update($noteId, $entryId, $supplierId, $body, $pinned, $userId);

        $this->logger->log('accounting.journal_note_edited', $userId, 'journal_entry', $entryId, [
            'note_id'       => $noteId,
            'body_changed'  => $hasBody,
            'pinned_before' => $note['pinned'],
            'pinned_after'  => $pinned ?? $note['pinned'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $this->notes->find($noteId, $entryId, $supplierId));
    }
}
