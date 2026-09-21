<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierException;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Service\Migration\Premier\PremierImportJobService;
use MyInvoice\Service\Migration\Premier\PremierUploads;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Průvodce „Přechod z PREMIER" - nahrání zálohy dat (`.izip`/`.icab`), náhled, zkouška
 * nanečisto a ostrý převod zvoleného roku na pozadí, protokoly.
 *
 *   POST /api/admin/imports/premier/uploads/chunked                {file_name, size}
 *   POST /api/admin/imports/premier/uploads/{token}/chunks         multipart `chunk` + `offset`
 *   POST /api/admin/imports/premier/uploads/{token}/complete
 *   GET  /api/admin/imports/premier/uploads/{token}
 *   POST /api/admin/imports/premier/uploads/{token}/start          {mode, year}
 *   GET  /api/admin/imports/premier/runs
 *   GET  /api/admin/imports/premier/runs/{id}
 *
 * Záloha se pořizuje v PREMIER („Správce → Záloha dat") a nahrává po částech jako export
 * POHODY/Money S3; rozbalí ji job na pozadí ({@see PremierImportJobService::MODE_PREPARE}).
 * Na rozdíl od POHODY nese jedna záloha VŠECHNY účetní roky, takže se po ostrém převodu
 * nemaže - uživatel v ní postupně převádí rok po roku. Stav běžícího převodu jde přes
 * společné GET /api/admin/imports/{id}.
 */
final class PremierMigrationAction
{
    private const MAX_BACKUP_BYTES = 2 * 1024 * 1024 * 1024;
    /** Nahraných záloh firmy najednou (nové nahrání smaže nejstarší nečinnou). */
    private const MAX_ACTIVE_UPLOADS = 3;
    /** Část zálohy: pod `upload_max_filesize`, IIS `maxAllowedContentLength` i nginx `client_max_body_size`. */
    // Pod výchozím limitem nginx (client_max_body_size 1m): u instalací za cizí reverzní
    // proxy by 8MB kousky skončily chybou 413 dřív, než dorazí do aplikace.
    public const CHUNK_BYTES = 768 * 1024;
    private const ALLOWED_EXTENSIONS = ['izip', 'icab', 'zip'];

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly PremierImportRepository $runs,
        private readonly PremierImporter $importer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    /** Založí nahrávání zálohy po částech: `{file_name, size}` → `{token, chunk_size}`. */
    public function initChunked(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $fileName = mb_substr(basename(str_replace('\\', '/', trim((string) ($body['file_name'] ?? '')))), 0, 200);
        $size = filter_var($body['size'] ?? null, FILTER_VALIDATE_INT);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileName === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return Json::error($response, 'invalid_file_type', 'Nahrajte zálohu dat z PREMIER (soubor .izip nebo .icab).', 422);
        }
        if (!is_int($size) || $size <= 0) {
            return Json::error($response, 'invalid_size', 'Chybí velikost zálohy.', 422);
        }
        if ($size > self::MAX_BACKUP_BYTES) {
            return Json::error($response, 'upload_too_large', 'Záloha je příliš velká (nejvýš 2 GB).', 413);
        }

        PremierUploads::purgeStale($supplierId);
        if (!PremierUploads::makeRoom($supplierId, self::MAX_ACTIVE_UPLOADS)) {
            return Json::error($response, 'too_many_uploads', 'Firma má rozpracovaných příliš mnoho záloh, počkejte na dokončení běžícího zpracování.', 429);
        }
        $token = PremierUploads::newToken();
        $dir = PremierUploads::dir($supplierId, $token);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return Json::error($response, 'storage_not_writable', 'Úložiště pro zálohy není zapisovatelné.', 500);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        PremierUploads::writeState($supplierId, $token, [
            'file_name' => $fileName,
            'size' => $size,
            'received' => 0,
            'status' => PremierUploads::STATUS_UPLOADING,
            'uploaded_by' => (int) ($user['id'] ?? 0),
            'created_at' => date('c'),
            'job_id' => null,
            'error' => null,
        ]);
        touch(PremierUploads::partPath($supplierId, $token));

        return Json::ok($response, ['token' => $token, 'chunk_size' => self::CHUNK_BYTES], 201);
    }

    /** @param array<string,string> $args */
    public function chunk(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        $body = (array) ($request->getParsedBody() ?? []);
        $offset = filter_var($body['offset'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $file = $request->getUploadedFiles()['chunk'] ?? null;
        if ($file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return Json::error($response, 'chunk_too_large', 'Část zálohy je větší, než server přijme.', 413);
        }
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Chybí část zálohy.', 400);
        }
        if (!is_int($offset)) {
            return Json::error($response, 'invalid_offset', 'Chybí pozice části zálohy.', 422);
        }
        try {
            $received = PremierUploads::appendChunk($supplierId, $token, $offset, $file->getStream(), self::CHUNK_BYTES);
        } catch (PremierException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422, $e->context);
        }
        return Json::ok($response, ['received' => $received]);
    }

    /**
     * Nahrávání je u konce: záloha se rozbalí a přečte jobem na pozadí.
     *
     * @param array<string,string> $args
     */
    public function complete(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        // Rozbalení je drahé (až 2 GB na disk) - u firmy běží nejvýš jedno najednou.
        $this->jobs->reapStale($supplierId, PremierImportJobService::SOURCE);
        foreach ($this->jobs->listForTenant($supplierId, PremierImportJobService::SOURCE, limit: 20) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true) && PremierImportJobService::isPrepareJob($existing)
                && (string) ($existing['params']['token'] ?? '') !== $token) {
                return Json::error($response, 'already_processing', 'Jiná záloha PREMIER se právě zpracovává, počkejte na jeho dokončení.', 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }
        try {
            $result = PremierUploads::withUploadLock($supplierId, $token, function () use ($request, $supplierId, $token, $userId): array {
                $state = PremierUploads::state($supplierId, $token);
                if ($state === null) {
                    throw new PremierException('upload_not_found', 'Nahrávaná záloha nebyla nalezena.', [], 404);
                }
                $status = (string) ($state['status'] ?? '');
                if (in_array($status, [PremierUploads::STATUS_PROCESSING, PremierUploads::STATUS_READY], true)) {
                    return ['job_id' => isset($state['job_id']) ? (int) $state['job_id'] : null, 'spawn' => false];
                }
                if ($status !== PremierUploads::STATUS_UPLOADING) {
                    throw new PremierException('upload_failed', (string) ($state['error'] ?? 'Zálohu PREMIER se nepodařilo načíst.'), [], 409);
                }
                $size = (int) ($state['size'] ?? 0);
                $received = PremierUploads::partSize($supplierId, $token);
                if ($received !== $size) {
                    throw new PremierException('upload_incomplete', 'Záloha ještě není nahraná celá.', ['received' => $received, 'size' => $size], 422);
                }
                $jobId = $this->jobs->create($supplierId, PremierImportJobService::SOURCE, [
                    'token' => $token,
                    'mode' => PremierImportJobService::MODE_PREPARE,
                    'ip' => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                    'user_agent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
                ], $userId);
                PremierUploads::updateState($supplierId, $token, [
                    'status' => PremierUploads::STATUS_PROCESSING,
                    'received' => $received,
                    'job_id' => $jobId,
                    'error' => null,
                ]);
                return ['job_id' => $jobId, 'spawn' => true];
            });
        } catch (PremierException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422, $e->context);
        }

        if ($result['spawn']) {
            $this->spawnWorker((int) $result['job_id']);
        }
        return Json::ok($response, ['token' => $token, 'job_id' => $result['job_id']], 202);
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        try {
            if (!PremierUploads::hasMeta($supplierId, $token)) {
                $state = PremierUploads::state($supplierId, $token);
                if ($state !== null) {
                    return Json::ok($response, $this->pendingView($supplierId, $token, $state));
                }
            }
            $meta = PremierUploads::meta($supplierId, $token);
        } catch (PremierException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        $preflight = [];
        $defaultYear = null;
        foreach ((array) ($meta['agendas'] ?? []) as $agenda) {
            if ($supplierIco === '' || (string) $agenda['ico'] !== $supplierIco) {
                continue;
            }
            $year = (int) $agenda['year'];
            $defaultYear = max($defaultYear ?? 0, $year);
            try {
                $backup = PremierBackup::open(PremierUploads::backupDir($supplierId, $token));
                $preflight[(string) $year] = $this->importer->preflight($supplierId, $backup, $year);
            } catch (\Throwable $e) {
                if (!$e instanceof PremierException) {
                    error_log(sprintf('PREMIER: náhled zálohy %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                }
                $preflight[(string) $year] = [['level' => 'error', 'code' => 'backup_unreadable',
                    'message' => $e instanceof PremierException ? $e->getMessage() : 'Zálohu nejde přečíst.', 'context' => []]];
            }
        }
        return Json::ok($response, $meta + [
            'status' => PremierUploads::STATUS_READY,
            'supplier_ico' => $supplierIco,
            'default_year' => $defaultYear,
            'preflight' => (object) $preflight,
        ]);
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function pendingView(int $supplierId, string $token, array $state): array
    {
        $status = (string) ($state['status'] ?? PremierUploads::STATUS_UPLOADING);
        $jobId = isset($state['job_id']) ? (int) $state['job_id'] : null;
        $error = isset($state['error']) ? (string) $state['error'] : null;
        if ($status === PremierUploads::STATUS_PROCESSING || $status === PremierUploads::STATUS_READY) {
            $job = $jobId !== null ? $this->jobs->find($jobId, $supplierId) : null;
            if ($job === null || in_array($job['status'], ['failed', 'cancelled', 'completed', 'completed_with_warnings'], true)) {
                $status = PremierUploads::STATUS_FAILED;
                $error ??= trim((string) ($job['last_error'] ?? '')) ?: 'Zálohu PREMIER se nepodařilo načíst.';
            } else {
                $status = PremierUploads::STATUS_PROCESSING;
            }
        }
        return [
            'token' => $token,
            'status' => $status,
            'file_name' => (string) ($state['file_name'] ?? ''),
            'size' => (int) ($state['size'] ?? 0),
            'received' => $status === PremierUploads::STATUS_UPLOADING ? PremierUploads::partSize($supplierId, $token) : (int) ($state['received'] ?? 0),
            'job_id' => $jobId,
            'error' => $status === PremierUploads::STATUS_FAILED ? $error : null,
        ];
    }

    /** @param array<string,string> $args */
    public function start(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        $body = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($body['mode'] ?? 'dry_run');
        if (!in_array($mode, ['dry_run', 'import'], true)) {
            return Json::error($response, 'invalid_mode', 'Neznámý režim převodu.', 422);
        }
        $year = (int) ($body['year'] ?? 0);
        if ($mode === 'import') {
            $missing = self::missingLiveImportRights($request);
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Ostrý převod zapisuje účetní deník a mění nastavení firmy - chybí oprávnění: '
                    . implode(', ', $missing) . '.', 403, ['missing_permissions' => $missing]);
            }
        }
        try {
            $meta = PremierUploads::meta($supplierId, $token);
        } catch (PremierException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        $agenda = PremierImportJobService::agenda($meta, $supplierIco, $year);
        if ($agenda === null) {
            return Json::error($response, 'invalid_year', 'Záloha neobsahuje účetní rok zvoleného roku s IČO této firmy.', 422);
        }
        PremierUploads::touch($supplierId, $token);
        if (!$this->runs->isLockFree($supplierId)) {
            return Json::error($response, 'already_running', 'Převod této firmy právě běží.', 409);
        }
        $this->jobs->reapStale($supplierId, PremierImportJobService::SOURCE);
        foreach ($this->jobs->listForTenant($supplierId, PremierImportJobService::SOURCE, limit: 20) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true) && !PremierImportJobService::isPrepareJob($existing)) {
                return Json::error($response, 'already_running', "Převod už běží (job #{$existing['id']}).", 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, PremierImportJobService::SOURCE, [
            'token' => $token,
            'mode' => $mode,
            'year' => $year,
            'ico' => $supplierIco,
        ], $userId);
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== PremierImportJobService::SOURCE) {
            $this->jobs->delete($jobId, $supplierId);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro převod z PREMIER - spusťte `php api/bin/migrate.php`.', 500);
        }
        $this->spawnWorker($jobId);
        $this->logger->log('import.premier_started', $userId, 'import_job', $jobId, ['mode' => $mode, 'year' => $year],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'mode' => $mode], 201);
    }

    public function runs(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        return Json::ok($response, ['items' => $this->runs->listRuns(SupplierGuard::currentId($request))]);
    }

    /** @param array<string,string> $args */
    public function run(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $run = $this->runs->findRun((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        if ($run === null) {
            return Json::error($response, 'not_found', 'Protokol převodu nenalezen.', 404);
        }
        return Json::ok($response, $run);
    }

    /**
     * Práva, která ostrý převod navíc potřebuje: zapisuje účetní deník a přepíná režim
     * účetnictví a automatiku. Zkouška nanečisto stačí s `utilities.import`.
     *
     * @return list<string>
     */
    public static function missingLiveImportRights(Request $request): array
    {
        return \MyInvoice\Action\Admin\Import\PohodaMigrationAction::missingLiveImportRights($request);
    }

    private function supplierIco(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return PartnerImporter::ico((string) $stmt->fetchColumn());
    }

    private function spawnWorker(int $jobId): void
    {
        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
    }

    private function deny(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'utilities.import', $level)) {
            return Json::error($response, 'forbidden', 'Převod z PREMIER smí spustit jen admin nebo účetní.', 403);
        }
        if (SupplierGuard::currentId($request) === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        return null;
    }
}
