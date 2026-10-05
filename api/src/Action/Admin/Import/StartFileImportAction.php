<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Import\FileImportJobService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/admin/import/start?kind=auto|issued|purchase[&stage=1][&upload=<token>]
 *   multipart/form-data: files[]
 *
 * Totéž co synchronní {@see \MyInvoice\Action\Admin\ImportAction}, ale na pozadí:
 * soubory se odloží na disk, vznikne `import_jobs` řádek (source `file_import`) a běh
 * převezme worker. UI polluje stav přes {@see ImportJobStatusAction} a po doběhnutí si
 * stáhne report přes {@see ImportJobReportAction}.
 *
 * Dávka z jiného systému má běžně tisíce dokladů — synchronní cesta na ni nestačí a
 * její utnutí uprostřed je horší než selhání, protože doklady zůstanou založené, ale
 * závěrečné kroky importu (dorovnání číselných řad, přepočet statistik klientů) už
 * neproběhnou. Synchronní endpoint zůstává pro malé dávky a pro API klienty, kteří
 * chtějí report rovnou v odpovědi; importuje ho tatáž služba.
 *
 * Tisíce souborů jedním požadavkem neprojdou (`max_file_uploads`, `post_max_size`),
 * proto jde dávku nahrát po částech: `stage=1` soubory jen odloží a vrátí `upload_token`,
 * další části ho posílají v `upload`, poslední požadavek bez `stage` (i bez souborů)
 * z celé dávky založí jeden job. Bez `stage` i `upload` je to jeden požadavek jako dřív.
 */
final class StartFileImportAction
{
    /**
     * Strop celé dávky (přes všechny části). Jeden požadavek dál omezuje PHP, proto
     * klient posílá po částech.
     */
    private const MAX_FILES        = 5000;
    private const MAX_PER_FILE     = 40 * 1024 * 1024;
    private const MAX_TOTAL_UPLOAD = 128 * 1024 * 1024;

    /** Rozpracovaný upload, který nikdo nedokončil, se uklidí po dni. */
    private const STALE_UPLOAD_SECONDS = 86400;

    private const MANIFEST = 'manifest.json';

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $query = $request->getQueryParams();
        $stage = (string) ($query['stage'] ?? '') === '1';
        $token = (string) ($query['upload'] ?? '');
        $userId = (int) ($user['id'] ?? 0);

