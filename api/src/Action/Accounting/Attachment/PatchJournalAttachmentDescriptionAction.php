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
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PATCH /api/accounting/journal/{id}/attachments/{attId}/description — inline editace
 * §33a popisku přílohy. Neměnný before/after audit. requireWrite = účetní|admin.
 */
final class PatchJournalAttachmentDescriptionAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly JournalEntryAttachmentRepository $attachments,
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

        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('description', $body)) {
            return Json::error($response, 'validation_failed', 'Chybí pole description.', 422);
        }
        $text = $body['description'] === null ? null : trim((string) $body['description']);
        if ($text === '') {
            $text = null;
        }
        if ($text !== null && mb_strlen($text) > 255) {
            return Json::error($response, 'validation_failed', 'Popis smí mít nejvýše 255 znaků.', 422);
        }

        $before = $att['description'] !== null ? (string) $att['description'] : null;
        $this->attachments->updateDescription($attId, $entryId, $supplierId, $text);

        $this->logger->log('accounting.attachment_description_edited', $this->userId($request), 'journal_entry', $entryId, [
            'attachment_id' => $attId,
            'before'        => $before,
            'after'         => $text,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $this->attachments->find($attId, $entryId, $supplierId));
    }
}
