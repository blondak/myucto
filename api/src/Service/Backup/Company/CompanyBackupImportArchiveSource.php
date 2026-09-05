<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use ZipArchive;

/**
 * Otevřená read-only session nad technicky ověřeným archivem. JSONL položky
 * lze plně přehrát opakovaně; plaintext secret payload nikdy nejde na disk.
 */
final class CompanyBackupImportArchiveSource
{
    private const READ_CHUNK_BYTES = 65_536;

    private readonly CompanyBackupJsonlReader $reader;

    private readonly ZipArchive $zip;

    /** @var array<string,CompanyBackupDataObject> */
    private array $objects = [];

    /** @var array<string,TenantDataDefinition> */
    private array $definitions = [];

    /** @var array{size:int,mtime:int,sha256:string} */
    private array $initialState;

    private string $password;

    private bool $active = false;

    private bool $secretRead = false;

    private bool $closed = false;

    public function __construct(
        private readonly string $archivePath,
        #[\SensitiveParameter] string $password,
        private readonly CompanyBackupTechnicalValidation $validation,
        private readonly CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
        private readonly CompanyBackupSecretEnvelopeCipher $cipher =
            new CompanyBackupSecretEnvelopeCipher(),
    ) {
        if ($password === '' || strlen($password) > 1_024) {
            throw self::error('source_archive_unlock_failed');
        }
        $this->reader = new CompanyBackupJsonlReader($limits);
        $this->initialState = $this->archiveState();
        if (!hash_equals(
            $validation->inspection->archiveSha256,
            $this->initialState['sha256'],
        )) {
            throw self::error('source_archive_changed');
        }

        foreach ($validation->inspection->dataInventory->objects as $object) {
            $definition = $validation->inspection->sourceRegistry->registry
                ->definition($object->registryKey);
            if (!$definition instanceof TenantDataDefinition
                || isset($this->objects[$object->registryKey])
            ) {
                throw self::error(
                    'source_registry_object_missing',
                    $object->registryKey,
                );
            }
            $this->objects[$object->registryKey] = $object;
            $this->definitions[$object->registryKey] = $definition;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw self::error('source_archive_unreadable');
        }
        if (!$zip->setPassword($password)) {
            $zip->close();
            throw self::error('source_archive_unlock_failed');
        }
        $this->password = $password;
        $this->zip = $zip;
    }

    /**
     * @param callable(array<string,mixed>):void $rowVisitor
     * @param null|callable(CompanyBackupReferenceOccurrence):void $referenceVisitor
     */
    public function consumeRows(
        string $registryKey,
        callable $rowVisitor,
        ?callable $referenceVisitor = null,
    ): int {
        $this->assertAvailable();
        $object = $this->objects[$registryKey] ?? null;
        $definition = $this->definitions[$registryKey] ?? null;
        if (!$object instanceof CompanyBackupDataObject
            || !$definition instanceof TenantDataDefinition
        ) {
            throw self::error('source_data_entry_missing', $registryKey);
        }
        $index = $this->zip->locateName($object->path);
        if (!is_int($index)) {
            throw self::error('source_data_entry_missing', $registryKey);
        }
        $stat = $this->zip->statIndex($index);
        if (!is_array($stat)
            || $stat['name'] !== $object->path
            || $stat['size'] !== $object->bytes
        ) {
            throw self::error('source_data_entry_changed', $registryKey);
        }
        $stream = @$this->zip->getStreamIndex($index);
        if (!is_resource($stream)) {
            throw self::error('source_archive_unlock_failed');
        }

        $this->active = true;
        $rows = 0;
        $failure = null;
        try {
            foreach ($this->reader->rows(
                $stream,
                $definition,
                $object,
                $referenceVisitor,
            ) as $row) {
                $rowVisitor($row);
                $rows++;
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }
        if (!@fclose($stream) && $failure === null) {
            $failure = self::error(
                'source_data_entry_close_failed',
                $registryKey,
            );
        }
        $this->active = false;
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        return $rows;
    }

    public function secretPayload(): ?CompanyBackupSecretPayload
    {
        $this->assertAvailable();
        if ($this->secretRead) {
            throw self::error('source_secret_payload_already_read');
        }
        $descriptor = $this->validation->inspection->secretInventory->envelope;
        if (!$descriptor instanceof CompanyBackupSecretEnvelopeDescriptor) {
            $this->secretRead = true;
            return null;
        }

        $ciphertext = $this->readExactEntry(
            $descriptor->path,
            $descriptor->bytes,
            $descriptor->sha256,
        );
        $plaintext = '';
        try {
            $sealed = CompanyBackupSealedSecretEnvelope::fromArray(
                $descriptor->toArray(),
                $ciphertext,
            );
            $plaintext = $this->cipher->open(
                $sealed,
                $this->password,
                $this->validation->inspection->manifest->backupId,
                $this->validation->inspection->sourceRegistry->fingerprint,
            );
            $payload = CompanyBackupSecretPayload::fromJson(
                $plaintext,
                $this->validation->inspection->sourceRegistry,
            );
        } catch (
            CompanyBackupSecretEnvelopeException
            |CompanyBackupSecretPayloadException $e
        ) {
            throw self::error($e->errorCode, previous: $e);
        } finally {
            self::wipe($plaintext);
            self::wipe($ciphertext);
        }
        $this->secretRead = true;
        return $payload;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->active) {
            throw self::error('source_archive_reader_busy');
        }

        $failure = null;
        try {
            if (!$this->zip->close()) {
                throw self::error('source_archive_close_failed');
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }
        self::wipe($this->password);
        $this->closed = true;
        try {
            $after = $this->archiveState();
            if ($after['size'] !== $this->initialState['size']
                || $after['mtime'] !== $this->initialState['mtime']
                || !hash_equals(
                    $this->initialState['sha256'],
                    $after['sha256'],
                )
            ) {
                throw self::error('source_archive_changed');
            }
        } catch (\Throwable $e) {
            $failure ??= $e;
        }
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }
        if (!$this->active) {
            try {
                $this->zip->close();
            } catch (\Throwable) {
                // Destruktor nesmí překrýt primární chybu importu.
            }
        }
        self::wipe($this->password);
        $this->closed = true;
    }

