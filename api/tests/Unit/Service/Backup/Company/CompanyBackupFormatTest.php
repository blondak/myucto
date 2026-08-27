<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupFormatException;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcaster;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use PHPUnit\Framework\TestCase;

final class CompanyBackupFormatTest extends TestCase
{
    public function testManifestHasStableCanonicalJsonAndHash(): void
    {
        $format = new CompanyBackupFormat(['chunked-files.v1', 'secret-envelope.v1']);
        $manifest = $this->manifest(
            requiredCapabilities: ['secret-envelope.v1', 'chunked-files.v1'],
            optionalCapabilities: ['readable-index.v1'],
        );
        $manifest['diagnostics'] = [
            'registry_hash' => 'sha256:' . str_repeat('a', 64),
            'registry_version' => 1,
        ];

        $encoded = $format->encodeManifest($manifest);
        $header = $format->parseManifestHeader($encoded);

        self::assertSame(['chunked-files.v1', 'secret-envelope.v1'], $header->requiredCapabilities);
        self::assertSame(['readable-index.v1'], $header->optionalCapabilities);
        self::assertSame($encoded, $header->canonicalJson());
        self::assertSame(hash('sha256', $encoded), $header->sha256());

        $reordered = [
            'source' => $manifest['source'],
            'diagnostics' => [
                'registry_version' => 1,
                'registry_hash' => 'sha256:' . str_repeat('a', 64),
            ],
            'capabilities' => [
                'optional' => ['readable-index.v1'],
                'required' => ['chunked-files.v1', 'secret-envelope.v1'],
            ],
            'backup_id' => $manifest['backup_id'],
            'format_version' => $manifest['format_version'],
            'format' => $manifest['format'],
            'product' => $manifest['product'],
        ];
        self::assertSame($encoded, $format->encodeManifest($reordered));
    }

    public function testLegacyInstanceExportIsRejectedWithDedicatedReason(): void
    {
        $json = json_encode([
            'format' => 'myucto-instance-export',
            'version' => 5,
        ], JSON_THROW_ON_ERROR);

        try {
            (new CompanyBackupFormat())->parseManifestHeader($json);
            self::fail('Legacy archiv nesmí projít parserem nového formátu.');
        } catch (CompanyBackupFormatException $e) {
            self::assertSame('legacy_format_requires_adapter', $e->errorCode);
            self::assertSame('format', $e->field);
        }
    }

