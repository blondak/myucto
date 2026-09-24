<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunException;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunInput;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Kontrola souběhu se starým účetním programem (interní API, ne veřejné v1).
 *
 *   GET    /api/accounting/parallel-run/sources                          — programy a druhy výstupů
 *   GET    /api/accounting/parallel-run/backups                          — zálohy Money S3 z průvodce převodu
 *   GET    /api/accounting/parallel-run/checks                           — historie cyklů
 *   POST   /api/accounting/parallel-run/checks                           — nová kontrola (multipart: month, source, soubory podle druhu)
 *   GET    /api/accounting/parallel-run/checks/{id}                      — protokol
 *   GET    /api/accounting/parallel-run/checks/{id}/export               — protokol jako CSV
 *   PUT    /api/accounting/parallel-run/checks/{id}/classification       — zařazení rozdílu
 *   POST   /api/accounting/parallel-run/checks/{id}/close | /reopen      — stav cyklu
 *   DELETE /api/accounting/parallel-run/checks/{id}
 *
 * Kontrola do účetnictví nic nezapisuje; ukládá jen protokol. Zápisy (spuštění,
 * zařazení, uzavření) smí účetní/admin, čtení každý s přístupem do účetnictví.
 */
final class ParallelRunAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly ParallelRunService $service,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
    ) {}

    public function sources(Request $request, Response $response): Response
    {
        return $this->read($request, $response, fn (int $supplierId): array => [
            'sources' => $this->service->sources(),
            'inputs' => ParallelRunInput::CRITERIA,
            'categories' => ParallelRunService::CATEGORIES,
        ]);
    }

    public function backups(Request $request, Response $response): Response
    {
        return $this->read($request, $response, fn (int $supplierId): array => ['backups' => $this->service->moneyBackups($supplierId)]);
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->read($request, $response, fn (int $supplierId): array => ['checks' => $this->service->history($supplierId)]);
    }

    /** @param array<string,string> $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, fn (int $supplierId): array => $this->service->get($supplierId, (int) $args['id']));
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!$this->requireWrite($request, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $files = [];
        $names = [];
        foreach ($request->getUploadedFiles() as $kind => $file) {
            if (is_array($file)) {
                $file = $file[0] ?? null;
            }
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return Json::error($response, 'bad_file', 'Soubor se nepodařilo nahrát.', 415);
            }
            if ((int) ($file->getSize() ?? 0) > ParallelRunService::MAX_FILE_BYTES) {
                return Json::error($response, 'bad_file', 'Soubor je větší než 20 MB.', 415);
            }
            $files[(string) $kind] = (string) $file->getStream()->getContents();
            $names[(string) $kind] = basename((string) ($file->getClientFilename() ?? $kind));
        }
        $options = [
            'statement_unit' => (float) ($body['statement_unit'] ?? 1),
            'backup_token' => (string) ($body['backup_token'] ?? ''),
        ];

        return $this->run($response, function () use ($request, $supplierId, $body, $files, $names, $options): array {
            $check = $this->service->check($supplierId, $this->userId($request), (string) ($body['month'] ?? ''), (string) ($body['source'] ?? ''), $files, $names, $options);
            $this->audit($request, $supplierId, 'run', ['check_id' => $check['id'], 'month' => $check['month'], 'source' => $check['source'], 'status' => $check['status']]);
            return $check;
        }, 201);
    }

    /** @param array<string,string> $args */
    public function classify(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId) use ($request, $args): array {
            $body = (array) ($request->getParsedBody() ?? []);
            $category = isset($body['category']) && $body['category'] !== '' ? (string) $body['category'] : null;
            return $this->service->classify($supplierId, (int) $args['id'], (string) ($body['difference_id'] ?? ''), $category, isset($body['note']) ? (string) $body['note'] : null, $this->userId($request));
        });
    }

    /** @param array<string,string> $args */
    public function close(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId) use ($request, $args): array {
            $body = (array) ($request->getParsedBody() ?? []);
            $check = $this->service->close($supplierId, (int) $args['id'], $this->userId($request), isset($body['note']) ? (string) $body['note'] : null);
            $this->audit($request, $supplierId, 'close', ['check_id' => $check['id'], 'month' => $check['month']]);
            return $check;
        });
    }

    /** @param array<string,string> $args */
    public function reopen(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId) use ($request, $args): array {
            $check = $this->service->reopen($supplierId, (int) $args['id']);
            $this->audit($request, $supplierId, 'reopen', ['check_id' => $check['id'], 'month' => $check['month']]);
            return $check;
        });
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId) use ($request, $args): array {
            $this->service->delete($supplierId, (int) $args['id']);
            $this->audit($request, $supplierId, 'delete', ['check_id' => (int) $args['id']]);
            return ['deleted' => true];
        });
    }

    /** @param array<string,string> $args */
    public function export(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        try {
            $check = $this->service->get($supplierId, (int) $args['id']);
            $csv = $this->service->exportCsv($supplierId, (int) $args['id']);
        } catch (ParallelRunException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422);
        }
        $response->getBody()->write($csv);
        $name = sprintf('soubeh-%s-%d.csv', $check['month'], $check['id']);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
    }

    /** @param callable(int): array<string,mixed> $fn */
    private function read(Request $request, Response $response, callable $fn): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return $this->run($response, static fn (): array => $fn($supplierId));
    }

    /** @param callable(int): array<string,mixed> $fn */
    private function write(Request $request, Response $response, callable $fn): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!$this->requireWrite($request, $response, $err)) return $err;
        return $this->run($response, static fn (): array => $fn($supplierId));
    }

    /** @param callable(): array<string,mixed> $fn */
    private function run(Response $response, callable $fn, int $status = 200): Response
    {
        try {
            return Json::ok($response, $fn(), $status);
        } catch (ParallelRunException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422);
        } catch (\Throwable $e) {
            $this->log->error('Kontrola souběhu: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'parallel_run_failed', 'Kontrolu se nepodařilo dokončit.', 500);
        }
    }

    /** @param array<string,mixed> $meta */
    private function audit(Request $request, int $supplierId, string $action, array $meta): void
    {
        $this->logger->log('accounting.parallel_run.' . $action, $this->userId($request), 'parallel_run_check', null,
            $meta,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);
    }
}