    private function assertAvailable(): void
    {
        if ($this->closed) {
            throw self::error('source_archive_reader_closed');
        }
        if ($this->active) {
            throw self::error('source_archive_reader_busy');
        }
    }

    private function readExactEntry(
        string $path,
        int $expectedBytes,
        string $expectedSha256,
    ): string {
        $index = $this->zip->locateName($path);
        if (!is_int($index)) {
            throw self::error('source_secret_payload_missing');
        }
        $stat = $this->zip->statIndex($index);
        if (!is_array($stat)
            || $stat['name'] !== $path
            || $stat['size'] !== $expectedBytes
            || $expectedBytes < 1
            || $expectedBytes > CompanyBackupSecretEnvelopeDescriptor::MAX_PLAINTEXT_BYTES
                + CompanyBackupSecretEnvelopeDescriptor::CIPHER_TAG_BYTES
        ) {
            throw self::error('source_secret_payload_changed');
        }
        $inspectionHash = $this->validation->inspection->entryHashes[$path]
            ?? null;
        if (!is_string($inspectionHash)
            || !hash_equals($inspectionHash, $expectedSha256)
        ) {
            throw self::error('source_secret_payload_changed');
        }
        $stream = @$this->zip->getStreamIndex($index);
        if (!is_resource($stream)) {
            throw self::error('source_archive_unlock_failed');
        }

        $content = '';
        $failure = null;
        try {
            while (!feof($stream)) {
                $remaining = $expectedBytes - strlen($content);
                if ($remaining < 1) {
                    throw self::error('source_secret_payload_changed');
                }
                $chunk = @fread(
                    $stream,
                    min(self::READ_CHUNK_BYTES, $remaining),
                );
                if (!is_string($chunk)
                    || $chunk === '' && !feof($stream)
                ) {
                    throw self::error('source_secret_payload_unreadable');
                }
                $content .= $chunk;
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }
        if (!@fclose($stream) && $failure === null) {
            $failure = self::error('source_secret_payload_unreadable');
        }
        if ($failure instanceof \Throwable) {
            self::wipe($content);
            throw $failure;
        }
        if (strlen($content) !== $expectedBytes
            || !hash_equals($expectedSha256, hash('sha256', $content))
        ) {
            self::wipe($content);
            throw self::error('source_secret_payload_changed');
        }
        return $content;
    }

    /** @return array{size:int,mtime:int,sha256:string} */
    private function archiveState(): array
    {
        clearstatcache(true, $this->archivePath);
        $before = @stat($this->archivePath);
        if ($before === false
            || !is_file($this->archivePath)
            || is_link($this->archivePath)
            || $before['size'] < 1
            || $before['size'] > $this->limits->maxArchiveBytes
        ) {
            throw self::error('source_archive_unreadable');
        }
        $sha256 = @hash_file('sha256', $this->archivePath);
        if (!is_string($sha256)) {
            throw self::error('source_archive_unreadable');
        }
        clearstatcache(true, $this->archivePath);
        $after = @stat($this->archivePath);
        if ($after === false
            || $after['size'] !== $before['size']
            || $after['mtime'] !== $before['mtime']
        ) {
            throw self::error('source_archive_changed');
        }
        return [
            'size' => $before['size'],
            'mtime' => $before['mtime'],
            'sha256' => $sha256,
        ];
    }

    private static function wipe(string &$value): void
    {
        $sensitive = $value;
        $value = '';
        if ($sensitive !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($sensitive);
        }
    }

    private static function error(
        string $errorCode,
        ?string $registryKey = null,
        ?string $column = null,
        ?\Throwable $previous = null,
    ): CompanyBackupPreflightException {
        return new CompanyBackupPreflightException(
            $errorCode,
            $registryKey,
            $column,
            $previous,
        );
    }
}
