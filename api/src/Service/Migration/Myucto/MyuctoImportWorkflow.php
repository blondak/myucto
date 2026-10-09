<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Migration\Shared\ChunkedUploadMessages;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use Psr\Http\Message\StreamInterface;

/** Small native profile: chunked upload, mandatory preview and an idempotent apply. */
final class MyuctoImportWorkflow
{
    public const MAX_BYTES = 64 * 1024 * 1024;
    private readonly ChunkedUploadStore $uploads;

    public function __construct(
        private readonly MyuctoExportReader $reader,
        private readonly MyuctoImporter $importer,
        private readonly Config $config
    )
    {
        $this->uploads = new ChunkedUploadStore(
            'myucto-import',
            'export.zip',
            'data',
            static fn (string $code, string $message, array $context, int $status): MyuctoImportException => new MyuctoImportException($code, $message, $context, $status),
            ChunkedUploadMessages::export()
        );
    }

    public function init(int $supplier, int $actor, string $fileName, mixed $size): array
    {
        $size = filter_var($size, FILTER_VALIDATE_INT);
        if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new MyuctoImportException('invalid_file_type', 'Nahrajte ZIP z Kompletního exportu dat MyÚčta.');
        }
        if (!is_int($size) || $size <= 0 || $size > self::MAX_BYTES) {
            throw new MyuctoImportException('upload_too_large', 'ZIP musí mít nejvýše 64 MiB.');
        }
        $this->uploads->purgeStale($supplier);
        if (!$this->uploads->makeRoom($supplier, 3)) {
            throw new MyuctoImportException('too_many_uploads', 'Dokončete nejprve rozpracovaný import.', [], 429);
        }
        $token = $this->uploads->newToken();
        $dir = $this->uploads->dir($supplier, $token);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new MyuctoImportException('storage_not_writable', 'Úložiště pro exporty není zapisovatelné.', [], 500);
        }
        $this->uploads->writeState($supplier, $token, ['file_name' => mb_substr(basename(str_replace('\\', '/', $fileName)), 0, 200), 'size' => $size,
            'received' => 0, 'status' => ChunkedUploadStore::STATUS_UPLOADING, 'uploaded_by' => $actor, 'created_at' => date('c')]);
        return ['token' => $token, 'chunk_size' => MigrationUploadLimits::CHUNK_BYTES];
    }

    public function chunk(
        int $supplier,
        int $actor,
        string $token,
        int $offset,
        StreamInterface $chunk
    ): array
    {
        $this->ownedState($supplier, $actor, $token);
        return ['received' => $this->uploads->appendChunk($supplier, $token, $offset, $chunk, MigrationUploadLimits::CHUNK_BYTES)];
    }

    public function complete(int $supplier, int $actor, string $token): array
    {
        return $this->uploads->withUploadLock($supplier, $token, function () use ($supplier, $actor, $token): array {
            $state = $this->ownedState($supplier, $actor, $token);
            if (($state['status'] ?? '') === ChunkedUploadStore::STATUS_UPLOADING) {
                $size = $this->uploads->partSize($supplier, $token);
                if ($size !== (int) $state['size']) {
                    throw new MyuctoImportException('upload_incomplete', 'Export ještě není nahraný celý.');
                }
                $this->uploads->updateState($supplier, $token, ['received' => $size, 'status' => ChunkedUploadStore::STATUS_READY,
                    'sha256' => hash_file('sha256', $this->uploads->partPath($supplier, $token))]);
            }
            return ['token' => $token, 'job_id' => null];
        });
    }

    public function run(
        int $supplier,
        int $actor,
        string $token,
        string $source,
        ?string $password,
        bool $apply,
        bool $confirmed
    ): array
    {
        $state = $this->ownedState($supplier, $actor, $token);
        if (($state['status'] ?? '') !== ChunkedUploadStore::STATUS_READY) {
            throw new MyuctoImportException('upload_incomplete', 'Export ještě není nahraný celý.');
        }
        $lock = $this->uploads->acquireJobLock($supplier, $token);
        if ($lock === null) {
            throw new MyuctoImportException('already_running', 'Tento import právě běží.', [], 409);
        }
        try {
            $state = $this->ownedState($supplier, $actor, $token);
            if (!preg_match('/\A[a-zA-Z0-9._-]{1,80}\z/D', $source)) {
                throw new MyuctoImportException('invalid_source', 'Vyplňte stabilní název původní instance (písmena, číslice, tečka, pomlčka, podtržítko).');
            }
            $path = $this->uploads->partPath($supplier, $token);
            $sha = hash_file('sha256', $path);
            if (!is_string($sha) || !hash_equals((string) $state['sha256'], $sha)) {
                throw new MyuctoImportException('upload_changed', 'Nahraný export se změnil, nahrajte jej znovu.');
            }
            if (
                $apply
                && (!$confirmed
                || ($state['checked_source'] ?? null) !== $source
                || ($state['checked_sha256'] ?? null) !== $sha)
            ) {
                throw new MyuctoImportException('preview_required', 'Nejprve proveďte úspěšnou kontrolu a potvrďte import do aktuální firmy.', [], 409);
            }
            $package = $this->reader->read($path, $password ?? BackupEncryption::passwordFromConfig($this->config));
            $report = $this->importer->import($package, $supplier, $actor, $source, !$apply);
            $root = reset($package['tables']['supplier']);
            $result = ['token' => $token, 'company_name' => $root['company_name'], 'ic' => $root['ic'], 'report' => $report];
            $this->uploads->updateState($supplier, $token, ['checked_source' => $source, 'checked_sha256' => $sha, 'result' => $result]);
            return $result;
        } finally {
            $this->uploads->releaseJobLock($lock);
        }
    }

    public function show(int $supplier, int $actor, string $token): array
    {
        $state = $this->ownedState($supplier, $actor, $token);
        return ['token' => $token, 'file_name' => $state['file_name'], 'result' => $state['result'] ?? null];
    }

    private function ownedState(int $supplier, int $actor, string $token): array
    {
        $state = $this->uploads->state($supplier, $token);
        if ($state === null || (int) ($state['uploaded_by'] ?? 0) !== $actor) {
            throw new MyuctoImportException('upload_not_found', 'Nahraný export nebyl nalezen.', [], 404);
        }
        return $state;
    }
}
