<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Repository\DocumentFileRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * N-souborů-na-doklad (Epic F7, §4.3 / §6) — správa `document_files` řádků jednoho
 * DMS dokumentu (role primary|attachment).
 *
 * Každá cesta NEJPRVE projde scope-guarded {@see DocumentRepository::find} (viewer
 * kontext), takže user-scoped doklad cizího uživatele je fail-closed neviditelný
 * (404) dřív, než se sáhne na jeho soubory. Bajty leží v témže content-addressed
 * `sup-{id}` sha stromu jako `documents` — přidání jde přes hardened
 * {@see DocumentStorage::storeFromTemp} (MIME z obsahu, blocklist, size cap, dedup,
 * path-traversal guard), mazání přes union ref-counting {@see DocumentStorage::deleteIfOrphan}.
 */
final class DocumentFilesAction
{
    use DocumentActionTrait;

    private const MAX_FILES_PER_REQUEST = 100;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentFileRepository $files,
        private readonly DocumentStorage $storage,
        private readonly ActivityLogger $logger,
    ) {}

    /** GET /api/documents/{id}/files */
    public function list(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, $this->viewer($request), true) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        return Json::ok($response, ['files' => $this->files->listByDocument($id, $sid)]);
    }

    /** POST /api/documents/{id}/files — přidá přílohu(y) role='attachment' (multipart file/file[]). */
    public function add(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        // Scope-guard rodičovský doklad PŘED sáhnutím na jeho soubory (fail-closed).
        if ($this->documents->find($id, $sid, $this->viewer($request)) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }

        $userId = $this->userId($request);
        $ip = $this->clientIp($request);
        $ua = $request->getHeaderLine('User-Agent');

        $uploaded = $request->getUploadedFiles();
        $list = [];
        if (isset($uploaded['file'])) {
            $list = is_array($uploaded['file']) ? array_values($uploaded['file']) : [$uploaded['file']];
        }
        if ($list === []) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán.', 400);
        }
        if (count($list) > self::MAX_FILES_PER_REQUEST) {
            return Json::error($response, 'too_many_files',
                'Příliš mnoho souborů najednou (max ' . self::MAX_FILES_PER_REQUEST . ').', 413);
        }

        // Nové přílohy zařadíme za stávající (sort_order pokračuje od maxima).
        $existing = $this->files->listByDocument($id, $sid);
        $nextSort = 0;
        foreach ($existing as $f) {
            $nextSort = max($nextSort, (int) $f['sort_order'] + 1);
        }

        $created = 0;
        $errors = [];
        foreach ($list as $file) {
            if (!$file instanceof UploadedFileInterface) continue;
            $originalName = trim((string) $file->getClientFilename());
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errors[] = ['name' => $originalName ?: '?', 'reason' => 'upload_error_' . $file->getError()];
                continue;
            }
            if ($originalName === '') {
                $errors[] = ['name' => '?', 'reason' => 'no_filename'];
                continue;
            }

            $tmp = $this->storage->tmpPath($sid);
            try {
                $file->moveTo($tmp);
            } catch (\Throwable) {
                $errors[] = ['name' => $originalName, 'reason' => 'move_failed'];
                @unlink($tmp);
                continue;
            }

            try {
                $stored = $this->storage->storeFromTemp($tmp, $sid, $originalName);
            } catch (DocumentException $e) {
                @unlink($tmp);
                $errors[] = ['name' => $originalName, 'reason' => $e->errorCode];
                continue;
            } catch (\Throwable) {
                @unlink($tmp);
                $errors[] = ['name' => $originalName, 'reason' => 'store_failed'];
                continue;
            }

            try {
                $this->files->add([
                    'document_id'   => $id,
                    'supplier_id'   => $sid,
                    'role'          => 'attachment',
                    'sha256'        => $stored['sha256'],
                    'filename'      => $stored['filename'],
                    'original_name' => $originalName,
                    'mime_type'     => $stored['mime_type'],
                    'size_bytes'    => $stored['size_bytes'],
                    'doc_type'      => $stored['doc_type'],
                    'sort_order'    => $nextSort++,
                    'uploaded_by'   => $userId,
                ]);
                $created++;
                $this->logger->log('document.file_added', $userId, 'document', $id,
                    ['original_name' => $originalName, 'sha256' => $stored['sha256']], $ip, $ua, $sid);
            } catch (\PDOException $e) {
                // Jen unique-violation (SQLSTATE 23000) = uq_df_doc_sha(document_id, sha256):
                // stejný obsah už k dokumentu patří. Ostatní DB chyby přebublej (nemaskovat
                // je jako „duplicate").
                if ((string) ($e->errorInfo[0] ?? $e->getCode()) !== '23000') {
                    throw $e;
                }
                $errors[] = ['name' => $originalName, 'reason' => 'duplicate'];
            }
        }

        if ($created === 0 && $errors !== []) {
            return Json::error($response, 'upload_failed', 'Žádný soubor se nepodařilo přidat.', 400, ['errors' => $errors]);
        }

        return Json::ok($response, ['files' => $this->files->listByDocument($id, $sid), 'errors' => $errors]);
    }

    /** PATCH /api/documents/{id}/files/{fileId} — set-primary a/nebo sort_order. */
    public function patch(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, $this->viewer($request)) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $fileId = (int) ($args['fileId'] ?? 0);
        $file = $this->files->find($fileId, $id, $sid);
        if ($file === null) {
            return Json::error($response, 'not_found', 'Soubor nenalezen.', 404);
        }

        $body = (array) $request->getParsedBody();

        if (array_key_exists('role', $body)) {
            $role = (string) $body['role'];
            if ($role === 'primary') {
                // Atomicky degraduje stávající primary a povýší cíl (právě jeden primary).
                $this->files->setPrimary($fileId, $id, $sid);
            } elseif ($role === 'attachment') {
                // Nesmí zůstat doklad bez primary — odmítni degradaci posledního primary.
                if ($file['role'] === 'primary') {
                    return Json::error($response, 'cannot_demote_primary',
                        'Dokument musí mít primární soubor — nejdřív nastav primární jiný soubor.', 409);
                }
            } else {
                return Json::error($response, 'bad_role', 'Neplatná role souboru.', 400);
            }
        }

        if (array_key_exists('sort_order', $body)) {
            $this->files->setSortOrder($fileId, $id, $sid, (int) $body['sort_order']);
        }

        return Json::ok($response, ['files' => $this->files->listByDocument($id, $sid)]);
    }

    /** DELETE /api/documents/{id}/files/{fileId} — odebrání (nelze poslední primary; orphan-aware). */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, $this->viewer($request)) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $fileId = (int) ($args['fileId'] ?? 0);
        $file = $this->files->find($fileId, $id, $sid);
        if ($file === null) {
            return Json::error($response, 'not_found', 'Soubor nenalezen.', 404);
        }

        // Primary soubor zrcadlí documents inline sloupce (SoT) — nelze smazat poslední
        // primary; uživatel musí nejdřív povýšit jiný soubor na primary.
        if ($file['role'] === 'primary') {
            return Json::error($response, 'cannot_delete_primary',
                'Primární soubor nelze smazat — nejdřív nastav primární jiný soubor.', 409);
        }

        $this->files->remove($fileId, $id, $sid);
        // Union ref-counting (documents + document_files) — bajt odpojíme jen když na sha
        // po vyloučení právě mazaného řádku neukazuje nikdo (§4.4).
        $this->storage->deleteIfOrphan(
            $sid,
            (string) $file['sha256'],
            (string) $file['filename'],
            null,
            $this->documents,
            [],
            [$fileId],
        );
        $this->logger->log('document.file_removed', $this->userId($request), 'document', $id,
            ['file_id' => $fileId, 'sha256' => $file['sha256']], $this->clientIp($request),
            $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, ['files' => $this->files->listByDocument($id, $sid)]);
    }

    /** GET /api/documents/{id}/files/{fileId}/download — vždy attachment + nosniff. */
    public function download(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        // Scope-guard rodičovský doklad (findRaw se scope guardem) PŘED servírováním souboru.
        if ($this->documents->findRaw($id, $sid, $this->viewer($request), true) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $fileId = (int) ($args['fileId'] ?? 0);
        $file = $this->files->find($fileId, $id, $sid);
        if ($file === null) {
            return Json::error($response, 'not_found', 'Soubor nenalezen.', 404);
        }

        $path = $this->storage->pathFor($sid, (string) $file['sha256'], (string) $file['filename']);
        if (!is_file($path) || !$this->insideBase($sid, $path)) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }

        $original = (string) ($file['original_name'] ?? $file['filename']);
        $safe = preg_replace('/[\r\n"\\\\]/', '_', $original);

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }
        $stream = new Stream($fh);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$safe}\"")
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox; style-src 'unsafe-inline'")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }

    /** Realpath-inside-base guard (content-addressed, ale obrana navíc; Win case-insensitive). */
    private function insideBase(int $supplierId, string $target): bool
    {
        $baseReal = realpath(DocumentStorage::baseDir($supplierId));
        $targetReal = realpath($target);
        if ($baseReal === false || $targetReal === false) {
            return false;
        }
        $b = rtrim(str_replace('\\', '/', $baseReal), '/');
        $t = str_replace('\\', '/', $targetReal);
        if (DIRECTORY_SEPARATOR === '\\') {
            $b = strtolower($b);
            $t = strtolower($t);
        }
        return str_starts_with($t . '/', $b . '/');
    }
}
