<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveCompatibilityException;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveInspector;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupFormatException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCipher;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeDescriptor;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class CompanyBackupArchiveInspectorTest extends TestCase
{
    private const PASSWORD = 'synthetic-backup-password-42';

    /** @var list<string> */
    private array $archives = [];

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            if (is_file($archive)) {
                @unlink($archive);
            }
        }
    }

    public function testValidatesEncryptedArchiveWithoutExtractingIt(): void
    {
        $payload = $this->payload();
        $archive = $this->archive($payload);

        $inspection = $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );

        self::assertSame('0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1', $inspection->manifest->backupId);
        self::assertSame(
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            $inspection->sourceRegistry->profile,
        );
        self::assertTrue($inspection->compatibility->isCompatible());
        self::assertSame([], $inspection->secretInventory->omissions);
        self::assertSame(hash_file('sha256', $archive), $inspection->archiveSha256);
        self::assertSame(4, $inspection->entryCount);
        self::assertSame(
            ['CTI-MNE.txt', 'data/table-supplier.jsonl', 'manifest.json'],
            array_keys($inspection->entryHashes),
        );
        foreach ($inspection->entryHashes as $path => $sha256) {
            self::assertSame(hash('sha256', $payload[$path]), $sha256);
        }
    }

    public function testWrongPasswordHasOneStableNonOracleError(): void
    {
        $archive = $this->archive($this->payload());

        $this->expectArchiveError('archive_unlock_failed');
        $this->inspector()->inspect(
            $archive,
            'wrong-password',
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testEveryRegularEntryMustUseAes256(): void
    {
        $archive = $this->archive(
            $this->payload(),
            unencrypted: ['data/table-supplier.jsonl'],
        );

        $this->expectArchiveError('entry_encryption_unsupported', 'data/table-supplier.jsonl');
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testRejectsTraversalBeforeReadingPayload(): void
    {
        $payload = $this->payload();
        $payload['../outside.txt'] = 'never extract me';
        $archive = $this->archive($payload);

        $this->expectArchiveError('entry_path_unsafe', '../outside.txt');
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testRejectsCaseInsensitivePathCollision(): void
    {
        $payload = $this->payload();
        $payload['Manifest.json'] = $payload['manifest.json'];
        $archive = $this->archive($payload);

        $this->expectArchiveError('entry_path_duplicate', 'manifest.json');
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testRejectsUnixSymlinkEntry(): void
    {
        $payload = $this->payload();
        $payload['files/link.pdf'] = 'target.pdf';
        $archive = $this->archive(
            $payload,
            unixModes: ['files/link.pdf' => 0120777],
        );

        $this->expectArchiveError('entry_type_unsupported', 'files/link.pdf');
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testRejectsCompressionBombFromCentralDirectoryMetadata(): void
    {
        $payload = $this->payload();
        $payload['data/table-supplier.jsonl'] = str_repeat('A', 10_000);
        $archive = $this->archive($payload);

        $this->expectArchiveError('archive_compression_ratio_exceeded', 'data/table-supplier.jsonl');
        $this->inspector(new CompanyBackupArchiveLimits(
            maxArchiveBytes: 1_000_000,
            maxEntries: 20,
            maxEntryBytes: 20_000,
            maxExpandedBytes: 40_000,
            maxCompressionRatio: 2,
            maxManifestBytes: 4_096,
            maxChecksumsBytes: 4_096,
        ))->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testChecksumScopeMustCoverEveryPayloadEntryExactlyOnce(): void
    {
        $payload = $this->payload();
        $checksums = $this->checksums(array_diff_key(
            $payload,
            ['data/table-supplier.jsonl' => true],
        ));
        $archive = $this->archive($payload, checksums: $checksums);

        $this->expectArchiveError('checksums_scope_mismatch');
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testChecksumMismatchStopsInspection(): void
    {
        $payload = $this->payload();
        $checksums = str_replace(
            hash('sha256', $payload['data/table-supplier.jsonl']),
            str_repeat('f', 64),
            $this->checksums($payload),
        );
        $archive = $this->archive($payload, checksums: $checksums);

        $this->expectArchiveError('entry_checksum_mismatch', 'data/table-supplier.jsonl');
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testManifestInventoryRejectsExtraChecksummedDataEntry(): void
    {
        $payload = $this->payload();
        $payload['data/table-unregistered.jsonl'] = "{\"id\":99}\n";
        $archive = $this->archive($payload);

        $this->expectArchiveError('data_inventory_scope_mismatch');
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testOmissionInventoryRejectsUnregisteredSecretPayload(): void
    {
        $payload = $this->payload();
        $payload['secrets/plaintext.txt'] = 'synthetic-secret-must-not-enter-archive';
        $archive = $this->archive($payload);

        $this->expectArchiveError(
            'secret_inventory_scope_mismatch',
            'secrets/plaintext.txt',
        );
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testAuthenticatesRegisteredSecretEnvelopeWithoutExtraction(): void
    {
        $payload = $this->payload(withSecretEnvelope: true);
        $archive = $this->archive($payload);

        $inspection = $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );

        self::assertNotNull($inspection->secretInventory->envelope);
        self::assertSame(
            'secrets/tenant.sealed',
            $inspection->secretInventory->envelope->path,
        );
        self::assertArrayHasKey(
            'secrets/tenant.sealed',
            $inspection->entryHashes,
        );
        self::assertSame(5, $inspection->entryCount);
    }

    public function testAuthenticatedEnvelopeStillRequiresRegistryBoundPayload(): void
    {
        $archive = $this->archive($this->payload(
            withSecretEnvelope: true,
            invalidSecretPayload: true,
        ));

        $this->expectArchiveError(
            'secret_payload_invalid',
            'secrets/tenant.sealed',
        );
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testProtectedDomainSecretWithoutEnvelopeIsRejected(): void
    {
        $archive = $this->archive($this->payload(protectedSecret: true));

        $this->expectArchiveError('secret_envelope_required');
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testEnvelopeRequiresMatchingRequiredCapability(): void
    {
        $payload = $this->payload(withSecretEnvelope: true);
        $manifest = json_decode(
            $payload['manifest.json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        $manifest['capabilities']['required'] = [];
        $payload['manifest.json'] = (new CompanyBackupFormat())->encodeManifest(
            $manifest,
        );
        $archive = $this->archive($payload);

        try {
            $this->inspector()->inspect(
                $archive,
                self::PASSWORD,
                '5.28.1',
                CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            );
            self::fail('Envelope bez povinné capability nesmí projít manifestem.');
        } catch (CompanyBackupFormatException $e) {
            self::assertSame('manifest_secrets_invalid', $e->errorCode);
            self::assertSame('secrets.envelope', $e->field);
        }
    }

    public function testEnvelopeSizeIsRejectedBeforeCiphertextIsRead(): void
    {
        $payload = $this->payload(withSecretEnvelope: true);
        $payload['secrets/tenant.sealed'] .= "\0";
        $archive = $this->archive($payload);

        $this->expectArchiveError(
            'secret_envelope_size_mismatch',
            'secrets/tenant.sealed',
        );
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testManifestInventoryDigestRejectsChangedChecksummedPayload(): void
    {
        $payload = $this->payload();
        $payload['data/table-supplier.jsonl'] = "{\"id\":2}\n";
        $archive = $this->archive($payload);

        $this->expectArchiveError(
            'data_entry_checksum_mismatch',
            'data/table-supplier.jsonl',
        );
        $this->inspector()->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
    }

    public function testCompatibilityGateRunsBeforeBusinessPayloadIsRead(): void
    {
        $payload = $this->payload(appVersion: '5.29.0');
        $checksums = str_replace(
            hash('sha256', $payload['data/table-supplier.jsonl']),
            str_repeat('f', 64),
            $this->checksums($payload),
        );
        $archive = $this->archive($payload, checksums: $checksums);

        try {
            $this->inspector()->inspect(
                $archive,
                self::PASSWORD,
                '5.28.1',
                CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            );
            self::fail('Novější zdroj musí zastavit inspekci před business payloadem.');
        } catch (CompanyBackupArchiveCompatibilityException $e) {
            self::assertSame(
                ['source_application_newer'],
                array_column($e->compatibility->toArray()['issues'], 'code'),
            );
        }
    }

    public function testCompatibilityGateRunsBeforeFullRegistryBodyIsParsed(): void
    {
        $archive = $this->archive($this->payload(
            appVersion: '5.29.0',
            includeRegistry: false,
        ));

        try {
            $this->inspector()->inspect(
                $archive,
                self::PASSWORD,
                '5.28.1',
                CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            );
            self::fail('Novější zdroj musí skončit dřív než neúplné tělo manifestu.');
        } catch (CompanyBackupArchiveCompatibilityException $e) {
            self::assertSame(
                ['source_application_newer'],
                array_column($e->compatibility->toArray()['issues'], 'code'),
            );
        }
    }

    private function inspector(?CompanyBackupArchiveLimits $limits = null): CompanyBackupArchiveInspector
    {
        return new CompanyBackupArchiveInspector(
            new CompanyBackupFormat([
                CompanyBackupSecretEnvelopeDescriptor::CAPABILITY,
            ]),
            BackupUpcasterRegistry::empty(),
            $limits ?? new CompanyBackupArchiveLimits(
                maxArchiveBytes: 1_000_000,
                maxEntries: 20,
                maxEntryBytes: 20_000,
                maxExpandedBytes: 40_000,
                maxCompressionRatio: 1_000,
                maxManifestBytes: 4_096,
                maxChecksumsBytes: 4_096,
            ),
        );
    }

    /** @return array<string,string> */
    private function payload(
        string $appVersion = '5.28.1',
        bool $includeRegistry = true,
        bool $protectedSecret = false,
        bool $withSecretEnvelope = false,
        bool $invalidSecretPayload = false,
    ): array
    {
        $format = new CompanyBackupFormat();
        $protectedSecret = $protectedSecret || $withSecretEnvelope;
        $registry = new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:supplier',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' => ['strategy' => 'selected_supplier', 'column' => 'id'],
                    ...($protectedSecret ? [
                        'secrets' => [
                            'domain_salt' => [
                                'policy' =>
                                    TenantSecretPolicy::ProtectedDomainSecret->value,
                                'storage' => 'raw',
                            ],
                        ],
                    ] : []),
                ],
            )],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
        $manifest = [
            'product' => CompanyBackupFormat::PRODUCT,
            'format' => CompanyBackupFormat::FORMAT,
            'format_version' => ['major' => 1, 'minor' => 0],
            'backup_id' => '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            'source' => [
                'app_version' => $appVersion,
                'schema_revision' => CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            ],
            'capabilities' => [
                'required' => $withSecretEnvelope
                    ? [CompanyBackupSecretEnvelopeDescriptor::CAPABILITY]
                    : [],
                'optional' => [],
            ],
        ];
        if ($includeRegistry) {
            $manifest['registry'] = TenantDataRegistrySnapshot::fromRegistry(
                $registry,
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            )->toArray();
        }
        $supplier = "{\"id\":1}\n";
        $manifest['data'] = [
            'format' => CompanyBackupDataInventory::FORMAT,
            'version' => CompanyBackupDataInventory::VERSION,
            'objects' => [[
                'registry_key' => 'table:supplier',
                'path' => 'data/table-supplier.jsonl',
                'order' => 1,
                'rows' => 1,
                'bytes' => strlen($supplier),
                'sha256' => hash('sha256', $supplier),
            ]],
        ];
        $manifest['files'] = [
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [],
        ];
        $secrets = [
            'format' => CompanyBackupSecretInventory::FORMAT,
            'version' => CompanyBackupSecretInventory::VERSION,
            'omissions' => [],
        ];
        $payload = [
            'CTI-MNE.txt' => "Syntetická záloha MyÚčta.\n",
            'data/table-supplier.jsonl' => $supplier,
        ];
        if ($withSecretEnvelope) {
            $snapshot = TenantDataRegistrySnapshot::fromRegistry(
                $registry,
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            );
            $sealed = (new CompanyBackupSecretEnvelopeCipher())->seal(
                $invalidSecretPayload
                    ? '{"synthetic":true}'
                    : CompanyBackupSecretPayload::fromValues([], $snapshot)->toJson(),
                self::PASSWORD,
                '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
                $snapshot->fingerprint,
            );
            $secrets['envelope'] = $sealed->descriptor->toArray();
            $payload[$sealed->descriptor->path] = $sealed->ciphertext;
        }
        $manifest['secrets'] = $secrets;
        $payload['manifest.json'] = $format->encodeManifest($manifest);
        return $payload;
    }

    /**
     * @param array<string,string> $payload
     * @param list<string> $unencrypted
     * @param array<string,int> $unixModes
     */
    private function archive(
        array $payload,
        ?string $checksums = null,
        array $unencrypted = [],
        array $unixModes = [],
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'myucto-company-backup-');
        if ($path === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný ZIP pro test.');
        }
        $this->archives[] = $path;

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->setPassword(self::PASSWORD));
        $entries = $payload;
        $entries['CHECKSUMS.txt'] = $checksums ?? $this->checksums($payload);
        foreach ($entries as $name => $content) {
            self::assertTrue($zip->addFromString($name, $content));
            if (!in_array($name, $unencrypted, true)) {
                self::assertTrue($zip->setEncryptionName(
                    $name,
                    ZipArchive::EM_AES_256,
                    self::PASSWORD,
                ));
            }
            if (isset($unixModes[$name])) {
                self::assertTrue($zip->setExternalAttributesName(
                    $name,
                    ZipArchive::OPSYS_UNIX,
                    $unixModes[$name] << 16,
                ));
            }
        }
        self::assertTrue($zip->close());
        return $path;
    }

    /** @param array<string,string> $payload */
    private function checksums(array $payload): string
    {
        ksort($payload, SORT_STRING);
        $lines = [];
        foreach ($payload as $path => $content) {
            $lines[] = hash('sha256', $content) . '  ' . $path;
        }
        return implode("\n", $lines) . "\n";
    }

    private function expectArchiveError(string $errorCode, ?string $entry = null): void
    {
        $this->expectException(CompanyBackupArchiveException::class);
        $this->expectExceptionMessage($errorCode);
        if ($entry !== null) {
            $this->expectExceptionMessage($entry);
        }
    }
}
