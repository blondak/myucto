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
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/accounting/journal/{id}/attachments — multipart upload jedné nebo více
 * příloh účetního zápisu (§33a), field `file` / `file[]`.
 *
 * Bezpečnost: per-soubor 20 MiB + per-zápis strop; MIME z OBSAHU (magic-byte + finfo,
 * klient-side Content-Type se ignoruje) + DANGEROUS_EXT/MIME blocklist; content-addressed
 * sha256 s dedup (409 když stejný obsah u zápisu už je). Bajty jdou do VLASTNÍHO namespace
 * `storage/journal/sup-{id}/…` (ne DMS). requireWrite = účetní|admin.
 */
final class UploadJournalAttachmentAction
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

        if ($this->journal->find($entryId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        $files = $request->getUploadedFiles();
        $list = [];
        if (isset($files['file'])) {
            $list = is_array($files['file']) ? $files['file'] : [$files['file']];
        }
        if (empty($list)) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán.', 400);
        }

        $userId = $this->userId($request);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');
        $totalSoFar = $this->attachments->totalSize($entryId, $supplierId);

        // LOW-2 — jedna vadná příloha nesmí shodit celou dávku: per-soubor výsledky
        // akumulujeme (created + errored s důvodem) a vracíme je klientovi. Když ale
        // NIC nevzniklo a byla chyba, vrátíme klasický error status (RESTová parita
        // pro single-file upload: duplicate → 409, file_too_large → 413, …).
        $created = [];
        $results = [];
        $firstError = null;
        foreach ($list as $file) {
            // LOW-6 — položka, která není nahraný soubor, je vadný request → 400.
            if (!$file instanceof UploadedFileInterface) {
                return Json::error($response, 'validation_failed', 'Neplatný formát nahrávaného souboru.', 400);
            }

            $originalName = trim((string) $file->getClientFilename());

            $fail = function (string $code, string $message, int $status) use (&$results, &$firstError, $originalName): void {
                $results[] = ['status' => 'error', 'original_name' => $originalName, 'error' => $code, 'message' => $message];
                if ($firstError === null) {
                    $firstError = ['code' => $code, 'message' => $message, 'status' => $status];
                }
            };

            if ($file->getError() !== UPLOAD_ERR_OK) {
                $fail('upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
                continue;
            }

            $size = (int) ($file->getSize() ?? 0);
            if ($size <= 0) {
                $fail('empty_file', 'Soubor je prázdný.', 400);
                continue;
            }
            if ($size > JournalAttachmentStorage::MAX_FILE_BYTES) {
                $fail('file_too_large',
                    'Soubor je příliš velký (max ' . (int) (JournalAttachmentStorage::MAX_FILE_BYTES / 1024 / 1024) . ' MiB).', 413);
                continue;
            }
            if ($totalSoFar + $size > JournalAttachmentStorage::MAX_ENTRY_BYTES) {
                $fail('total_too_large',
                    'Překročen celkový limit příloh zápisu (max ' . (int) (JournalAttachmentStorage::MAX_ENTRY_BYTES / 1024 / 1024) . ' MiB).', 413);
                continue;
            }

            if ($originalName === '') {
                $fail('no_filename', 'Chybí název souboru.', 400);
                continue;
            }

            // Přesuň do dočasného souboru uvnitř journal namespace (IIS/Slim moveTo vyžaduje
            // writable target dir — proto rovnou do sup-{id} kořene, ne sys_get_temp_dir).
            try {
                $tmpPath = $this->storage->tmpPath($supplierId);
            } catch (DocumentException $e) {
                $fail($e->errorCode, $e->getMessage(), $e->httpStatus);
                continue;
            }
            try {
                $file->moveTo($tmpPath);
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                $fail('move_failed', 'Nepodařilo se přesunout nahraný soubor: ' . $e->getMessage(), 500);
                continue;
            }

            // Uloží s hardeningem (MIME z obsahu + blocklist + sanitizace + dedup na disku).
            try {
                $stored = $this->storage->storeFromTemp($tmpPath, $supplierId, $originalName);
            } catch (DocumentException $e) {
                $fail($e->errorCode, $e->getMessage(), $e->httpStatus);
                continue;
            }

            // Dedup na úrovni zápisu (§5.2): stejný obsah u téhož zápisu → 409. Bajty na disku
            // zůstávají — sdílí je existující příloha (content-addressed, žádný orphan).
            if ($this->attachments->findBySha($entryId, $supplierId, $stored['sha256']) !== null) {
                $fail('duplicate', 'Tato příloha už je u zápisu evidována.', 409);
                continue;
            }

            $attId = $this->attachments->add(
                $entryId,
                $supplierId,
                $stored['sha256'],
                $stored['filename'],
                $originalName,
                $stored['mime_type'],
                $size,
                $stored['doc_type'],
                null,
                $userId,
            );
            $totalSoFar += $size;

            $this->logger->log('accounting.attachment_uploaded', $userId, 'journal_entry', $entryId, [
                'attachment_id' => $attId,
                'original_name' => $originalName,
                'size_bytes'    => $size,
                'mime_type'     => $stored['mime_type'],
                'sha256'        => $stored['sha256'],
            ], $ip, $ua, $supplierId);

            $created[] = $attId;
            $results[] = ['status' => 'created', 'attachment_id' => $attId, 'original_name' => $originalName];
        }

        // Nic nevzniklo a byla chyba → klasický error status (single-file parita).
        if ($created === [] && $firstError !== null) {
            return Json::error($response, $firstError['code'], $firstError['message'], $firstError['status']);
        }

        return Json::ok($response, [
            'created'    => $created,
            'results'    => $results,
            'items'      => $this->attachments->list($entryId, $supplierId),
            'total_size' => $totalSoFar,
        ]);
    }
}
