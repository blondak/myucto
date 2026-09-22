<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxUploads;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/** Nahrání šifrované zálohy, výběr firmy, kontrolní běh a následný převod. */
final class StereoNxMigrationAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly StereoNxImporter $importer,
        private readonly LoggerInterface $log,
    ) {}

    public function init(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $name = basename(str_replace('\\', '/', (string) ($body['file_name'] ?? '')));
        $size = filter_var($body['size'] ?? null, FILTER_VALIDATE_INT);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip' || !is_int($size) || $size < 1) {
            return Json::error($response, 'invalid_backup', 'Vyberte zálohu Stereo NX ve formátu ZIP.', 422);
        }
        if ($size > StereoNxUploads::MAX_BYTES) {
            return Json::error($response, 'upload_too_large', 'Záloha přesahuje limit 2 GB.', 413);
        }
        $sid = SupplierGuard::currentId($request);
        if (!StereoNxUploads::prepareRoom($sid)) {
            return Json::error($response, 'too_many_uploads', 'Firma už má tři nahrané zálohy; některou odstraňte.', 429);
        }
        $token = StereoNxUploads::token();
        $dir = StereoNxUploads::dir($sid, $token);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return Json::error($response, 'storage_error', 'Úložiště záloh není dostupné.', 500);
        }
        StereoNxUploads::save($sid, $token, [
            'status' => 'uploading', 'size' => $size, 'received' => 0,
            'file_name' => mb_substr($name, 0, 200), 'uploaded_by' => $this->userId($request),
            'created_at' => time(),
        ]);
        return Json::ok($response, ['token' => $token, 'chunk_size' => StereoNxUploads::CHUNK_BYTES], 201);
    }

    public function index(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response, AccessLevel::READ)) return $denied;
        return Json::ok($response, ['uploads' => StereoNxUploads::listForUser(
            SupplierGuard::currentId($request), $this->userId($request),
        )]);
    }

    /** @param array<string,string> $args */
    public function chunk(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $offset = filter_var($body['offset'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $file = $request->getUploadedFiles()['chunk'] ?? null;
        if (!is_int($offset) || !$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'invalid_chunk', 'Chybí platná část zálohy.', 422);
        }
        try {
            $sid = SupplierGuard::currentId($request);
            $this->owner($sid, $args['token'], $this->userId($request));
            return Json::ok($response, ['received' => StereoNxUploads::append($sid, $args['token'], $offset, $file->getStream())]);
        } catch (StereoNxException $e) {
            if ($e->errorCode === 'chunk_offset_mismatch') {
                $path = StereoNxUploads::archive(SupplierGuard::currentId($request), $args['token']);
                clearstatcache(true, $path);
                return Json::error($response, $e->errorCode, $e->getMessage(), 409,
                    ['received' => is_file($path) ? (int) filesize($path) : 0]);
            }
            return $this->error($response, $e);
        } catch (\Throwable) {
            return Json::error($response, 'backup_read_failed', 'Zálohu se nepodařilo bezpečně načíst.', 422);
        }
    }

    /** @param array<string,string> $args */
    public function complete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        try {
            $sid = SupplierGuard::currentId($request);
            $this->owner($sid, $args['token'], $this->userId($request));
            return StereoNxUploads::locked($sid, $args['token'], static function (array $state) use ($sid, $args, $response): Response {
                if (($state['status'] ?? '') === 'ready') return Json::ok($response, ['token' => $args['token'], 'job_id' => null]);
                $path = StereoNxUploads::archive($sid, $args['token']);
                clearstatcache(true, $path);
                if (($state['status'] ?? '') !== 'uploading' || !is_file($path) || filesize($path) !== (int) $state['size']) {
                    throw new StereoNxException('upload_incomplete', 'Záloha ještě není nahraná celá.');
                }
                $state['status'] = 'ready';
                $state['sha256'] = hash_file('sha256', $path);
                StereoNxUploads::save($sid, $args['token'], $state);
                return Json::ok($response, ['token' => $args['token'], 'job_id' => null]);
            });
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable) {
            return Json::error($response, 'backup_read_failed', 'Zálohu se nepodařilo bezpečně načíst.', 422);
        }
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, AccessLevel::READ)) return $denied;
        try {
            $state = $this->owner(SupplierGuard::currentId($request), $args['token'], $this->userId($request));
            return Json::ok($response, [
                'token' => $args['token'], 'status' => $state['status'], 'file_name' => $state['file_name'],
                'size' => $state['size'], 'received' => $state['received'],
                'dry_run_company' => $state['dry_run_company'] ?? null,
            ]);
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable) {
            return Json::error($response, 'backup_read_failed', 'Zálohu se nepodařilo bezpečně načíst.', 422);
        }
    }

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        try {
            $sid = SupplierGuard::currentId($request);
            $this->ready($sid, $args['token'], $this->userId($request));
            $password = $this->password($request);
            $companies = StereoNxBackup::companies(StereoNxUploads::archive($sid, $args['token']), $password);
            $target = $this->target($sid);
            $items = [];
            foreach ($companies as $company) {
                $backup = StereoNxBackup::open(StereoNxUploads::archive($sid, $args['token']), $company['index'], $password);
                $identity = $backup->companyIdentity();
                $items[] = ['index' => $company['index'], 'label' => $company['label'],
                    'identity' => $identity, 'matches_target' => $identity['ico'] === $target['ico']];
            }
            return Json::ok($response, ['companies' => $items, 'target' => $target]);
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable) {
            return Json::error($response, 'backup_read_failed', 'Zálohu se nepodařilo bezpečně načíst.', 422);
        }
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        try {
            $sid = SupplierGuard::currentId($request);
            $uid = $this->userId($request);
            $this->owner($sid, $args['token'], $uid);
            StereoNxUploads::locked($sid, $args['token'], static function (array $state) use ($sid, $args): void {
                $state['status'] = 'deleting';
                StereoNxUploads::save($sid, $args['token'], $state);
            });
            StereoNxUploads::delete($sid, $args['token']);
            return Json::ok($response, ['deleted' => true]);
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        }
    }

    /** @param array<string,string> $args */
    public function run(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $company = filter_var($body['company'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $mode = (string) ($body['mode'] ?? '');
        $blankCountryIsCz = ($body['blank_country_is_cz'] ?? false) === true;
        if (!is_int($company) || !in_array($mode, ['dry_run', 'import'], true)) {
            return Json::error($response, 'invalid_selection', 'Vyberte firmu a režim převodu.', 422);
        }
        try {
            $sid = SupplierGuard::currentId($request);
            $uid = $this->userId($request);
            $state = $this->ready($sid, $args['token'], $uid);
            $password = $this->password($request);
            $backup = StereoNxBackup::open(StereoNxUploads::archive($sid, $args['token']), $company, $password);
            $identity = $backup->companyIdentity();
            $target = $this->target($sid);
            if ($identity['ico'] !== $target['ico'] || $target['accounting_mode'] !== 'tax_evidence' || !$identity['vat_payer'] || !$target['vat_payer']) {
                return Json::error($response, 'target_mismatch', 'Firma musí mít shodné IČO a být plátcem DPH v režimu daňové evidence.', 422);
            }
            if (!$this->db->hasTable('stereo_nx_import_map')) {
                return Json::error($response, 'migration_required',
                    'Chybí databázová migrace pro převod ze Stereo NX. Spusťte php api/bin/migrate.php.', 503);
            }
            $hash = hash_file('sha256', StereoNxUploads::archive($sid, $args['token']));
            if (!hash_equals((string) $state['sha256'], $hash)) {
                return Json::error($response, 'archive_changed', 'Nahraná záloha se změnila; nahrajte ji znovu.', 409);
            }
            if ($mode === 'import' && (($state['dry_run_company'] ?? null) !== $company
                || ($state['dry_run_blank_country_is_cz'] ?? null) !== $blankCountryIsCz)) {
                return Json::error($response, 'dry_run_required', 'Před převodem spusťte úspěšnou zkoušku nanečisto pro zvolenou firmu.', 409);
            }
            $token = $args['token'];
            $report = StereoNxUploads::locked($sid, $token, function (array $locked) use ($sid, $uid, $company, $mode, $backup, $hash, $token, $blankCountryIsCz): array {
                if (($locked['status'] ?? '') !== 'ready' || !hash_equals((string) ($locked['sha256'] ?? ''), $hash)) {
                    throw new StereoNxException('upload_changed', 'Nahraná záloha se změnila.');
                }
                if ($mode === 'import' && (($locked['dry_run_company'] ?? null) !== $company
                    || ($locked['dry_run_blank_country_is_cz'] ?? null) !== $blankCountryIsCz)) {
                    throw new StereoNxException('dry_run_required', 'Před převodem spusťte úspěšnou zkoušku nanečisto.');
                }
                $report = $this->importer->run($backup, $sid, $uid, $mode === 'dry_run', $blankCountryIsCz);
                if ($mode === 'dry_run') {
                    $locked['dry_run_company'] = ($report['ok'] ?? false) === true ? $company : null;
                    $locked['dry_run_blank_country_is_cz'] = ($report['ok'] ?? false) === true ? $blankCountryIsCz : null;
                } else {
                    $locked['dry_run_company'] = null;
                    $locked['dry_run_blank_country_is_cz'] = null;
                    $locked['imported_at'] = time();
                }
                StereoNxUploads::save($sid, $token, $locked);
                return $report;
            });
            return Json::ok($response, ['mode' => $mode, 'report' => $report]);
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable $e) {
            $diagnostic = ['exception_class' => $e::class];
            if ($e instanceof \PDOException) {
                $sqlState = (string) $e->getCode();
                if (preg_match('/^[A-Z0-9]{5}$/D', $sqlState)) $diagnostic['sqlstate'] = $sqlState;
                $driverCode = $e->errorInfo[1] ?? null;
                if (is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode))) {
                    $diagnostic['driver_code'] = (int) $driverCode;
                }
            }
            $this->log->error('Stereo NX import failed', $diagnostic);
            return Json::error($response, 'backup_read_failed', 'Převod se nepodařilo dokončit; zkontrolujte stav dat.', 500);
        }
    }

    private function password(Request $request): ?string
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('password', $body)) return null;
        $password = $body['password'];
        if (!is_string($password) || $password === '' || strlen($password) > 512) {
            throw new StereoNxException('password_invalid', 'Neplatné heslo zálohy.');
        }
        return $password;
    }

    /** @return array<string,mixed> */
    private function owner(int $sid, string $token, int $uid): array
    {
        $state = StereoNxUploads::state($sid, $token);
        if ((int) ($state['uploaded_by'] ?? 0) !== $uid || $uid < 1) {
            throw new StereoNxException('upload_not_found', 'Nahraná záloha nebyla nalezena.');
        }
        return $state;
    }

    /** @return array<string,mixed> */
    private function ready(int $sid, string $token, int $uid): array
    {
        $state = $this->owner($sid, $token, $uid);
        if (($state['status'] ?? '') !== 'ready') throw new StereoNxException('upload_incomplete', 'Záloha ještě není nahraná celá.');
        return $state;
    }

    /** @return array{ico:string,accounting_mode:string,vat_payer:bool} */
    private function target(int $sid): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, accounting_mode, is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new StereoNxException('target_missing', 'Cílová firma nebyla nalezena.');
        return ['ico' => preg_replace('/\D/', '', (string) $row['ic']),
            'accounting_mode' => (string) $row['accounting_mode'], 'vat_payer' => (bool) $row['is_vat_payer']];
    }

    private function userId(Request $request): int
    {
        return (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
    }

    private function deny(Request $request, Response $response, AccessLevel $level = AccessLevel::WRITE): ?Response
    {
        if (!RequestAuthorization::allows($request, 'utilities.import', $level)) {
            return Json::error($response, 'forbidden', 'Převod Stereo NX vyžaduje oprávnění k importu.', 403);
        }
        if (SupplierGuard::currentId($request) < 1) return Json::error($response, 'no_supplier', 'Vyberte cílovou firmu.', 400);
        if ($this->userId($request) < 1) return Json::sessionRequired($response);
        return null;
    }

    private function error(Response $response, StereoNxException $e): Response
    {
        $status = in_array($e->errorCode, ['upload_not_found', 'target_missing'], true) ? 404 : 422;
        return Json::error($response, $e->errorCode, $e->getMessage(), $status);
    }
}
