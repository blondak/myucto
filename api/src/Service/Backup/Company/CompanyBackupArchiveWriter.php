<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use ZipArchive;

/**
 * Jednorázový AES-256 writer přenositelného ZIPu. Cílovou cestu zveřejní až
 * atomickým hardlinkem po nezávislé reader-validaci hotového balíčku.
 */
final class CompanyBackupArchiveWriter
{
    private const MIN_PASSWORD_BYTES = 12;
    private const MAX_PASSWORD_BYTES = 1_024;
    private const FLUSH_ENTRIES = 200;
    private const FLUSH_BYTES = 33_554_432;

    private ?ZipArchive $zip = null;
    private string $partialPath = '';
    private string $password;
    private CompanyBackupArchivePathSet $paths;

    /** @var array<string,array{sha256:string,size:int}> bez CHECKSUMS.txt */
    private array $entryHashes = [];

    /** @var array<string,array{source:string,size:int,mtime:int,sha256:string}> */
    private array $pendingFiles = [];

    private int $expandedBytes = 0;
    private int $pendingEntries = 0;
    private int $pendingBytes = 0;
    private bool $finished = false;
    private bool $aborted = false;

    public function __construct(
        private readonly string $archivePath,
        #[\SensitiveParameter] string $password,
        private readonly CompanyBackupFormat $format,
        private readonly CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $passwordBytes = strlen($password);
        if ($passwordBytes < self::MIN_PASSWORD_BYTES
            || $passwordBytes > self::MAX_PASSWORD_BYTES
            || str_contains($password, "\0")
        ) {
            throw new CompanyBackupArchiveWriteException('archive_password_weak');
        }
        if (!defined('ZipArchive::EM_AES_256')) {
            throw new CompanyBackupArchiveWriteException('zip_aes256_unavailable');
        }
        if ($limits->maxEntries < 3) {
            throw new CompanyBackupArchiveWriteException('archive_entry_count_exceeded');
        }
        if ($archivePath === '' || $this->destinationExists()) {
            throw new CompanyBackupArchiveWriteException('archive_destination_exists');
        }
        $directory = dirname($archivePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new CompanyBackupArchiveWriteException('archive_destination_unwritable');
        }

        $this->password = $password;
        $this->paths = new CompanyBackupArchivePathSet();
        foreach (CompanyBackupArchiveLayout::REQUIRED_ENTRIES as $entry) {
            $this->paths->add($entry, false);
        }

        try {
            $this->partialPath = $archivePath . '.part-' . bin2hex(random_bytes(12));
            $this->openZip(true);
        } catch (\Throwable $e) {
            $this->cleanupPartial();
            $this->password = '';
            if ($e instanceof CompanyBackupArchiveWriteException) {
                throw $e;
            }
            throw new CompanyBackupArchiveWriteException('archive_create_failed', null, $e);
        }
    }

    public function __destruct()
    {
        $this->abort();
    }

    public function addString(string $entryPath, string $contents): void
    {
        $this->assertOpen();
        try {
            $path = $this->registerPayload($entryPath, strlen($contents));
            $zip = $this->requireZip();
            if (!$zip->addFromString($path, $contents)) {
                throw new CompanyBackupArchiveWriteException('archive_entry_add_failed', $path);
            }
            $this->encryptEntry($zip, $path);
            $this->recordEntry($path, hash('sha256', $contents), strlen($contents));
            $this->flushIfNeeded();
        } catch (\Throwable $e) {
            $this->abort();
            throw $e;
        }
    }

    public function addFile(string $entryPath, string $sourcePath): void
    {
        $this->assertOpen();
        try {
            $source = $this->fingerprintSource($sourcePath, $entryPath);
            $path = $this->registerPayload($entryPath, $source['size']);
            $zip = $this->requireZip();
            if (!$zip->addFile($sourcePath, $path)) {
                throw new CompanyBackupArchiveWriteException('archive_entry_add_failed', $path);
            }
            $this->encryptEntry($zip, $path);
            $this->pendingFiles[$path] = [
                'source' => $sourcePath,
                ...$source,
            ];
            $this->recordEntry($path, $source['sha256'], $source['size']);
            $this->flushIfNeeded();
        } catch (\Throwable $e) {
            $this->abort();
            throw $e;
        }
    }

