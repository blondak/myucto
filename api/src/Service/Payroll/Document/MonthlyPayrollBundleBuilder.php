<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use ZipArchive;

final class MonthlyPayrollBundleBuilder
{
    public const VERSION = 'mz-16-monthly-bundle-v1';

    /** @param list<PayrollBundleEntry> $entries */
    public function build(
        string $period,
        string $revisionReference,
        string $sourceSnapshotHash,
        array $entries,
    ): PayrollArtifact {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Payroll bundle period is invalid.');
        }
        usort($entries, static fn (PayrollBundleEntry $a, PayrollBundleEntry $b): int
            => $a->documentId <=> $b->documentId);

        $manifestEntries = [];
        foreach ($entries as $index => $entry) {
            if (!hash_equals($entry->fileSha256, hash('sha256', $entry->bytes))) {
                throw new \RuntimeException('Payroll bundle source document integrity check failed.');
            }
            $extension = $entry->mimeType === 'application/pdf' ? 'pdf' : 'bin';
            $name = sprintf('document-%06d.%s', $index + 1, $extension);
            $manifestEntries[] = [
                'document_id' => $entry->documentId,
                'kind' => $entry->kind->value,
                'entry_name' => $name,
                'file_sha256' => $entry->fileSha256,
                'size_bytes' => strlen($entry->bytes),
                'mime_type' => $entry->mimeType,
            ];
        }
        $manifest = [
            'schema' => self::VERSION,
            'period' => $period,
            'revision_reference' => $revisionReference,
            'revision_snapshot_hash' => $sourceSnapshotHash,
            'entries' => $manifestEntries,
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);

        $tmpDir = RuntimePaths::storage('tmp/payroll-bundles');
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Payroll bundle temporary directory is unavailable.');
        }
        $tmpPath = $tmpDir . '/bundle-' . bin2hex(random_bytes(12)) . '.zip';
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Payroll bundle could not be created.');
            }
            $opened = true;
            foreach ($entries as $index => $entry) {
                $extension = $entry->mimeType === 'application/pdf' ? 'pdf' : 'bin';
                if (!$zip->addFromString(
                    sprintf('document-%06d.%s', $index + 1, $extension),
                    $entry->bytes,
                )) {
                    throw new \RuntimeException('Payroll bundle document could not be added.');
                }
            }
            if (!$zip->addFromString('manifest.json', $manifestJson)) {
                throw new \RuntimeException('Payroll bundle manifest could not be added.');
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Payroll bundle could not be finalized.');
            }
            $opened = false;
            $this->verifyArchive($tmpPath, $entries, $manifestJson);
            $bytes = file_get_contents($tmpPath);
            if (!is_string($bytes)) {
                throw new \RuntimeException('Payroll bundle could not be read.');
            }
        } finally {
            if ($opened) {
                @$zip->close();
            }
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return new PayrollArtifact(
            PayrollDocumentKind::MonthlyBundle,
            $bytes,
            'application/zip',
            'mzdovy-balicek-' . $period . '-' . substr(hash('sha256', $bytes), 0, 12) . '.zip',
            $manifestHash,
            self::VERSION,
            self::VERSION,
            $manifest,
        );
    }

    /** @param list<PayrollBundleEntry> $entries */
    private function verifyArchive(
        string $path,
        array $entries,
        string $manifestJson,
    ): void {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Payroll bundle verification failed.');
        }
        try {
            if ($zip->numFiles !== count($entries) + 1) {
                throw new \RuntimeException('Payroll bundle contains an unexpected number of files.');
            }
            foreach ($entries as $index => $entry) {
                $extension = $entry->mimeType === 'application/pdf' ? 'pdf' : 'bin';
                $bytes = $zip->getFromName(
                    sprintf('document-%06d.%s', $index + 1, $extension),
                );
                if (!is_string($bytes) || !hash_equals($entry->fileSha256, hash('sha256', $bytes))) {
                    throw new \RuntimeException('Payroll bundle document verification failed.');
                }
            }
            if ($zip->getFromName('manifest.json') !== $manifestJson) {
                throw new \RuntimeException('Payroll bundle manifest verification failed.');
            }
        } finally {
            $zip->close();
        }
    }
}
