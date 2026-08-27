<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use ZipArchive;

/**
 * Read-only technická validace přenositelného balíčku. ZIP nikdy nerozbaluje;
 * payload čte streamovaně až po kompatibilitní bráně manifestu.
 */
final class CompanyBackupArchiveInspector
{
    private const MANIFEST = 'manifest.json';
    private const CHECKSUMS = 'CHECKSUMS.txt';
    private const README = 'CTI-MNE.txt';

    public function __construct(
        private readonly CompanyBackupFormat $format,
        private readonly BackupUpcasterRegistry $upcasters,
        private readonly CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    public function inspect(
        string $archivePath,
        #[\SensitiveParameter] string $password,
        string $targetAppVersion,
        string $targetSchemaRevision,
    ): CompanyBackupArchiveInspection {
        if ($password === '' || strlen($password) > 1_024) {
            throw new CompanyBackupArchiveException('archive_unlock_failed');
        }
        clearstatcache(true, $archivePath);
        $before = @stat($archivePath);
        if ($before === false || !is_file($archivePath) || is_link($archivePath)) {
            throw new CompanyBackupArchiveException('archive_unreadable');
        }
        $archiveBytes = $before['size'];
        if ($archiveBytes < 1
            || $archiveBytes > $this->limits->maxArchiveBytes
        ) {
            throw new CompanyBackupArchiveException('archive_size_exceeded');
        }
        if (!defined('ZipArchive::EM_AES_256')) {
            throw new CompanyBackupArchiveException('zip_aes256_unavailable');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new CompanyBackupArchiveException('archive_unreadable');
        }

        try {
            if (!$zip->setPassword($password)) {
                throw new CompanyBackupArchiveException('archive_unlock_failed');
            }
            [$entries, $expandedBytes] = $this->inspectCentralDirectory($zip);
            foreach ([self::MANIFEST, self::CHECKSUMS, self::README] as $required) {
                if (!isset($entries[$required]) || $entries[$required]['directory']) {
                    throw new CompanyBackupArchiveException('required_entry_missing', $required);
                }
            }

            $manifestRead = $this->readEntry(
                $zip,
                $entries[self::MANIFEST],
                $this->limits->maxManifestBytes,
                true,
                true,
            );
            $manifestContent = $manifestRead['content'];
            if ($manifestContent === null) {
                throw new \LogicException('Čtení manifestu nevrátilo obsah.');
            }
            $manifest = $this->format->parseManifestHeader($manifestContent);
            $compatibility = $this->format->checkCompatibility(
                $manifest,
                $targetAppVersion,
                $targetSchemaRevision,
                $this->upcasters,
            );
            if (!$compatibility->isCompatible()) {
                throw new CompanyBackupArchiveCompatibilityException($compatibility);
            }

            $checksumsRead = $this->readEntry(
                $zip,
                $entries[self::CHECKSUMS],
                $this->limits->maxChecksumsBytes,
                true,
                false,
            );
            $checksumsContent = $checksumsRead['content'];
            if ($checksumsContent === null) {
                throw new \LogicException('Čtení checksumů nevrátilo obsah.');
            }
            $checksums = CompanyBackupChecksums::parse($checksumsContent);

            $payloadPaths = [];
            foreach ($entries as $path => $entry) {
                if (!$entry['directory'] && $path !== self::CHECKSUMS) {
                    $payloadPaths[] = $path;
                }
            }
            sort($payloadPaths, SORT_STRING);
            if ($checksums->paths() !== $payloadPaths) {
                throw new CompanyBackupArchiveException('checksums_scope_mismatch');
            }

            $entryHashes = [self::MANIFEST => $manifestRead['sha256']];
            foreach ($payloadPaths as $path) {
                if ($path === self::MANIFEST) {
                    continue;
                }
                $read = $this->readEntry(
                    $zip,
                    $entries[$path],
                    $this->limits->maxEntryBytes,
                    false,
                    false,
                );
                $entryHashes[$path] = $read['sha256'];
            }
            foreach ($entryHashes as $path => $actualHash) {
                $expectedHash = $checksums->hashFor($path);
                if ($expectedHash === null || !hash_equals($expectedHash, $actualHash)) {
                    throw new CompanyBackupArchiveException('entry_checksum_mismatch', $path);
                }
            }
        } finally {
            $zip->close();
        }

        clearstatcache(true, $archivePath);
        $after = @stat($archivePath);
        if ($after === false
            || $after['size'] !== $archiveBytes
            || $after['mtime'] !== $before['mtime']
        ) {
            throw new CompanyBackupArchiveException('archive_changed_during_inspection');
        }
        $archiveSha256 = @hash_file('sha256', $archivePath);
        if (!is_string($archiveSha256)) {
            throw new CompanyBackupArchiveException('archive_unreadable');
        }
        clearstatcache(true, $archivePath);
        $afterHash = @stat($archivePath);
        if ($afterHash === false
            || $afterHash['size'] !== $archiveBytes
            || $afterHash['mtime'] !== $before['mtime']
        ) {
            throw new CompanyBackupArchiveException('archive_changed_during_inspection');
        }

        return new CompanyBackupArchiveInspection(
            $manifest,
            $compatibility,
            $archiveSha256,
            count($entries),
            $expandedBytes,
            $entryHashes,
        );
    }

    /**
     * @return array{0:array<string,array{
     *   index:int,
     *   path:string,
     *   directory:bool,
     *   size:int,
     *   compressed_size:int
     * }>,1:int}
     */
    private function inspectCentralDirectory(ZipArchive $zip): array
    {
        $count = $zip->numFiles;
        if ($count < 1 || $count > $this->limits->maxEntries) {
            throw new CompanyBackupArchiveException('archive_entry_count_exceeded');
        }

        $entries = [];
        $collisionPaths = [];
        $expandedBytes = 0;
        $aes256 = ZipArchive::EM_AES_256;
        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new CompanyBackupArchiveException('entry_metadata_invalid');
            }
            $rawPath = $stat['name'];
            $size = $stat['size'];
            $compressedSize = $stat['comp_size'];
            if ($size < 0
                || $compressedSize < 0
            ) {
                throw new CompanyBackupArchiveException(
                    'entry_metadata_invalid',
                    $rawPath,
                );
            }

            $opsys = 0;
            $attributes = 0;
            if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                throw new CompanyBackupArchiveException('entry_metadata_invalid', $rawPath);
            }
            $unixType = 0;
            if ($opsys === ZipArchive::OPSYS_UNIX) {
                $unixType = (($attributes >> 16) & 0xffff) & 0170000;
                if (!in_array($unixType, [0, 0040000, 0100000], true)) {
                    throw new CompanyBackupArchiveException('entry_type_unsupported', $rawPath);
                }
            }
            $nameDirectory = str_ends_with($rawPath, '/');
            $attributeDirectory = $unixType === 0040000;
            if (($attributeDirectory && !$nameDirectory)
                || ($unixType === 0100000 && $nameDirectory)
            ) {
                throw new CompanyBackupArchiveException('entry_type_unsupported', $rawPath);
            }
            $directory = $nameDirectory;
            $path = CompanyBackupArchivePath::normalize($rawPath, $directory);
            $collisionKey = CompanyBackupArchivePath::collisionKey($path);
            if (isset($collisionPaths[$collisionKey])) {
                throw new CompanyBackupArchiveException(
                    'entry_path_duplicate',
                    $collisionPaths[$collisionKey],
                );
            }
            $collisionPaths[$collisionKey] = $path;

