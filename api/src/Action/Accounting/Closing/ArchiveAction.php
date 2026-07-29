<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Archive\ArchiveService;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Stream;

/**
 * Per-firma archivace účetnictví (Epic F4, R15) — export ZIP s manifestem.
 * Obnova archivu přes API záměrně NEEXISTUJE (jen CLI dry-run draft
 * api/bin/archive-restore.php). Celý resource je admin-only.
 *
 *   GET    /api/accounting/archive                — seznam archivů firmy
 *   POST   /api/accounting/archive/export         — vytvoření archivu (synchronně)
 *   GET    /api/accounting/archive/{id}/download  — stažení ZIP (stream)
 *   DELETE /api/accounting/archive/{id}           — smazání archivu (soubor + metadata)
 *
 * Download streamuje VÝHRADNĚ soubor dohledaný přes id v accounting_archives
 * (tenant-scoped) — filename se nikdy nebere z requestu (žádný path traversal);
 * basename() je defense-in-depth nad hodnotou z DB.
 */
final class ArchiveAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly ArchiveService $archive,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->requireAdmin($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        return Json::ok($response, $this->archive->list($supplierId));
    }

    public function export(Request $request, Response $response): Response
    {
        if (!$this->requireAdmin($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        try {
            $row = $this->archive->export($supplierId, $this->userId($request));
        } catch (ClosingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Export účetního archivu selhal: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'operation_failed', 'Archiv se nepodařilo vytvořit.', 500);
        }

        $this->logEvent($request, 'accounting.archive_exported', (int) ($row['id'] ?? 0), [
            'filename'   => $row['filename'] ?? null,
            'size_bytes' => $row['size_bytes'] ?? null,
            'sha256'     => $row['sha256'] ?? null,
        ]);
        return Json::ok($response, $row, 201);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireAdmin($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $row = $this->archive->find($supplierId, $id);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Archiv nenalezen.', 404);
        }

        // Jméno souboru pochází z DB (kontrolovaný formát myucto-archiv-sup{N}-…zip);
        // filePath dělá basename() nad hodnotou z DB, escape uvozovek je pojistka
        // proti header injection — z requestu se žádná cesta nebere (path traversal).
        $path = $this->archive->filePath($supplierId, $row);
        $filename = basename($path);
        if ($filename === '' || !is_file($path)) {
            return Json::error($response, 'not_found', 'Soubor archivu na disku neexistuje.', 404);
        }
        $safeFilename = preg_replace('/[\r\n"\\\\]/', '_', $filename);

        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeFilename . '"')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireAdmin($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        try {
            $deleted = $this->archive->delete($supplierId, $id);
        } catch (\Throwable $e) {
            $this->log->error('Smazání účetního archivu selhalo: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'operation_failed', 'Archiv se nepodařilo smazat.', 500);
        }
        if ($deleted === null) {
            return Json::error($response, 'not_found', 'Archiv nenalezen.', 404);
        }

        $this->logEvent($request, 'accounting.archive_deleted', $id, [
            'filename' => $deleted['filename'] ?? null,
            'sha256'   => $deleted['sha256'] ?? null,
        ]);
        return Json::ok($response, ['deleted' => true]);
    }

    private function logEvent(Request $request, string $action, int $entityId, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'accounting_archive', $entityId, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