    public function finish(
        CompanyBackupManifest $manifest,
        string $readme,
    ): CompanyBackupArchiveWriteResult {
        $this->assertOpen();
        try {
            $manifestJson = $manifest->canonicalJson();
            if (strlen($manifestJson) > $this->limits->maxManifestBytes) {
                throw new CompanyBackupArchiveWriteException(
                    'entry_size_exceeded',
                    CompanyBackupArchiveLayout::MANIFEST,
                );
            }
            if ($readme === ''
                || preg_match('//u', $readme) !== 1
                || strlen($readme) > $this->limits->maxEntryBytes
            ) {
                throw new CompanyBackupArchiveWriteException(
                    'archive_readme_invalid',
                    CompanyBackupArchiveLayout::README,
                );
            }
            $this->addReservedString(CompanyBackupArchiveLayout::MANIFEST, $manifestJson, true);
            $this->addReservedString(CompanyBackupArchiveLayout::README, $readme, true);

            $checksums = CompanyBackupChecksums::fromEntryHashes($this->entryHashes)
                ->canonicalText();
            if (strlen($checksums) > $this->limits->maxChecksumsBytes) {
                throw new CompanyBackupArchiveWriteException(
                    'entry_size_exceeded',
                    CompanyBackupArchiveLayout::CHECKSUMS,
                );
            }
            $this->addReservedString(CompanyBackupArchiveLayout::CHECKSUMS, $checksums, false);
            $this->flush(false);

            try {
                $inspection = (new CompanyBackupArchiveInspector(
                    $this->format,
                    BackupUpcasterRegistry::empty(),
                    $this->limits,
                ))->inspect(
                    $this->partialPath,
                    $this->password,
                    $manifest->header->sourceAppVersion,
                    $manifest->header->schemaRevision,
                );
            } catch (CompanyBackupArchiveException $e) {
                throw new CompanyBackupArchiveWriteException(
                    'archive_self_check_failed',
                    $e->entry,
                    $e,
                );
            } catch (CompanyBackupArchiveCompatibilityException $e) {
                throw new CompanyBackupArchiveWriteException(
                    'archive_self_check_failed',
                    null,
                    $e,
                );
            }

            if ($this->destinationExists()) {
                throw new CompanyBackupArchiveWriteException('archive_destination_exists');
            }
            if (!@link($this->partialPath, $this->archivePath)) {
                $code = $this->destinationExists()
                    ? 'archive_destination_exists'
                    : 'archive_publish_failed';
                throw new CompanyBackupArchiveWriteException($code);
            }
            $archiveBytes = @filesize($this->archivePath);
            if (!is_int($archiveBytes) || $archiveBytes < 1) {
                @unlink($this->archivePath);
                throw new CompanyBackupArchiveWriteException('archive_publish_failed');
            }

            @unlink($this->partialPath);
            $this->partialPath = '';
            $this->password = '';
            $this->finished = true;
            return new CompanyBackupArchiveWriteResult(
                $this->archivePath,
                $inspection->archiveSha256,
                $archiveBytes,
                $inspection->entryCount,
            );
        } catch (\Throwable $e) {
            $this->abort();
            if ($e instanceof CompanyBackupArchiveWriteException) {
                throw $e;
            }
            throw new CompanyBackupArchiveWriteException('archive_write_failed', null, $e);
        }
    }

    public function abort(): void
    {
        if ($this->finished || $this->aborted) {
            return;
        }
        $this->aborted = true;
        if ($this->zip !== null) {
            $zip = $this->zip;
            $this->zip = null;
            @$zip->close();
        }
        $this->cleanupPartial();
        $this->password = '';
        $this->pendingFiles = [];
    }

    /** @return array{size:int,mtime:int,sha256:string} */
    private function fingerprintSource(string $sourcePath, string $entryPath): array
    {
        clearstatcache(true, $sourcePath);
        $before = @stat($sourcePath);
        if ($before === false || !is_file($sourcePath) || is_link($sourcePath)) {
            throw new CompanyBackupArchiveWriteException('archive_source_unreadable', $entryPath);
        }
        $size = $before['size'];
        if ($size > $this->limits->maxEntryBytes) {
            throw new CompanyBackupArchiveWriteException('entry_size_exceeded', $entryPath);
        }
        $sha256 = @hash_file('sha256', $sourcePath);
        if (!is_string($sha256)) {
            throw new CompanyBackupArchiveWriteException('archive_source_unreadable', $entryPath);
        }
        clearstatcache(true, $sourcePath);
        $after = @stat($sourcePath);
        if ($after === false
            || is_link($sourcePath)
            || $after['size'] !== $size
            || $after['mtime'] !== $before['mtime']
        ) {
            throw new CompanyBackupArchiveWriteException('archive_source_changed', $entryPath);
        }
        return ['size' => $size, 'mtime' => $before['mtime'], 'sha256' => $sha256];
    }

