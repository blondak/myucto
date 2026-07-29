<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Attachment;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryAttachmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /api/accounting/journal/{id}/attachments/{attId} — stažení přílohy zápisu.
 * Vždy `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff` (nikdy
 * inline render), realpath-inside-base kontrola před streamem. Scoped tenantem + zápisem.
 */
final class DownloadJournalAttachmentAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly JournalEntryAttachmentRepository $attachments,
        private readonly JournalAttachmentStorage $storage,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

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

        $path = $this->storage->pathFor($supplierId, (string) $att['sha256'], (string) $att['filename']);

        // realpath-inside-base: bajt musí ležet uvnitř journal sup-{id} kořene (defense).
        $real = realpath($path);
        $base = realpath(JournalAttachmentStorage::baseDir($supplierId));
        if ($real === false || $base === false) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }
        $r = str_replace('\\', '/', $real);
        $b = rtrim(str_replace('\\', '/', $base), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $r = strtolower($r);
            $b = strtolower($b);
        }
        if (!str_starts_with($r . '/', $b . '/')) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }
        if (!is_file($real)) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }

        $original = (string) ($att['original_name'] ?? $att['filename']);
        $safe = preg_replace('/[\r\n"\\\\]/', '_', $original);
        $mime = (string) ($att['mime_type'] ?? 'application/octet-stream');

        // LOW-5 — fopen může selhat (race na smazání / práva); vrať JSON 404, ne TypeError 500.
        $fh = @fopen($real, 'rb');
        if ($fh === false) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }

        $stream = new Stream($fh);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', $mime !== '' ? $mime : 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"')
            ->withHeader('Content-Length', (string) filesize($real))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withBody($stream);
    }
}
