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
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Průvodce „Přechod z POHODA" - nahrání XML exportu agendy (ZIP), náhled, zkouška
 * nanečisto a ostrý převod zvoleného roku na pozadí, protokoly a exportní nástroj.
 *
 *   POST /api/admin/imports/pohoda/uploads/chunked                {file_name, size}
 *   POST /api/admin/imports/pohoda/uploads/{token}/chunks         multipart `chunk` + `offset`
 *   POST /api/admin/imports/pohoda/uploads/{token}/complete
 *   GET  /api/admin/imports/pohoda/uploads/{token}
 *   POST /api/admin/imports/pohoda/uploads/{token}/start          {mode, year, kind: accounting|payroll}
 *   GET  /api/admin/imports/pohoda/runs
 *   GET  /api/admin/imports/pohoda/runs/{id}
 *   GET  /api/admin/imports/pohoda/tool                           soubory exportního nástroje
 *   GET  /api/admin/imports/pohoda/tool/download[?name=]          ZIP celého nástroje nebo jeden soubor
 *
 * Export se vytváří nástrojem `tools/pohoda-export` přímo u POHODY (oficiální XML
 * rozhraní, jen čte). ZIP se nahrává po částech jako záloha Money S3 a rozbalí ho job na
 * pozadí ({@see PohodaImportJobService::MODE_PREPARE}). Stav běžícího převodu jde přes
 * společné GET /api/admin/imports/{id}.
 */
