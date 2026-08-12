<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Codebooks;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;
use MyInvoice\Service\Accounting\Codebooks\CodebookImportException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Sdílená báze pro import číselníků (Epic F5 §4.5). requireWrite (admin|accountant),
 * multipart pole `file` + `dry_run` (default 1 — preview je výchozí; ostrý běh
 * vyžaduje explicitní dry_run=0). Bezpečnost uploadu §4.6: whitelist přípon xlsx|csv,
 * velikost ≤ 2 MB, MIME sniff (zip / text). Report ze §4.4 přes Json::ok; ostrý běh
 * s chybami → import_has_errors 422; po úspěšném ostrém běhu ActivityLogger.
 */
abstract class AbstractCodebookImportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        protected readonly ActivityLogger $logger,
        protected readonly IpMatcher $ipMatcher,
        protected readonly Connection $db,
    ) {}

    abstract protected function importService(): AbstractCodebookImportService;

    /** kind pro ActivityLogger payload (accounts|posting-rules|assets). */
    abstract protected function kind(): string;

    /**
     * Vyžaduje tenhle import podvojné účetnictví?
     *
     * Default `true` je schválně fail-safe: nový import číselníku, který se
     * na tuhle bázi pověsí, je gate ohlídaný, i když na něj autor zapomene.
     * Opt-out patří jen importům, které s účetním režimem nemají nic
     * společného — sklad a e-shop běží stejně u daňové evidence jako
     * u podvojného účetnictví a `requireDoubleEntry()` by je jen bezdůvodně
     * zavřel. Ty místo toho hlídá `GuardsStockEnabled` (opt-in modul).
     */
    protected function requiresDoubleEntry(): bool
    {
        return true;
    }

    public function import(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if ($this->requiresDoubleEntry() && !$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $file = $this->firstFile($request->getUploadedFiles());
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'bad_file', 'Nahrajte soubor XLSX nebo CSV.', 415);
        }
        if ((int) ($file->getSize() ?? 0) > self::MAX_BYTES) {
            return Json::error($response, 'bad_file', 'Soubor je větší než 2 MB.', 415);
        }

        $filename = (string) ($file->getClientFilename() ?? '');
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            return Json::error($response, 'bad_file', 'Podporované formáty jsou XLSX a CSV.', 415);
        }

        $content = (string) $file->getStream()->getContents();
        if (!$this->mimeOk($ext, $content)) {
            return Json::error($response, 'bad_file', 'Obsah souboru neodpovídá příponě.', 415);
        }

        $dryRun = $this->dryRun($request);

        try {
            $report = $this->importService()->import($supplierId, $this->userId($request), $content, $filename, $dryRun);
        } catch (CodebookImportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        if (!$dryRun && (int) $report['failed'] > 0) {
            return Json::error($response, 'import_has_errors', 'Import obsahuje chyby — nic nebylo zapsáno.', 422, [
                'created' => $report['created'],
                'updated' => $report['updated'],
                'skipped' => $report['skipped'],
                'failed'  => $report['failed'],
                'rows'    => $report['rows'],
            ]);
        }

        if (!$dryRun) {
            $this->logger->log(
                'accounting.codebook_imported',
                $this->userId($request),
                'codebook',
                null,
                [
                    'kind'    => $this->kind(),
                    'created' => $report['created'],
                    'updated' => $report['updated'],
                    'skipped' => $report['skipped'],
                    'file'    => $filename,
                ],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }

        return Json::ok($response, $report);
    }

    private function dryRun(Request $request): bool
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('dry_run', $body)) {
            return true;
        }
        $v = strtolower(trim((string) $body['dry_run']));
        return !in_array($v, ['0', 'false', 'no'], true);
    }

    private function mimeOk(string $ext, string $content): bool
    {
        if ($content === '') {
            return false;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if ($ext === 'xlsx') {
            return str_contains($mime, 'zip')
                || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }
        // csv
        return str_starts_with($mime, 'text/')
            || in_array($mime, ['application/csv', 'application/vnd.ms-excel', 'application/octet-stream'], true);
    }

    /**
     * @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads
     */
    private function firstFile(array $uploads): ?UploadedFileInterface
    {
        foreach ($uploads as $node) {
            if ($node instanceof UploadedFileInterface) {
                return $node;
            }
            if (is_array($node)) {
                foreach ($node as $sub) {
                    if ($sub instanceof UploadedFileInterface) {
                        return $sub;
                    }
                }
            }
        }
        return null;
    }
}