        $postLimit = ini_parse_quantity((string) ini_get('post_max_size'));
        $contentLength = (int) ($request->getServerParams()['CONTENT_LENGTH'] ?? $request->getHeaderLine('Content-Length'));
        if ($postLimit > 0 && $contentLength > $postLimit) {
            return Json::error($response, 'upload_too_large', 'Požadavek překračuje povolenou velikost uploadu.', 413);
        }
        $uploads = [];
        $uploadError = UPLOAD_ERR_OK;
        $walk = function ($node) use (&$walk, &$uploads, &$uploadError): void {
            if ($node instanceof UploadedFileInterface) {
                if ($node->getError() === UPLOAD_ERR_OK) {
                    $uploads[] = $node;
                } elseif ($node->getError() !== UPLOAD_ERR_NO_FILE) {
                    $uploadError = $node->getError();
                }
            } elseif (is_array($node)) {
                foreach ($node as $sub) $walk($sub);
            }
        };
        foreach ($request->getUploadedFiles() as $node) $walk($node);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání části dávky selhalo. Import nebyl spuštěn.',
                in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400);
        }
        if (isset($query['file_count']) && (int) $query['file_count'] !== count($uploads)) {
            return Json::error($response, 'upload_incomplete', 'Část dávky se nenahrála celá. Import nebyl spuštěn.', 400);
        }

        $lockName = NamedLockName::for($this->db, 'file_import_upload', $supplierId);
        $lock = $this->db->pdo()->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            return Json::error($response, 'upload_busy', 'Jiná část uploadu se právě ukládá. Opakujte požadavek.', 409);
        }
        try {
            return $this->acceptUpload($request, $response, $supplierId, $userId, $stage, $token, $uploads);
        } finally {
            $this->db->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    private function acceptUpload(Request $request, Response $response, int $supplierId, int $userId, bool $stage, string $token, array $uploads): Response
    {
        $query = $request->getQueryParams();

        if ($token !== '') {
            if (preg_match('/^[a-f0-9]{16}$/', $token) !== 1) {
                return Json::error($response, 'upload_not_found', 'Rozpracovaný upload neexistuje.', 404);
            }
            $dir = FileImportJobService::stagingDir($supplierId, $token);
            $manifest = $this->readManifest($dir);
            if ($manifest === null || (int) ($manifest['user_id'] ?? 0) !== $userId) {
                return Json::error($response, 'upload_not_found', 'Rozpracovaný upload neexistuje nebo už byl odeslán.', 404);
            }
        } else {
            $kind = (string) ($query['kind'] ?? 'auto');
            if (!in_array($kind, ['auto', 'issued', 'purchase'], true)) {
                return Json::error($response, 'invalid_kind', "Neznámý kind '{$kind}', použij auto|issued|purchase.", 400);
            }
            if ($uploads === []) {
                return Json::error($response, 'no_files', 'Nahrajte alespoň jeden soubor.', 400);
            }
            if ($running = $this->runningJob($supplierId)) {
                return Json::error($response, 'already_running',
                    "Import už běží (job #{$running['id']}, stav: {$running['status']}).", 409,
                    ['existing_job_id' => $running['id']],
                );
            }
            $this->purgeStaleUploads($supplierId);
            $token = bin2hex(random_bytes(8));
            $dir = FileImportJobService::stagingDir($supplierId, $token);
            // Soubory musí přežít konec requestu — worker je čte až potom.
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return Json::error($response, 'storage_not_writable', 'Úložiště jobů není zapisovatelné.', 500);
            }
            // Viz {@see \MyInvoice\Action\Admin\ImportAction} — výchozí `received`, koncept
            // jen na výslovné přání. Obě cesty musí odpovídat, jinak by týž soubor skončil
            // jinak podle toho, jestli běžel synchronně nebo na pozadí.
            $manifest = [
                'user_id' => $userId,
                'kind' => $kind,
                'purchase_status' => ((string) ($query['purchase_status'] ?? 'received')) === 'draft' ? 'draft' : 'received',
                'bytes' => 0,
                'files' => [],
            ];
        }

        if (count($manifest['files']) + count($uploads) > self::MAX_FILES) {
            return Json::error($response, 'upload_too_large', 'Příliš mnoho souborů (max ' . self::MAX_FILES . ').', 413);
        }
        $total = (int) $manifest['bytes'];
        foreach ($uploads as $u) {
            $size = (int) ($u->getSize() ?? 0);
            if ($size > self::MAX_PER_FILE) {
                return Json::error($response, 'upload_too_large',
                    'Soubor "' . ($u->getClientFilename() ?? 'upload') . '" je příliš velký (max ' . self::MAX_PER_FILE . ' B).', 413);
            }
            $total += $size;
        }
        if ($total > self::MAX_TOTAL_UPLOAD) {
            return Json::error($response, 'upload_too_large',
                'Celková velikost uploadu překračuje povolený limit (max ' . self::MAX_TOTAL_UPLOAD . ' B).', 413);
        }

        foreach ($uploads as $u) {
            // Do cesty jde jen index — jméno z uploadu se ukládá zvlášť jako metadata,
            // takže se přes ně nedá ukázat mimo adresář jobu.
            $i = count($manifest['files']);
            try {
                $u->moveTo($dir . '/' . $i . '.bin');
            } catch (\Throwable) {
                $this->purge($dir);
                return Json::error($response, 'move_failed', 'Uložení nahraných souborů selhalo.', 500);
            }
            $manifest['files'][$i] = ['name' => mb_scrub(basename((string) ($u->getClientFilename() ?? ('soubor-' . $i))), 'UTF-8')];
        }
        $manifest['bytes'] = $total;

        if ($stage) {
            $encoded = json_encode($manifest, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false || @file_put_contents($dir . '/' . self::MANIFEST, $encoded) === false) {
                $this->purge($dir);
                return Json::error($response, 'storage_not_writable', 'Úložiště jobů není zapisovatelné.', 500);
            }
            return Json::ok($response, ['upload_token' => $token, 'status' => 'uploading', 'files' => count($manifest['files'])], 202);
        }
        @unlink($dir . '/' . self::MANIFEST);
        if ($manifest['files'] === []) {
            $this->purge($dir);
            return Json::error($response, 'no_files', 'Nahrajte alespoň jeden soubor.', 400);
        }
        // Mezi částmi mohl začít jiný import — dva joby nad jednou firmou souběžně neběží.
        if ($running = $this->runningJob($supplierId)) {
            $this->purge($dir);
            return Json::error($response, 'already_running',
                "Import už běží (job #{$running['id']}, stav: {$running['status']}).", 409,
                ['existing_job_id' => $running['id']],
            );
        }

        $kind = (string) $manifest['kind'];
        $purchaseStatus = (string) $manifest['purchase_status'];
        $params = [
            'kind' => $kind,
            'purchase_status' => $purchaseStatus,
            'staging_dir' => $dir,
            'files' => $manifest['files'],
        ];
        $jobId = $this->jobs->create($supplierId, 'file_import', $params, $userId);

        // Chybějící migrace by MySQL v ne-striktním režimu uložila jako prázdný source
        // a job by uvízl navždy — radši ho hned zrušit a říct proč.
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== 'file_import') {
            $this->jobs->delete($jobId, $supplierId);
            $this->purge($dir);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro import na pozadí — spusťte `php api/bin/migrate.php`.', 500);
        }

        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.file_started', $userId, 'import_job', $jobId,
            ['files' => count($manifest['files']), 'kind' => $kind, 'purchase_status' => $purchaseStatus],
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'job_id' => $jobId,
            'status' => 'queued',
            'files'  => count($manifest['files']),
            'kind'   => $kind,
        ], 201);
    }

    /** @return array<string,mixed>|null */
    private function runningJob(int $supplierId): ?array
    {
        // Zaseknuté joby (mrtvý worker) by jinak navždy blokovaly nový start.
        $this->jobs->reapStale($supplierId, 'file_import');
        foreach ($this->jobs->listForTenant($supplierId, 'file_import', limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return $existing;
            }
        }
        return null;
    }

    /** @return array{user_id:int,kind:string,purchase_status:string,bytes:int,files:array<int,array{name:string}>}|null */
    private function readManifest(string $dir): ?array
    {
        $raw = @file_get_contents($dir . '/' . self::MANIFEST);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            return null;
        }
        $data['files'] = array_values($data['files']);
        return $data;
    }

    /** Rozpracované uploady, které nikdo nedokončil (zavřený prohlížeč), by v úložišti zůstaly navždy. */
    private function purgeStaleUploads(int $supplierId): void
    {
        foreach ((array) glob(RuntimePaths::storage('import-jobs/' . $supplierId) . '/*/' . self::MANIFEST) as $manifest) {
            if (is_string($manifest) && (int) @filemtime($manifest) < time() - self::STALE_UPLOAD_SECONDS) {
                $this->purge(dirname($manifest));
            }
        }
    }

    private function purge(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($dir);
    }
}
