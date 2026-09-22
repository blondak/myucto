<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\ImportYears;
use MyInvoice\Service\Migration\Pohoda\ChartJournalImporter;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Průvodce „Přechod z POHODA" - nahrání XML exportu agendy (ZIP), náhled, zkouška
 * nanečisto a ostrý převod vybraných roků na pozadí, protokoly a exportní nástroj.
 *
 *   POST /api/admin/imports/pohoda/uploads/chunked                {file_name, size}
 *   POST /api/admin/imports/pohoda/uploads/{token}/chunks         multipart `chunk` + `offset`
 *   POST /api/admin/imports/pohoda/uploads/{token}/complete
 *   GET  /api/admin/imports/pohoda/uploads/{token}
 *   POST /api/admin/imports/pohoda/uploads/{token}/start          {mode, years: int[] (nebo year), kind: accounting|payroll}
 *   GET  /api/admin/imports/pohoda/runs
 *   GET  /api/admin/imports/pohoda/runs/{id}
 *   DELETE /api/admin/imports/pohoda/runs/{id}                    jen zkouška nanečisto
 *   GET  /api/admin/imports/pohoda/tool                           soubory exportního nástroje
 *   GET  /api/admin/imports/pohoda/tool/download[?name=]          ZIP celého nástroje nebo jeden soubor
 *
 * Export se vytváří nástrojem `tools/pohoda-export` přímo u POHODY (oficiální XML
 * rozhraní, jen čte). ZIP se nahrává po částech jako záloha Money S3 a rozbalí ho job na
 * pozadí ({@see AbstractMigrationAction}). Stav běžícího převodu jde přes společné
 * GET /api/admin/imports/{id}.
 */
final class PohodaMigrationAction extends AbstractMigrationAction
{
    protected const JOB_SERVICE = PohodaImportJobService::class;
    protected const EXCEPTION_CLASS = PohodaException::class;
    protected const ALLOWED_EXTENSIONS = ['zip'];
    protected const MAX_BYTES = MigrationUploadLimits::POHODA_MAX_BYTES;

    protected const TEXT_DENIED = 'Převod z POHODY smí spustit jen admin nebo účetní.';
    protected const TEXT_INVALID_FILE_TYPE = 'Nahrajte ZIP s XML exportem z POHODY.';
    protected const TEXT_INVALID_SIZE = 'Chybí velikost exportu.';
    protected const TEXT_TOO_LARGE = 'Export je příliš velký (nejvýš 2 GB).';
    protected const TEXT_TOO_MANY_UPLOADS = 'Firma má rozpracovaných příliš mnoho exportů, počkejte na dokončení běžícího zpracování.';
    protected const TEXT_CHUNK_MISSING = 'Chybí část exportu.';
    protected const TEXT_OFFSET_MISSING = 'Chybí pozice části exportu.';
    protected const TEXT_ALREADY_PROCESSING = 'Jiný export z POHODY se právě zpracovává, počkejte na jeho dokončení.';
    protected const TEXT_UPLOAD_FAILED = 'Export z POHODY se nepodařilo načíst.';
    protected const TEXT_UPLOAD_INCOMPLETE = 'Export ještě není nahraný celý.';
    protected const TEXT_MIGRATION_REQUIRED = 'Chybí databázová migrace pro převod z POHODY - spusťte `php api/bin/migrate.php`.';
    protected const RUN_ENTITY = 'pohoda_import';
    protected const DRY_RUN_DELETED_EVENT = 'import.pohoda_dry_run_deleted';

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
        ImportJobRepository $jobs,
        PohodaImportRepository $runs,
        private readonly PohodaImporter $importer,
        ActivityLogger $logger,
        IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly PohodaPayrollImporter $payroll,
    ) {
        parent::__construct($jobs, $runs, $logger, $ipMatcher);
    }

    protected function uploads(): ChunkedUploadStore
    {
        return PohodaUploads::store();
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
            $pending = $this->pendingUpload($supplierId, $token);
            if ($pending !== null) {
                return Json::ok($response, $pending);
            }
            $meta = PohodaUploads::meta($supplierId, $token);
        } catch (PohodaException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        $preflight = [];
        $payrollPreflight = [];
        $defaultYear = null;
        foreach ((array) ($meta['agendas'] ?? []) as $i => $agenda) {
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
                // Přehled nahraný před výběrem roků pozdější roky agendy nemá.
                if (!is_array($agenda['counts']['later_years'] ?? null)) {
                    $meta['agendas'][$i]['counts']['later_years'] = ChartJournalImporter::laterYears($export);
                }
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
        if (!ImportYears::validBody($body)) {
            return Json::error($response, 'invalid_year', 'Roky převodu musí být seznam roků.', 422);
        }
        $years = ImportYears::fromParams($body);
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
        try {
            // Stejný plán jako job: rok s agendou IČO firmy, nebo pozdější rok vybrané agendy.
            PohodaImportJobService::plan($meta, $supplierIco, $years, $kind === 'payroll', $supplierId, $token);
        } catch (PohodaException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 422, $e->context);
        }
        PohodaUploads::touch($supplierId, $token);
        $running = $this->alreadyRunning($response, $supplierId);
        if ($running !== null) {
            return $running;
        }

        $userId = self::userId($request);
        $jobId = $this->createRunJob($response, $supplierId, [
            'token' => $token,
            'mode' => $mode,
            // Vybrané roky vzestupně; `year` = první z nich pro starší čtení parametrů jobu.
            'years' => $years,
            'year' => $years[0],
            'ico' => $supplierIco,
            'kind' => $kind,
            // OIČ a ID PPV z PAMICA se převezmou jen s potvrzením, že pocházejí z protokolů ČSSZ.
            'confirm_identifiers' => $kind === 'payroll' && filter_var($body['confirm_identifiers'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // Převzatá docházka a mzdové vstupy se rovnou schválí. Bez toho zůstanou měsíce
            // `open` a vstupy `draft` a mzdový běh nad nimi narazí na blokující kontroly
            // `time_month_not_approved` a `draft_inputs_present`, které nejdou přebít výjimkou.
            'approve_taken_over' => $kind === 'payroll' && filter_var($body['approve_taken_over'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], $userId);
        if ($jobId instanceof Response) {
            return $jobId;
        }
        $this->spawnWorker($jobId);
        $this->logger->log('import.pohoda_started', $userId, 'import_job', $jobId, ['mode' => $mode, 'years' => $years, 'kind' => $kind],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'mode' => $mode], 201);
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
        return self::missingRights($request, self::LIVE_IMPORT_RIGHTS);
    }

    /**
     * Práva, která převod mezd potřebuje navíc k `utilities.import`: stejná jako ruční
     * import docházky a mezd (vstupy, osoby a vztahy, profil importu).
     *
     * @return list<string>
     */
    public static function missingPayrollRights(Request $request): array
    {
        return self::missingRights($request, ['payroll.inputs.write', 'payroll.person.write', 'payroll.settings']);
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
}