            if ($directory) {
                if ($size !== 0) {
                    throw new CompanyBackupArchiveException('entry_type_unsupported', $path);
                }
            } else {
                $encryptionMethod = $stat['encryption_method'];
                if ($encryptionMethod !== $aes256) {
                    throw new CompanyBackupArchiveException('entry_encryption_unsupported', $path);
                }
                if ($size > $this->limits->maxEntryBytes) {
                    throw new CompanyBackupArchiveException('entry_size_exceeded', $path);
                }
                if ($size > 0
                    && $size > max(1, $compressedSize) * $this->limits->maxCompressionRatio
                ) {
                    throw new CompanyBackupArchiveException(
                        'archive_compression_ratio_exceeded',
                        $path,
                    );
                }
                if ($size > $this->limits->maxExpandedBytes - $expandedBytes) {
                    throw new CompanyBackupArchiveException('archive_expanded_size_exceeded', $path);
                }
                $expandedBytes += $size;
            }

            $entries[$path] = [
                'index' => $index,
                'path' => $path,
                'directory' => $directory,
                'size' => $size,
                'compressed_size' => $compressedSize,
            ];
        }

        $filePaths = [];
        foreach ($entries as $path => $entry) {
            if (!$entry['directory']) {
                $filePaths[CompanyBackupArchivePath::collisionKey($path)] = $path;
            }
        }
        foreach ($filePaths as $path) {
            $segments = explode('/', $path);
            array_pop($segments);
            $prefix = '';
            foreach ($segments as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                $prefixKey = CompanyBackupArchivePath::collisionKey($prefix);
                if (isset($filePaths[$prefixKey])) {
                    throw new CompanyBackupArchiveException('entry_path_conflict', $path);
                }
            }
        }
        return [$entries, $expandedBytes];
    }

    /**
     * @param array{index:int,path:string,directory:bool,size:int,compressed_size:int} $entry
     * @return array{content:?string,sha256:string,bytes:int}
     */
    private function readEntry(
        ZipArchive $zip,
        array $entry,
        int $maxBytes,
        bool $collectContent,
        bool $unlockFailure,
    ): array {
        if ($entry['directory']) {
            throw new \LogicException('Adresář nelze číst jako soubor.');
        }
        $stream = @$zip->getStreamIndex($entry['index']);
        if (!is_resource($stream)) {
            throw new CompanyBackupArchiveException(
                $unlockFailure ? 'archive_unlock_failed' : 'entry_unreadable',
                $unlockFailure ? null : $entry['path'],
            );
        }

        $hash = hash_init('sha256');
        $content = $collectContent ? '' : null;
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = @fread($stream, 65_536);
                if (!is_string($chunk) || ($chunk === '' && !feof($stream))) {
                    throw new CompanyBackupArchiveException('entry_unreadable', $entry['path']);
                }
                if ($chunk === '') {
                    break;
                }
                $chunkBytes = strlen($chunk);
                if ($chunkBytes > $maxBytes - $bytes) {
                    throw new CompanyBackupArchiveException('entry_size_exceeded', $entry['path']);
                }
                $bytes += $chunkBytes;
                hash_update($hash, $chunk);
                if ($content !== null) {
                    $content .= $chunk;
                }
            }
        } finally {
            fclose($stream);
        }
        if ($bytes !== $entry['size']) {
            throw new CompanyBackupArchiveException('entry_size_mismatch', $entry['path']);
        }
        return [
            'content' => $content,
            'sha256' => hash_final($hash),
            'bytes' => $bytes,
        ];
    }
}