    public function testParserRejectsNonCanonicalManifestBytes(): void
    {
        $prettyJson = json_encode($this->manifest(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        try {
            (new CompanyBackupFormat())->parseManifestHeader($prettyJson);
            self::fail('Parser nesmí připustit více bajtových podob stejného manifestu.');
        } catch (CompanyBackupFormatException $e) {
            self::assertSame('manifest_not_canonical', $e->errorCode);
        }
    }

    public function testUnsupportedFormatMajorIsRejected(): void
    {
        $format = new CompanyBackupFormat();
        $manifest = $this->manifest();
        $manifest['format_version']['major'] = 2;
        $header = $format->parseManifestHeader($format->encodeManifest($manifest));

        $result = $format->checkCompatibility(
            $header,
            '5.28.1',
            'company-backup.schema.v1',
            BackupUpcasterRegistry::empty(),
        );

        self::assertFalse($result->isCompatible());
        self::assertSame(['format_major_unsupported'], array_column($result->toArray()['issues'], 'code'));
    }

    public function testHigherMinorIsAcceptedWhenEveryRequiredCapabilityIsKnown(): void
    {
        $format = new CompanyBackupFormat(['chunked-files.v1']);
        $header = $format->parseManifestHeader($format->encodeManifest($this->manifest(
            minor: 9,
            requiredCapabilities: ['chunked-files.v1'],
            optionalCapabilities: ['future-readable-report.v3'],
        )));

        $result = $format->checkCompatibility(
            $header,
            '5.28.1',
            'company-backup.schema.v1',
            BackupUpcasterRegistry::empty(),
        );

        self::assertTrue($result->isCompatible());
        self::assertSame([], $result->issues);
        self::assertSame([], $result->upcasterIds);
    }

    public function testUnknownRequiredCapabilityBlocksRestoreButUnknownOptionalOneDoesNot(): void
    {
        $format = new CompanyBackupFormat(['chunked-files.v1']);
        $header = $format->parseManifestHeader($format->encodeManifest($this->manifest(
            minor: 9,
            requiredCapabilities: ['chunked-files.v1', 'future-secrets.v2'],
            optionalCapabilities: ['future-readable-report.v3'],
        )));

        $result = $format->checkCompatibility(
            $header,
            '5.28.1',
            'company-backup.schema.v1',
            BackupUpcasterRegistry::empty(),
        );

        self::assertFalse($result->isCompatible());
        self::assertSame(
            ['required_capability_unsupported'],
            array_column($result->toArray()['issues'], 'code'),
        );
        self::assertSame('future-secrets.v2', $result->toArray()['issues'][0]['value']);
    }

    public function testNewerSourceApplicationStopsBeforeSchemaPlanning(): void
    {
        $format = new CompanyBackupFormat();
        $header = $format->parseManifestHeader($format->encodeManifest($this->manifest(
            appVersion: '5.29.0',
            schemaRevision: 'company-backup.schema.v9',
        )));

        $result = $format->checkCompatibility(
            $header,
            '5.28.1',
            'company-backup.schema.v1',
            BackupUpcasterRegistry::empty(),
        );

        self::assertFalse($result->isCompatible());
        self::assertSame(['source_application_newer'], array_column($result->toArray()['issues'], 'code'));
        self::assertSame([], $result->upcasterIds, 'Po blokující verzi se schema cesta ani neplánuje.');
    }

    public function testApplicationDirectionUsesSemverPrereleaseOrdering(): void
    {
        $format = new CompanyBackupFormat();
        $header = $format->parseManifestHeader($format->encodeManifest($this->manifest(
            appVersion: '5.29.0-foo',
        )));

        $result = $format->checkCompatibility(
            $header,
            '5.29.0-bar',
            'company-backup.schema.v1',
            BackupUpcasterRegistry::empty(),
        );

        self::assertFalse($result->isCompatible(), 'SemVer porovnává neznámé prerelease identifikátory lexikálně.');
        self::assertSame(['source_application_newer'], array_column($result->toArray()['issues'], 'code'));
    }

    public function testCompatibilityReturnsCompleteLosslessUpcasterChain(): void
    {
        $format = new CompanyBackupFormat();
        $header = $format->parseManifestHeader($format->encodeManifest($this->manifest(
            appVersion: '5.26.0',
            schemaRevision: 'company-backup.schema.v1',
        )));
        $registry = BackupUpcasterRegistry::fromUpcasters([
            new FormatTestUpcaster('schema-v2-v3', 'company-backup.schema.v2', 'company-backup.schema.v3'),
            new FormatTestUpcaster('schema-v1-v2', 'company-backup.schema.v1', 'company-backup.schema.v2'),
        ]);

        $result = $format->checkCompatibility(
            $header,
            '5.28.1',
            'company-backup.schema.v3',
            $registry,
        );

        self::assertTrue($result->isCompatible());
        self::assertSame(['schema-v1-v2', 'schema-v2-v3'], $result->upcasterIds);
    }

    public function testLossyUpcasterIsNeverAcceptedImplicitly(): void
    {
        $format = new CompanyBackupFormat();
        $header = $format->parseManifestHeader($format->encodeManifest($this->manifest(
            appVersion: '5.26.0',
            schemaRevision: 'company-backup.schema.v1',
        )));
        $registry = BackupUpcasterRegistry::fromUpcasters([
            new FormatTestUpcaster(
                'schema-v1-v2-lossy',
                'company-backup.schema.v1',
                'company-backup.schema.v2',
                false,
            ),
        ]);

        $result = $format->checkCompatibility(
            $header,
            '5.28.1',
            'company-backup.schema.v2',
            $registry,
        );

        self::assertFalse($result->isCompatible());
        self::assertSame(['schema_upcaster_lossy'], array_column($result->toArray()['issues'], 'code'));
    }

    /**
     * @param list<string> $requiredCapabilities
     * @param list<string> $optionalCapabilities
     * @return array<string,mixed>
     */
    private function manifest(
        int $minor = 0,
        string $appVersion = '5.28.1',
        string $schemaRevision = 'company-backup.schema.v1',
        array $requiredCapabilities = [],
        array $optionalCapabilities = [],
    ): array {
        return [
            'product' => 'myucto',
            'format' => 'myucto-company-backup',
            'format_version' => [
                'major' => 1,
                'minor' => $minor,
            ],
            'backup_id' => '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            'source' => [
                'app_version' => $appVersion,
                'schema_revision' => $schemaRevision,
            ],
            'capabilities' => [
                'required' => $requiredCapabilities,
                'optional' => $optionalCapabilities,
            ],
        ];
    }
}

final readonly class FormatTestUpcaster implements BackupUpcaster
{
    public function __construct(
        private string $upcasterId,
        private string $source,
        private string $target,
        private bool $lossless = true,
    ) {}

    public function id(): string
    {
        return $this->upcasterId;
    }

    public function sourceRevision(): string
    {
        return $this->source;
    }

    public function targetRevision(): string
    {
        return $this->target;
    }

    public function isLossless(): bool
    {
        return $this->lossless;
    }

    public function warnings(): array
    {
        return [];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function upcastManifest(array $manifest): array
    {
        $manifest['source']['schema_revision'] = $this->target;
        return $manifest;
    }

    public function upcastRows(string $logicalObject, iterable $rows): iterable
    {
        return $rows;
    }
}