final class PohodaMigrationAction
{
    private const MAX_EXPORT_BYTES = 2 * 1024 * 1024 * 1024;
    /** Nahraných exportů firmy najednou (nové nahrání smaže nejstarší nečinný). */
    private const MAX_ACTIVE_UPLOADS = 3;
    /** Část exportu: pod `upload_max_filesize`, IIS `maxAllowedContentLength` i nginx `client_max_body_size`. */
    public const CHUNK_BYTES = 8 * 1024 * 1024;
    /**
     * Exportní nástroje po programech. POHODA se exportuje přes XML rozhraní, PAMICA nemá
     * XML rozhraní a čte se přímo z mzdového datového souboru, takže má vlastní skript.
     */
    private const TOOL_DIRS = [
        'pohoda' => '/tools/pohoda-export',
        'pamica' => '/tools/pamica-export',
    ];
    private const TOOL_FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.(ps1|cmd)$/';

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly PohodaImportRepository $runs,
        private readonly PohodaImporter $importer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly PohodaPayrollImporter $payroll,
    ) {}

    /** Založí nahrávání exportu po částech: `{file_name, size}` → `{token, chunk_size}`. */
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
        if ($fileName === '' || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'zip') {
            return Json::error($response, 'invalid_file_type', 'Nahrajte ZIP s XML exportem z POHODY.', 422);
        }
        if (!is_int($size) || $size <= 0) {
            return Json::error($response, 'invalid_size', 'Chybí velikost exportu.', 422);
        }
        if ($size > self::MAX_EXPORT_BYTES) {
            return Json::error($response, 'upload_too_large', 'Export je příliš velký (nejvýš 2 GB).', 413);
        }

        PohodaUploads::purgeStale($supplierId);
        if (!PohodaUploads::makeRoom($supplierId, self::MAX_ACTIVE_UPLOADS)) {
            return Json::error($response, 'too_many_uploads', 'Firma má rozpracovaných příliš mnoho exportů, počkejte na dokončení běžícího zpracování.', 429);
        }
        $token = PohodaUploads::newToken();
        $dir = PohodaUploads::dir($supplierId, $token);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return Json::error($response, 'storage_not_writable', 'Úložiště pro exporty není zapisovatelné.', 500);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        PohodaUploads::writeState($supplierId, $token, [
            'file_name' => $fileName,
            'size' => $size,
            'received' => 0,
            'status' => PohodaUploads::STATUS_UPLOADING,
            'uploaded_by' => (int) ($user['id'] ?? 0),
            'created_at' => date('c'),
            'job_id' => null,
            'error' => null,
        ]);
        touch(PohodaUploads::partPath($supplierId, $token));

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
            return Json::error($response, 'chunk_too_large', 'Část exportu je větší, než server přijme.', 413);
        }
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Chybí část exportu.', 400);
        }
        if (!is_int($offset)) {
            return Json::error($response, 'invalid_offset', 'Chybí pozice části exportu.', 422);
        }
        try {
            $received = PohodaUploads::appendChunk($supplierId, $token, $offset, $file->getStream(), self::CHUNK_BYTES);
        } catch (PohodaException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422, $e->context);
        }
        return Json::ok($response, ['received' => $received]);
    }

    /**
     * Nahrávání je u konce: export se rozbalí a přečte jobem na pozadí.
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
        $this->jobs->reapStale($supplierId, PohodaImportJobService::SOURCE);
        foreach ($this->jobs->listForTenant($supplierId, PohodaImportJobService::SOURCE, limit: 20) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true) && PohodaImportJobService::isPrepareJob($existing)
                && (string) ($existing['params']['token'] ?? '') !== $token) {
                return Json::error($response, 'already_processing', 'Jiný export z POHODY se právě zpracovává, počkejte na jeho dokončení.', 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }
        try {
            $result = PohodaUploads::withUploadLock($supplierId, $token, function () use ($request, $supplierId, $token, $userId): array {
                $state = PohodaUploads::state($supplierId, $token);
                if ($state === null) {
                    throw new PohodaException('upload_not_found', 'Nahrávaný export nebyl nalezen.', [], 404);
                }
                $status = (string) ($state['status'] ?? '');
                if (in_array($status, [PohodaUploads::STATUS_PROCESSING, PohodaUploads::STATUS_READY], true)) {
                    return ['job_id' => isset($state['job_id']) ? (int) $state['job_id'] : null, 'spawn' => false];
                }
                if ($status !== PohodaUploads::STATUS_UPLOADING) {
                    throw new PohodaException('upload_failed', (string) ($state['error'] ?? 'Export z POHODY se nepodařilo načíst.'), [], 409);
                }
                $size = (int) ($state['size'] ?? 0);
                $received = PohodaUploads::partSize($supplierId, $token);
                if ($received !== $size) {
                    throw new PohodaException('upload_incomplete', 'Export ještě není nahraný celý.', ['received' => $received, 'size' => $size], 422);
                }
                $jobId = $this->jobs->create($supplierId, PohodaImportJobService::SOURCE, [
                    'token' => $token,
                    'mode' => PohodaImportJobService::MODE_PREPARE,
                    'ip' => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                    'user_agent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
                ], $userId);
                PohodaUploads::updateState($supplierId, $token, [
                    'status' => PohodaUploads::STATUS_PROCESSING,
                    'received' => $received,
                    'job_id' => $jobId,
                    'error' => null,
                ]);
                return ['job_id' => $jobId, 'spawn' => true];
            });
        } catch (PohodaException $e) {
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
            if (!PohodaUploads::hasMeta($supplierId, $token)) {
                $state = PohodaUploads::state($supplierId, $token);
                if ($state !== null) {
                    return Json::ok($response, $this->pendingView($supplierId, $token, $state));
                }
            }
            $meta = PohodaUploads::meta($supplierId, $token);
        } catch (PohodaException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        $preflight = [];
        $payrollPreflight = [];
        $defaultYear = null;
        foreach ((array) ($meta['agendas'] ?? []) as $agenda) {
            if ($supplierIco === '' || (string) $agenda['ico'] !== $supplierIco) {
                continue;
            }
            $year = (int) $agenda['year'];
            $defaultYear = max($defaultYear ?? 0, $year);
            $agendaDir = PohodaUploads::exportDir($supplierId, $token) . DIRECTORY_SEPARATOR . $agenda['dir'];
            if ((bool) ($agenda['has_payroll'] ?? false)) {
                try {
                    $payrollPreflight[(string) $year] = $this->payroll->preflight($supplierId, $agendaDir . DIRECTORY_SEPARATOR . PohodaExport::FILES['payroll'], $year);
                } catch (\Throwable $e) {
                    error_log(sprintf('POHODA: náhled mezd exportu %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                    $payrollPreflight[(string) $year] = [['level' => 'error', 'code' => 'payroll_unreadable', 'message' => 'Mzdy v exportu nejde přečíst.', 'context' => []]];
                }
            }
            if (!(bool) ($agenda['has_accounting'] ?? true)) {
                continue;
            }
            try {
                $export = PohodaExport::open(PohodaUploads::exportDir($supplierId, $token) . DIRECTORY_SEPARATOR . $agenda['dir']);
                $preflight[(string) $year] = $this->importer->preflight($supplierId, $export);
            } catch (\Throwable $e) {
                if (!$e instanceof PohodaException) {
                    error_log(sprintf('POHODA: náhled exportu %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                }
                $preflight[(string) $year] = [['level' => 'error', 'code' => 'export_unreadable',
                    'message' => $e instanceof PohodaException ? $e->getMessage() : 'Agendu exportu nejde přečíst.', 'context' => []]];
            }
        }
        return Json::ok($response, $meta + [
            'status' => PohodaUploads::STATUS_READY,
            'supplier_ico' => $supplierIco,
            'default_year' => $defaultYear,
            'preflight' => (object) $preflight,
            'payroll_preflight' => (object) $payrollPreflight,
        ]);
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function pendingView(int $supplierId, string $token, array $state): array
    {
        $status = (string) ($state['status'] ?? PohodaUploads::STATUS_UPLOADING);
        $jobId = isset($state['job_id']) ? (int) $state['job_id'] : null;
        $error = isset($state['error']) ? (string) $state['error'] : null;
        if ($status === PohodaUploads::STATUS_PROCESSING || $status === PohodaUploads::STATUS_READY) {
            $job = $jobId !== null ? $this->jobs->find($jobId, $supplierId) : null;
            if ($job === null || in_array($job['status'], ['failed', 'cancelled', 'completed', 'completed_with_warnings'], true)) {
                $status = PohodaUploads::STATUS_FAILED;
                $error ??= trim((string) ($job['last_error'] ?? '')) ?: 'Export z POHODY se nepodařilo načíst.';
            } else {
                $status = PohodaUploads::STATUS_PROCESSING;
            }
        }
        return [
            'token' => $token,
            'status' => $status,
            'file_name' => (string) ($state['file_name'] ?? ''),
            'size' => (int) ($state['size'] ?? 0),
            'received' => $status === PohodaUploads::STATUS_UPLOADING ? PohodaUploads::partSize($supplierId, $token) : (int) ($state['received'] ?? 0),
            'job_id' => $jobId,
            'error' => $status === PohodaUploads::STATUS_FAILED ? $error : null,
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
        $kind = (string) ($body['kind'] ?? 'accounting');
        if (!in_array($kind, ['accounting', 'payroll'], true)) {
            return Json::error($response, 'invalid_kind', 'Neznámý druh převodu.', 422);
        }
        if ($kind === 'payroll') {
            // Mzdy zakládají osoby, vztahy, vstupy a profil importu - i zkouška nanečisto jde celou cestou.
            $missing = self::missingPayrollRights($request);
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Převod mezd zakládá zaměstnance, pracovní vztahy a mzdové vstupy - chybí oprávnění: '
                    . implode(', ', $missing) . '.', 403, ['missing_permissions' => $missing]);
            }
        } elseif ($mode === 'import') {
            $missing = self::missingLiveImportRights($request);
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Ostrý převod zapisuje účetní deník a mění nastavení firmy - chybí oprávnění: '
                    . implode(', ', $missing) . '.', 403, ['missing_permissions' => $missing]);
            }
        }
        try {
            $meta = PohodaUploads::meta($supplierId, $token);
        } catch (PohodaException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        $agenda = PohodaImportJobService::agenda($meta, $supplierIco, $year);
        if ($agenda === null) {
            return Json::error($response, 'invalid_year', 'Export neobsahuje agendu zvoleného roku s IČO této firmy.', 422);
        }
        if ($kind === 'payroll' ? !$agenda['has_payroll'] : !$agenda['has_accounting']) {
            return Json::error($response, 'invalid_kind', $kind === 'payroll'
                ? 'Export zvoleného roku neobsahuje mzdy (91_mzdy.xml).'
                : 'Export zvoleného roku obsahuje jen mzdy, účetnictví v něm není.', 422);
        }
        PohodaUploads::touch($supplierId, $token);
        if (!$this->runs->isLockFree($supplierId)) {
            return Json::error($response, 'already_running', 'Převod této firmy právě běží.', 409);
        }
        $this->jobs->reapStale($supplierId, PohodaImportJobService::SOURCE);
        foreach ($this->jobs->listForTenant($supplierId, PohodaImportJobService::SOURCE, limit: 20) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true) && !PohodaImportJobService::isPrepareJob($existing)) {
                return Json::error($response, 'already_running', "Převod už běží (job #{$existing['id']}).", 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, PohodaImportJobService::SOURCE, [
            'token' => $token,
            'mode' => $mode,
            'year' => $year,
            'ico' => $supplierIco,
            'kind' => $kind,
            // OIČ a ID PPV z PAMICA se převezmou jen s potvrzením, že pocházejí z protokolů ČSSZ.
            'confirm_identifiers' => $kind === 'payroll' && filter_var($body['confirm_identifiers'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // Převzatá docházka a mzdové vstupy se rovnou schválí. Bez toho zůstanou měsíce
            // `open` a vstupy `draft` a mzdový běh nad nimi narazí na blokující kontroly
            // `time_month_not_approved` a `draft_inputs_present`, které nejdou přebít výjimkou.
            'approve_taken_over' => $kind === 'payroll' && filter_var($body['approve_taken_over'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], $userId);
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== PohodaImportJobService::SOURCE) {
            $this->jobs->delete($jobId, $supplierId);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro převod z POHODY - spusťte `php api/bin/migrate.php`.', 500);
        }
        $this->spawnWorker($jobId);
        $this->logger->log('import.pohoda_started', $userId, 'import_job', $jobId, ['mode' => $mode, 'year' => $year, 'kind' => $kind],
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

    /** Soubory exportního nástroje, které si uživatel stáhne k POHODĚ. */
    public function tool(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $variant = self::toolVariant($request);
        $files = [];
        foreach (self::toolFiles($variant) as $name => $path) {
            $files[] = ['name' => $name, 'size' => (int) filesize($path)];
        }
        return Json::ok($response, ['files' => $files]);
    }

    /**
     * Stažení nástroje: bez jména ZIP se všemi soubory, s `?name=` jeden soubor. Jméno jde
     * v query, ne v cestě - IIS i nginx přípony `.cmd`/`.ps1` v cestě URL blokují.
     */
    public function toolDownload(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $variant = self::toolVariant($request);
        $files = self::toolFiles($variant);
        if ($files === []) {
            return Json::error($response, 'tool_missing', 'Exportní nástroj v této instalaci chybí.', 404);
        }
        $name = (string) ($request->getQueryParams()['name'] ?? '');
        if ($name !== '') {
            if (!isset($files[$name])) {
                return Json::error($response, 'not_found', 'Soubor nástroje nenalezen.', 404);
            }
            $response->getBody()->write((string) file_get_contents($files[$name]));
            return $response
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
                ->withHeader('Cache-Control', 'no-store');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pohoda-tool');
        $zip = new \ZipArchive();
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return Json::error($response, 'zip_failed', 'Balíček nástroje se nepodařilo vytvořit.', 500);
        }
        foreach ($files as $fileName => $path) {
            $zip->addFile($path, $variant . '-export/' . $fileName);
        }
        $zip->close();
        $response->getBody()->write((string) file_get_contents($tmp));
        @unlink($tmp);
        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $variant . '-export.zip"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Práva, která ostrý převod navíc potřebuje: zapisuje účetní deník a přepíná režim
     * účetnictví a automatiku. Zkouška nanečisto stačí s `utilities.import`.
     *
     * @return list<string>
     */
    public static function missingLiveImportRights(Request $request): array
    {
        return array_values(array_filter(['accounting.journal.write', 'settings.company.write'],
            static fn (string $key): bool => !RequestAuthorization::allows($request, $key, AccessLevel::WRITE)));
    }

    /**
     * Práva, která převod mezd potřebuje navíc k `utilities.import`: stejná jako ruční
     * import docházky a mezd (vstupy, osoby a vztahy, profil importu).
     *
     * @return list<string>
     */
    public static function missingPayrollRights(Request $request): array
    {
        return array_values(array_filter(['payroll.inputs.write', 'payroll.person.write', 'payroll.settings'],
            static fn (string $key): bool => !RequestAuthorization::allows($request, $key, AccessLevel::WRITE)));
    }

    /** Program, jehož nástroj se servíruje; neznámá hodnota spadne na POHODU. */
    private static function toolVariant(Request $request): string
    {
        $variant = (string) ($request->getQueryParams()['variant'] ?? 'pohoda');
        return isset(self::TOOL_DIRS[$variant]) ? $variant : 'pohoda';
    }

    /** @return array<string,string> jméno => cesta */
    private static function toolFiles(string $variant = 'pohoda'): array
    {
        $dir = self::TOOL_DIRS[$variant] ?? self::TOOL_DIRS['pohoda'];
        $out = [];
        foreach (glob(Bootstrap::rootDir() . $dir . '/*') ?: [] as $path) {
            $name = basename($path);
            if (is_file($path) && preg_match(self::TOOL_FILE_PATTERN, $name) === 1) {
                $out[$name] = $path;
            }
        }
        ksort($out);
        return $out;
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
            return Json::error($response, 'forbidden', 'Převod z POHODY smí spustit jen admin nebo účetní.', 403);
        }
        if (SupplierGuard::currentId($request) === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        return null;
    }
}
