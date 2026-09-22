<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AbstractMigrationImportRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Společný základ průvodců převodem z cizího programu (Money S3, POHODA, PREMIER):
 * nahrání souboru po částech, dokončení nahrávání jobem na pozadí, stav rozpracovaného
 * nahrávání, hlídka „převod už běží", protokoly běhů a práva.
 *
 *   POST {base}/uploads/chunked                {file_name, size} → {token, chunk_size}
 *   POST {base}/uploads/{token}/chunks         multipart `chunk` + `offset`
 *   POST {base}/uploads/{token}/complete
 *   GET  {base}/runs
 *   GET  {base}/runs/{id}
 *   DELETE {base}/runs/{id}                    jen doběhlá zkouška nanečisto
 *
 * Reálný soubor má stovky megabajtů až jednotky gigabajtů, víc než limity PHP
 * i webserverů na jeden požadavek. Průvodce ho proto nahrává po částech pod všemi
 * limity a rozbalení i načtení běží jako job na pozadí
 * ({@see AbstractImportJobService::MODE_PREPARE}). Všechno je vázané na aktuální firmu:
 * nahraný soubor leží pod jejím adresářem a protokoly se čtou se `supplier_id`.
 *
 * Zdroj dodá úložiště, job, třídu výjimky, povolené přípony, limit velikosti a texty.
 */
abstract class AbstractMigrationAction
{
    /** Část souboru: pod `upload_max_filesize`, IIS `maxAllowedContentLength` i nginx `client_max_body_size`. */
    public const CHUNK_BYTES = MigrationUploadLimits::CHUNK_BYTES;

    /** @var class-string<AbstractImportJobService> job zdroje */
    protected const JOB_SERVICE = AbstractImportJobService::class;
    /** Třída výjimky zdroje (má `errorCode` a `context`). */
    protected const EXCEPTION_CLASS = \RuntimeException::class;
    /** @var list<string> povolené přípony nahrávaného souboru (malými písmeny) */
    protected const ALLOWED_EXTENSIONS = [];
    protected const MAX_BYTES = 0;
    /** Nahraných souborů firmy najednou (nové nahrání smaže nejstarší nečinný). */
    protected const MAX_ACTIVE_UPLOADS = MigrationUploadLimits::MAX_ACTIVE_UPLOADS;

    protected const TEXT_DENIED = '';
    protected const TEXT_INVALID_FILE_TYPE = '';
    protected const TEXT_INVALID_SIZE = '';
    protected const TEXT_TOO_LARGE = '';
    protected const TEXT_TOO_MANY_UPLOADS = '';
    protected const TEXT_CHUNK_MISSING = '';
    protected const TEXT_OFFSET_MISSING = '';
    protected const TEXT_ALREADY_PROCESSING = '';
    /** Zpracování nahraného souboru selhalo bez uložené chyby. */
    protected const TEXT_UPLOAD_FAILED = '';
    protected const TEXT_UPLOAD_INCOMPLETE = '';
    protected const TEXT_MIGRATION_REQUIRED = '';
    /** Typ entity běhu a událost activity logu po smazání protokolu zkoušky nanečisto. */
    protected const RUN_ENTITY = '';
    protected const DRY_RUN_DELETED_EVENT = '';

    /** Práva, která ostrý převod navíc potřebuje: zapisuje účetní deník a přepíná režim účetnictví a automatiku. */
    protected const LIVE_IMPORT_RIGHTS = ['accounting.journal.write', 'settings.company.write'];

    public function __construct(
        protected readonly ImportJobRepository $jobs,
        protected readonly AbstractMigrationImportRepository $runs,
        protected readonly ActivityLogger $logger,
        protected readonly IpMatcher $ipMatcher,
    ) {}

    /** Úložiště nahraných souborů zdroje. */
    abstract protected function uploads(): ChunkedUploadStore;

