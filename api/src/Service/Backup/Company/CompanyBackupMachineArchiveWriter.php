<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Zapíše uzavřený strojový snapshot jako jeden self-checkovaný AES-256 ZIP. */
final readonly class CompanyBackupMachineArchiveWriter
{
    public function __construct(
        private CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {}

    public function write(
        CompanyBackupMachineSnapshot $snapshot,
        string $archivePath,
        #[\SensitiveParameter] string $password,
        string $sourceAppVersion,
        string $readme,
        string $schemaRevision = CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
    ): CompanyBackupArchiveWriteResult {
        $manifest = CompanyBackupManifest::fromMachineSnapshot(
            $snapshot,
            $sourceAppVersion,
            $schemaRevision,
        );
        $format = new CompanyBackupFormat([
            CompanyBackupSecretEnvelopeDescriptor::CAPABILITY,
        ]);
        $writer = new CompanyBackupArchiveWriter(
            $archivePath,
            $password,
            $format,
            $this->limits,
        );

        try {
            foreach ($snapshot->sourceFiles as $entryPath => $sourcePath) {
                $writer->addFile($entryPath, $sourcePath);
            }
            if ($snapshot->secretEnvelope !== null) {
                $writer->addSecretEnvelope($snapshot->secretEnvelope);
            }
            return $writer->finish($manifest, $readme);
        } catch (\Throwable $e) {
            $writer->abort();
            throw $e;
        }
    }
}
