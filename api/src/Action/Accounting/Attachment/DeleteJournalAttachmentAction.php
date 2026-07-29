<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Attachment;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryAttachmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/accounting/journal/{id}/attachments/{attId} — smazání přílohy zápisu.
 * DB řádek se odstraní, bajt jen dedup-aware (deleteIfOrphan počítá JEN
 * journal_entry_attachments — vlastní namespace, nekříží se s DMS). requireWrite.
 */
final class DeleteJournalAttachmentAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly JournalEntryAttachmentRepository $attachments,
        private readonly JournalAttachmentStorage $storage,
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
        $attId = (int) ($args['attId'] ?? 0);

        if ($this->journal->find($entryId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        $att = $this->attachments->find($attId, $entryId, $supplierId);
        if ($att === null) {
            return Json::error($response, 'not_found', 'Příloha nenalezena.', 404);
        }

        $this->attachments->delete($attId, $entryId, $supplierId);
        // Řádek už je z DB pryč → countBySha vidí jen zbylé reference (excludeId = 0).
        $this->storage->deleteIfOrphan($supplierId, (string) $att['sha256'], (string) $att['filename'], $this->attachments);

        $this->logger->log('accounting.attachment_deleted', $this->userId($request), 'journal_entry', $entryId, [
            'attachment_id' => $attId,
            'original_name' => $att['original_name'] ?? null,
            'sha256'        => $att['sha256'] ?? null,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['deleted' => $attId]);
    }
}