    /** Založí nahrávání souboru po částech: `{file_name, size}` → `{token, chunk_size}`. */
    public function initChunked(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $uploads = $this->uploads();
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $fileName = mb_substr(basename(str_replace('\\', '/', trim((string) ($body['file_name'] ?? '')))), 0, 200);
        $size = filter_var($body['size'] ?? null, FILTER_VALIDATE_INT);
        if ($fileName === '' || !in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), static::ALLOWED_EXTENSIONS, true)) {
            return Json::error($response, 'invalid_file_type', static::TEXT_INVALID_FILE_TYPE, 422);
        }
        if (!is_int($size) || $size <= 0) {
            return Json::error($response, 'invalid_size', static::TEXT_INVALID_SIZE, 422);
        }
        if ($size > static::MAX_BYTES) {
            return Json::error($response, 'upload_too_large', static::TEXT_TOO_LARGE, 413);
        }

        $uploads->purgeStale($supplierId);
        if (!$uploads->makeRoom($supplierId, static::MAX_ACTIVE_UPLOADS)) {
            return Json::error($response, 'too_many_uploads', static::TEXT_TOO_MANY_UPLOADS, 429);
        }
        $token = $uploads->newToken();
        $dir = $uploads->dir($supplierId, $token);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return Json::error($response, 'storage_not_writable', $uploads->messages()->storageNotWritable, 500);
        }
        $uploads->writeState($supplierId, $token, [
            'file_name' => $fileName,
            'size' => $size,
            'received' => 0,
            'status' => ChunkedUploadStore::STATUS_UPLOADING,
            'uploaded_by' => self::userId($request),
            'created_at' => date('c'),
            'job_id' => null,
            'error' => null,
        ]);
        touch($uploads->partPath($supplierId, $token));

        return Json::ok($response, ['token' => $token, 'chunk_size' => static::CHUNK_BYTES], 201);
    }

    /**
     * Připojí část souboru. Přijme jen část, jejíž `offset` navazuje na už nahraná data;
     * jinak 409 s `received`, podle kterého klient pokračuje.
     *
     * @param array<string,string> $args
     */
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
            return Json::error($response, 'chunk_too_large', $this->uploads()->messages()->chunkTooLarge, 413);
        }
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', static::TEXT_CHUNK_MISSING, 400);
        }
        if (!is_int($offset)) {
            return Json::error($response, 'invalid_offset', static::TEXT_OFFSET_MISSING, 422);
        }
        try {
            $received = $this->uploads()->appendChunk($supplierId, $token, $offset, $file->getStream(), static::CHUNK_BYTES);
        } catch (\RuntimeException $e) {
            return $this->sourceError($response, $e);
        }
        return Json::ok($response, ['received' => $received]);
    }

    /**
     * Nahrávání je u konce: soubor se rozbalí a přečte jobem na pozadí (rozbalení
     * gigabajtové zálohy trvá déle, než smí trvat HTTP požadavek). Dokončení je
     * idempotentní: opakované vrátí job, který už běží.
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
        $userId = self::userId($request);
        $job = static::JOB_SERVICE;
        $uploads = $this->uploads();
        // Rozbalení je drahé (gigabajty na disk) - u firmy běží nejvýš jedno najednou.
        $this->jobs->reapStale($supplierId, $job::SOURCE);
        foreach ($this->jobs->listForTenant($supplierId, $job::SOURCE, limit: 20) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true) && $job::isPrepareJob($existing)
                && (string) ($existing['params']['token'] ?? '') !== $token) {
                return Json::error($response, 'already_processing', static::TEXT_ALREADY_PROCESSING, 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }
        try {
            $result = $uploads->withUploadLock($supplierId, $token, function () use ($request, $supplierId, $token, $userId, $job, $uploads): array {
                $state = $uploads->state($supplierId, $token);
                if ($state === null) {
                    throw $this->sourceException('upload_not_found', $uploads->messages()->uploadNotFound, [], 404);
                }
                $status = (string) ($state['status'] ?? '');
                if (in_array($status, [ChunkedUploadStore::STATUS_PROCESSING, ChunkedUploadStore::STATUS_READY], true)) {
                    return ['job_id' => isset($state['job_id']) ? (int) $state['job_id'] : null, 'spawn' => false];
                }
                if ($status !== ChunkedUploadStore::STATUS_UPLOADING) {
                    throw $this->sourceException('upload_failed', (string) ($state['error'] ?? static::TEXT_UPLOAD_FAILED), [], 409);
                }
                $size = (int) ($state['size'] ?? 0);
                $received = $uploads->partSize($supplierId, $token);
                if ($received !== $size) {
                    throw $this->sourceException('upload_incomplete', static::TEXT_UPLOAD_INCOMPLETE, ['received' => $received, 'size' => $size], 422);
                }
                $jobId = $this->jobs->create($supplierId, $job::SOURCE, [
                    'token' => $token,
                    'mode' => $job::MODE_PREPARE,
                    'ip' => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                    'user_agent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
                ], $userId);
                $uploads->updateState($supplierId, $token, [
                    'status' => ChunkedUploadStore::STATUS_PROCESSING,
                    'received' => $received,
                    'job_id' => $jobId,
                    'error' => null,
                ]);
                return ['job_id' => $jobId, 'spawn' => true];
            });
        } catch (\RuntimeException $e) {
            return $this->sourceError($response, $e);
        }

        if ($result['spawn']) {
            $this->spawnWorker((int) $result['job_id']);
        }
        return Json::ok($response, ['token' => $token, 'job_id' => $result['job_id']], 202);
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
     * Smaže protokol zkoušky nanečisto. Protokol ostrého převodu a běžící zkouška zůstávají
     * ({@see AbstractMigrationImportRepository::deleteDryRun()}).
     *
     * @param array<string,string> $args
     */
    public function deleteRun(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $run = $this->runs->findRun($id, $supplierId);
        if ($run === null) {
            return Json::error($response, 'not_found', 'Protokol převodu nenalezen.', 404);
        }
        if (!$this->runs->deleteDryRun($id, $supplierId)) {
            return Json::error($response, 'run_not_deletable',
                'Smazat jde jen doběhlou zkoušku nanečisto. Protokol ostrého převodu zůstává jako záznam převzatých dat.', 409);
        }
        $this->logger->log(static::DRY_RUN_DELETED_EVENT, self::userId($request), static::RUN_ENTITY, $id,
            ['year' => $run['agenda_year'] ?? null, 'ico' => $run['agenda_ico'] ?? null],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }

    /**
     * Soubor se ještě nahrává nebo zpracovává: stav pro průvodce, nebo null, když už je
     * zpracovaný (má `meta.json`) nebo nahraný jedním požadavkem (nemá stav nahrávání).
     *
     * @return array<string,mixed>|null
     */
    protected function pendingUpload(int $supplierId, string $token): ?array
    {
        $uploads = $this->uploads();
        if ($uploads->hasMeta($supplierId, $token)) {
            return null;
        }
        $state = $uploads->state($supplierId, $token);
        return $state !== null ? $this->pendingView($supplierId, $token, $state) : null;
    }

    /**
     * Soubor se ještě nahrává nebo zpracovává. Job, který skončil bez zápisu do stavu
     * (spadlý worker, úklid „mrtvého" jobu), se hlásí jako chyba s textem jobu —
     * jinak by průvodce čekal navěky.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    protected function pendingView(int $supplierId, string $token, array $state): array
    {
        $status = (string) ($state['status'] ?? ChunkedUploadStore::STATUS_UPLOADING);
        $jobId = isset($state['job_id']) ? (int) $state['job_id'] : null;
        $error = isset($state['error']) ? (string) $state['error'] : null;
        if ($status === ChunkedUploadStore::STATUS_PROCESSING || $status === ChunkedUploadStore::STATUS_READY) {
            $job = $jobId !== null ? $this->jobs->find($jobId, $supplierId) : null;
            if ($job === null || in_array($job['status'], ['failed', 'cancelled', 'completed', 'completed_with_warnings'], true)) {
                $status = ChunkedUploadStore::STATUS_FAILED;
                $error ??= trim((string) ($job['last_error'] ?? '')) ?: static::TEXT_UPLOAD_FAILED;
            } else {
                $status = ChunkedUploadStore::STATUS_PROCESSING;
            }
        }
        return [
            'token' => $token,
            'status' => $status,
            'file_name' => (string) ($state['file_name'] ?? ''),
            'size' => (int) ($state['size'] ?? 0),
            'received' => $status === ChunkedUploadStore::STATUS_UPLOADING ? $this->uploads()->partSize($supplierId, $token) : (int) ($state['received'] ?? 0),
            'job_id' => $jobId,
            'error' => $status === ChunkedUploadStore::STATUS_FAILED ? $error : null,
        ];
    }

    /**
     * Hlídka před spuštěním převodu: živý worker drží zámek firmy i tehdy, když job dlouho
     * nehlásí průběh (zkouška nanečisto) — takový job se za mrtvý považovat nesmí. Načítání
     * nahraného souboru není převod a spuštění neblokuje.
     */
    protected function alreadyRunning(Response $response, int $supplierId): ?Response
    {
        if (!$this->runs->isLockFree($supplierId)) {
            return Json::error($response, 'already_running', 'Převod této firmy právě běží.', 409);
        }
        $job = static::JOB_SERVICE;
        $this->jobs->reapStale($supplierId, $job::SOURCE);
        foreach ($this->jobs->listForTenant($supplierId, $job::SOURCE, limit: 20) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true) && !$job::isPrepareJob($existing)) {
                return Json::error($response, 'already_running', "Převod už běží (job #{$existing['id']}).", 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }
        return null;
    }

    /**
     * Založí job převodu. Bez migrace, která přidá zdroj do `import_jobs.source`, by MariaDB
     * hodnotu tiše uložila prázdnou — takový job se smaže a vrátí se chyba.
     *
     * @param array<string,mixed> $params
     * @return int|Response id jobu, nebo chybová odpověď
     */
    protected function createRunJob(Response $response, int $supplierId, array $params, int $userId): int|Response
    {
        $source = (static::JOB_SERVICE)::SOURCE;
        $jobId = $this->jobs->create($supplierId, $source, $params, $userId);
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== $source) {
            $this->jobs->delete($jobId, $supplierId);
            return Json::error($response, 'migration_required', static::TEXT_MIGRATION_REQUIRED, 500);
        }
        return $jobId;
    }

    protected function spawnWorker(int $jobId): void
    {
        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
    }

    protected function deny(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'utilities.import', $level)) {
            return Json::error($response, 'forbidden', static::TEXT_DENIED, 403);
        }
        if (SupplierGuard::currentId($request) === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        return null;
    }

    /**
     * Práva s úrovní zápisu, která uživateli chybí.
     *
     * @param list<string> $required
     * @return list<string>
     */
    public static function missingRights(Request $request, array $required): array
    {
        return array_values(array_filter($required,
            static fn (string $key): bool => !RequestAuthorization::allows($request, $key, AccessLevel::WRITE)));
    }

    protected static function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    /** Výjimka zdroje jako JSON chyba; jiná výjimka letí dál. */
    protected function sourceError(Response $response, \RuntimeException $e): Response
    {
        if (!is_a($e, static::EXCEPTION_CLASS)) {
            throw $e;
        }
        return Json::error($response, $e->errorCode, $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422, $e->context);
    }

    /** @param array<string,mixed> $context */
    protected function sourceException(string $code, string $message, array $context = [], int $status = 422): \RuntimeException
    {
        $class = static::EXCEPTION_CLASS;
        return new $class($code, $message, $context, $status);
    }
}