    private function registerPayload(string $rawPath, int $size): string
    {
        if ($size < 0 || $size > $this->limits->maxEntryBytes) {
            throw new CompanyBackupArchiveWriteException('entry_size_exceeded', $rawPath);
        }
        if ($size > $this->limits->maxExpandedBytes - $this->expandedBytes) {
            throw new CompanyBackupArchiveWriteException(
                'archive_expanded_size_exceeded',
                $rawPath,
            );
        }
        $path = $this->paths->add($rawPath, false);
        if (count($this->paths->paths()) > $this->limits->maxEntries) {
            throw new CompanyBackupArchiveWriteException('archive_entry_count_exceeded');
        }
        return $path;
    }

    private function addReservedString(string $path, string $contents, bool $hashed): void
    {
        $size = strlen($contents);
        if ($size > $this->limits->maxEntryBytes
            || $size > $this->limits->maxExpandedBytes - $this->expandedBytes
        ) {
            throw new CompanyBackupArchiveWriteException('entry_size_exceeded', $path);
        }
        $zip = $this->requireZip();
        if (!$zip->addFromString($path, $contents)) {
            throw new CompanyBackupArchiveWriteException('archive_entry_add_failed', $path);
        }
        $this->encryptEntry($zip, $path);
        $this->recordEntry($path, $hashed ? hash('sha256', $contents) : null, $size);
        $this->flushIfNeeded();
    }

    private function encryptEntry(ZipArchive $zip, string $path): void
    {
        if (!$zip->setEncryptionName(
            $path,
            ZipArchive::EM_AES_256,
            $this->password,
        )) {
            throw new CompanyBackupArchiveWriteException('archive_entry_encrypt_failed', $path);
        }
    }

    private function recordEntry(string $path, ?string $sha256, int $size): void
    {
        if ($sha256 !== null) {
            $this->entryHashes[$path] = ['sha256' => $sha256, 'size' => $size];
        }
        $this->expandedBytes += $size;
        $this->pendingEntries++;
        $this->pendingBytes += $size;
    }

    private function flushIfNeeded(): void
    {
        if ($this->pendingEntries >= self::FLUSH_ENTRIES
            || $this->pendingBytes >= self::FLUSH_BYTES
        ) {
            $this->flush(true);
        }
    }

    private function flush(bool $reopen): void
    {
        $zip = $this->requireZip();
        $this->zip = null;
        if (!$zip->close()) {
            throw new CompanyBackupArchiveWriteException('archive_close_failed');
        }
        $this->verifyPendingFiles();
        $size = @filesize($this->partialPath);
        if (!is_int($size) || $size > $this->limits->maxArchiveBytes) {
            throw new CompanyBackupArchiveWriteException('archive_size_exceeded');
        }
        $this->pendingEntries = 0;
        $this->pendingBytes = 0;
        if ($reopen) {
            $this->openZip(false);
        }
    }

    private function verifyPendingFiles(): void
    {
        foreach ($this->pendingFiles as $entry => $expected) {
            clearstatcache(true, $expected['source']);
            $stat = @stat($expected['source']);
            $sha256 = @hash_file('sha256', $expected['source']);
            if ($stat === false
                || is_link($expected['source'])
                || $stat['size'] !== $expected['size']
                || $stat['mtime'] !== $expected['mtime']
                || !is_string($sha256)
                || !hash_equals($expected['sha256'], $sha256)
            ) {
                throw new CompanyBackupArchiveWriteException(
                    'archive_source_changed',
                    $entry,
                );
            }
        }
        $this->pendingFiles = [];
    }

    private function openZip(bool $create): void
    {
        $zip = new ZipArchive();
        $flags = $create ? ZipArchive::CREATE | ZipArchive::EXCL : ZipArchive::CREATE;
        if ($zip->open($this->partialPath, $flags) !== true) {
            throw new CompanyBackupArchiveWriteException('archive_create_failed');
        }
        if (!$zip->setPassword($this->password)) {
            $zip->close();
            throw new CompanyBackupArchiveWriteException('archive_password_failed');
        }
        $this->zip = $zip;
    }

    private function requireZip(): ZipArchive
    {
        if ($this->zip === null) {
            throw new \LogicException('Writer zálohy nemá otevřený ZIP.');
        }
        return $this->zip;
    }

    private function assertOpen(): void
    {
        if ($this->finished || $this->aborted || $this->zip === null) {
            throw new \LogicException('Writer zálohy už nelze použít.');
        }
    }

    private function cleanupPartial(): void
    {
        if ($this->partialPath !== '' && (is_file($this->partialPath) || is_link($this->partialPath))) {
            @unlink($this->partialPath);
        }
        $this->partialPath = '';
    }

    /** @phpstan-impure Stav cíle se může změnit souběžně mezi preflightem a publish. */
    private function destinationExists(): bool
    {
        clearstatcache(true, $this->archivePath);
        return @lstat($this->archivePath) !== false;
    }
}
