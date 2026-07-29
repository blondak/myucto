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
 * POST /api/accounting/journal/{id}/notes — nová poznámka k účetnímu zápisu.
 *
 * ZÁMĚRNĚ BEZ guardu uzavřeného období — shodně s přílohami (§33a). Poznámka není
 * účetní zápis, nemění částky ani obraty; naopak právě u zápisů generovaných ze
 * zdroje (invoice/bank/cash), kde `description` editovat NELZE, je poznámka jediná
 * cesta, jak si k dokladu něco napsat. Blokovat ji v uzavřeném období by tu featuru
 * zabilo přesně tam, kde je nejpotřebnější.
 */
final class CreateJournalNoteAction
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

        if ($this->journal->find($entryId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        $payload = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('body', $payload)) {
            return Json::error($response, 'validation_failed', 'Chybí pole body.', 422);
        }
        $body = $this->validateBody($payload['body'], $response, $err);
        if ($body === null) return $err;

        if ($this->notes->countLive($entryId, $supplierId) >= JournalEntryNoteRepository::MAX_NOTES_PER_ENTRY) {
            return Json::error(
                $response,
                'too_many_notes',
                'Zápis už má maximální počet poznámek (' . JournalEntryNoteRepository::MAX_NOTES_PER_ENTRY . ').',
                409
            );
        }

        $pinned = $this->optionalBool($payload, 'pinned') ?? false;
        $userId = $this->userId($request);
        $noteId = $this->notes->add($entryId, $supplierId, $body, $pinned, $userId);

        $this->logger->log('accounting.journal_note_created', $userId, 'journal_entry', $entryId, [
            'note_id' => $noteId,
            'pinned'  => $pinned,
            'length'  => mb_strlen($body),
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $this->notes->find($noteId, $entryId, $supplierId), 201);
    }
}
