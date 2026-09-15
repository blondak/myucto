<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupJsonlReader;
use MyInvoice\Service\Backup\Company\CompanyBackupDataWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupJsonlWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupPreparedImportRow;
use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretRestoreMaterializer;
use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupRawSecretMaterialization;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCipher;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayloadException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinksProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkProtectedTransportTest extends TestCase
{
    private const PASSWORD = 'synthetic-backup-password-42';
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const TOKEN = '00000000abababababababababababababababababababab';

    public function testEncryptedEnvelopeRestoresExactTokenUnderNewSupplierAndKeepsHistoryInJsonl(): void
    {
        $definition = self::definition();
        $registry = self::registry($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        self::assertSame(CompanyBackupWorkReportLinksProjection::dataColumns(), $projection->dataColumns);
        self::assertSame('token', $projection->requiredSecretEnvelopeColumn());
        self::assertSame('token<-raw_bytes_v1:48@supplier_id',
            $projection->protectedSecretMaterializations->materializations[0]->signature());

        $sourceRow = self::row();
        CompanyBackupWorkReportLinksProjection::validateRow($sourceRow);
        $projection->assertCompleteSourceRow($sourceRow);
        $jsonlPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'work-report-links-' . bin2hex(random_bytes(8)) . '.jsonl';
        try {
            $object = (new CompanyBackupJsonlWriter())->write($definition, 1, [$sourceRow], $jsonlPath);
            $jsonl = file_get_contents($jsonlPath);
            self::assertIsString($jsonl);
            $stream = fopen($jsonlPath, 'rb');
            self::assertIsResource($stream);
            try {
                $decodedRows = iterator_to_array((new CompanyBackupJsonlReader())->rows(
                    $stream, $definition, $object,
                ));
            } finally {
                fclose($stream);
            }
        } finally {
            if (is_file($jsonlPath)) {
                unlink($jsonlPath);
            }
        }
        self::assertStringNotContainsString(self::TOKEN, $jsonl);
        self::assertStringNotContainsString('"token"', $jsonl);
        self::assertCount(1, $decodedRows);
        $sourceRow = $decodedRows[1];
        self::assertSame('project', $sourceRow['scope']);
        self::assertSame('2025-12-31 23:59:00', $sourceRow['created_at']);
        self::assertSame('2026-01-01 00:01:00', $sourceRow['last_sent_at']);
        self::assertSame('2026-01-02 01:02:03', $sourceRow['last_viewed_at']);
        self::assertSame('2026-01-03 04:05:06', $sourceRow['revoked_at']);

        $source = new class implements CompanyBackupProtectedSecretSource {
            /** @return iterable<CompanyBackupSecretValue> */
            public function values(PDO $snapshot, int $supplierId, CompanyBackupProtectedSecretProjection $projection): iterable
            {
                CompanyBackupWorkReportLinkProtectedTransportTest::assertSame(2, $supplierId);
                CompanyBackupWorkReportLinkProtectedTransportTest::assertSame(['token'], $projection->columns);
                yield CompanyBackupWorkReportLinkProtectedTransportTest::tokenValue();
            }
        };
        $sealed = (new CompanyBackupSecretEnvelopeCollector($source))->collect(
            $this->createStub(PDO::class), $registry, 2, self::PASSWORD, self::BACKUP_ID,
        );
        self::assertStringNotContainsString(self::TOKEN, $sealed->ciphertext);
        $plaintext = (new CompanyBackupSecretEnvelopeCipher())->open(
            $sealed, self::PASSWORD, self::BACKUP_ID, $registry->fingerprint,
        );
        $payload = CompanyBackupSecretPayload::fromJson($plaintext, $registry);
        self::assertSame(self::TOKEN, $payload->values()[0]->plaintext());

        $targetRow = $sourceRow;
        $targetRow['id'] = 107;
        $targetRow['supplier_id'] = 12;
        $targetRow['client_id'] = 111;
        $targetRow['project_id'] = 113;
        $identity = CompanyBackupSourceIdentityProjection::fromDefinition($definition);
        $prepared = new CompanyBackupPreparedImportRow(
            $targetRow, $identity->identityForRow($sourceRow), $identity->identityForRow($targetRow),
        );
        $materializer = new CompanyBackupProtectedSecretRestoreMaterializer(
            $payload, $registry, self::sensitiveData(),
        );
        self::assertSame(['token' => self::TOKEN],
            $materializer->valuesFor($definition, $sourceRow, $prepared));
        $materializer->finish();
        self::assertSame('2026-01-03 04:05:06', $targetRow['revoked_at']);
        self::assertSame('2026-01-01 00:01:00', $targetRow['last_sent_at']);
        self::assertSame('2026-01-02 01:02:03', $targetRow['last_viewed_at']);
    }

    public function testRequiredMissingTokenCannotMaterialize(): void
    {
        $definition = self::definition();
        $registry = self::registry($definition);
        $payload = CompanyBackupSecretPayload::fromValues([], $registry);
        $row = self::row();
        $identity = CompanyBackupSourceIdentityProjection::fromDefinition($definition)->identityForRow($row);
        $materializer = new CompanyBackupProtectedSecretRestoreMaterializer(
            $payload, $registry, self::sensitiveData(),
        );
        $this->expectException(CompanyBackupImportWriteException::class);
        $this->expectExceptionMessage('import_protected_secret_materialization_failed');
        $materializer->valuesFor($definition, $row, new CompanyBackupPreparedImportRow($row, $identity, $identity));
    }

    public function testInjectedPlaintextTokenIsRejectedByJsonlWriterWithoutLeavingFile(): void
    {
        $definition = self::definition();
        $injected = self::row();
        $injected['token'] = self::TOKEN;
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'injected-work-report-link-' . bin2hex(random_bytes(8)) . '.jsonl';
        try {
            try {
                (new CompanyBackupJsonlWriter())->write($definition, 1, [$injected], $path);
                self::fail('Export nesmí zapsat token do běžného JSONL.');
            } catch (CompanyBackupDataWriteException $e) {
                self::assertSame('data_row_invalid', $e->errorCode);
                self::assertSame('table:work_report_links', $e->registryKey);
            }
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testWrongLengthAndMalformedFortyEightByteTokensCannotEnterPayload(): void
    {
        $registry = self::registry(self::definition());
        foreach ([str_repeat('a', 47), str_repeat('A', 48), str_repeat('g', 48)] as $token) {
            $value = CompanyBackupSecretValue::fromPlaintext(
                'table:work_report_links', CompanyBackupSecretScope::Column,
                'token', ['id' => 7], $token,
            );
            try {
                CompanyBackupSecretPayload::fromValues([$value], $registry);
                self::fail('Neplatný token nesmí být zapečetěn.');
            } catch (CompanyBackupSecretPayloadException $e) {
                self::assertSame('secret_payload_value_invalid', $e->errorCode);
                self::assertStringNotContainsString($token, $e->getMessage());
            }
        }
    }

    public function testMalformedTokenCannotBeReadFromExistingEnvelopePayload(): void
    {
        $registry = self::registry(self::definition());
        $payload = CompanyBackupSecretPayload::fromValues([self::tokenValue()], $registry)->toArray();
        $payload['declarations'][0]['values'][0]['value_base64'] = base64_encode(str_repeat('A', 48));
        $this->expectException(CompanyBackupSecretPayloadException::class);
        $this->expectExceptionMessage('secret_payload_value_invalid');
        CompanyBackupSecretPayload::fromArray($payload, $registry);
    }

    public function testCollectorRejectsMalformedSourceTokenBeforeSealing(): void
    {
        $source = new class implements CompanyBackupProtectedSecretSource {
            /** @return iterable<CompanyBackupSecretValue> */
            public function values(PDO $snapshot, int $supplierId, CompanyBackupProtectedSecretProjection $projection): iterable
            {
                yield CompanyBackupSecretValue::fromPlaintext(
                    'table:work_report_links', CompanyBackupSecretScope::Column,
                    'token', ['id' => 7], str_repeat('A', 48),
                );
            }
        };
        $this->expectException(CompanyBackupSecretPayloadException::class);
        $this->expectExceptionMessage('secret_payload_value_invalid');
        (new CompanyBackupSecretEnvelopeCollector($source))->collect(
            $this->createStub(PDO::class), self::registry(self::definition()),
            2, self::PASSWORD, self::BACKUP_ID,
        );
    }

    public function testMalformedTokenCannotMaterializeButUnrelatedRawSecretCan(): void
    {
        $value = CompanyBackupSecretValue::fromPlaintext('table:work_report_links',
            CompanyBackupSecretScope::Column, 'token', ['id' => 7], str_repeat('A', 48));
        $contract = CompanyBackupRawSecretMaterialization::fromArray(
            CompanyBackupWorkReportLinksProjection::protectedSecretMaterializations()[0],
            'table:work_report_links',
        );
        try {
            $contract->materialize($value, ['supplier_id' => 12]);
            self::fail('Neplatný token nesmí být uložen.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_restore_value_invalid', $e->errorCode);
        }
        $unrelated = CompanyBackupRawSecretMaterialization::fromArray([
            'materializer' => 'raw_bytes_v1', 'secret_column' => 'salt',
            'tenant_id_column' => 'supplier_id', 'nullable' => false, 'bytes' => 48,
        ], 'table:unrelated');
        self::assertSame(['salt' => str_repeat('A', 48)], $unrelated->materialize(
            CompanyBackupSecretValue::fromPlaintext('table:unrelated',
                CompanyBackupSecretScope::Column, 'salt', ['id' => 7], str_repeat('A', 48)),
            ['supplier_id' => 12],
        ));
    }

    public static function tokenValue(): CompanyBackupSecretValue
    {
        return CompanyBackupSecretValue::fromPlaintext('table:work_report_links',
            CompanyBackupSecretScope::Column, 'token', ['id' => 7], self::TOKEN);
    }

    /** @return array<string,int|string|null> */
    private static function row(): array
    {
        return [
            'id' => 7, 'supplier_id' => 2, 'scope' => 'project',
            'client_id' => 11, 'project_id' => 13,
            'created_by_user_id' => null,
            'created_at' => '2025-12-31 23:59:00',
            'last_sent_at' => '2026-01-01 00:01:00',
            'last_viewed_at' => '2026-01-02 01:02:03',
            'revoked_at' => '2026-01-03 04:05:06',
        ];
    }

    private static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition('table:work_report_links', TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned, [TenantDataRegistry::COMPANY_BACKUP_PROFILE], [
                'primary_key' => ['id'],
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => CompanyBackupWorkReportLinksProjection::secrets(),
                'company_backup' => [
                    'data_columns' => CompanyBackupWorkReportLinksProjection::dataColumns(),
                    'references' => CompanyBackupWorkReportLinksProjection::references(),
                    'protected_secret_materializations' =>
                        CompanyBackupWorkReportLinksProjection::protectedSecretMaterializations(),
                    'embedded_references' => [], 'generated_columns' => [],
                    'omit_columns' => [], 'restore_overrides' => [],
                ],
            ]);
    }

    private static function registry(TenantDataDefinition $definition): TenantDataRegistrySnapshot
    {
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(1, [$definition],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE]), TenantDataRegistry::COMPANY_BACKUP_PROFILE);
    }

    private static function sensitiveData(): PayrollSensitiveData
    {
        $config = new Config(['app' => [
            'secret_encryption_key' => base64_encode(str_repeat('s', 32)),
            'payroll_hash_key' => base64_encode(str_repeat('h', 32)),
        ]]);
        return new PayrollSensitiveData(new SecretEncryption($config), $config);
    }
}
