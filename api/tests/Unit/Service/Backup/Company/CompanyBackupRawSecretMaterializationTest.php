<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company as Backup;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupRawSecretMaterializationTest extends TestCase
{
    public function testPreservesBytesAndHandlesNullableSecretWithoutGeneratingReplacement(): void
    {
        $set = $this->set();
        $binary = hex2bin(str_repeat('00ff', 16));
        $value = Backup\CompanyBackupSecretValue::fromPlaintext('table:supplier',
            Backup\CompanyBackupSecretScope::Column, 'ai_pseudo_salt', ['id' => 7], $binary);
        self::assertSame(['ai_pseudo_salt' => $binary], $set->materializeForColumn(
            'ai_pseudo_salt', $value, ['id' => 7], ['id' => 71], $this->sensitiveData()));
        self::assertSame(['ai_pseudo_salt' => null], $set->materializeForColumn(
            'ai_pseudo_salt', null, ['id' => 7], ['id' => 71], $this->sensitiveData()));
    }

    #[DataProvider('invalidValues')]
    public function testRejectsWrongLengthIdentityAndUnmappedTarget(string $case): void
    {
        $bytes = match ($case) { 'short' => 31, 'long' => 33, default => 32 };
        $value = Backup\CompanyBackupSecretValue::fromPlaintext(
            $case === 'table' ? 'table:another' : 'table:supplier',
            Backup\CompanyBackupSecretScope::Column, 'ai_pseudo_salt',
            ['id' => $case === 'row' ? 8 : 7], str_repeat('s', $bytes));
        $this->expectException(Backup\CompanyBackupDataSourceException::class);
        $this->set()->materializeForColumn('ai_pseudo_salt', $value, ['id' => 7],
            ['id' => $case === 'target' ? 0 : 71], $this->sensitiveData());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidValues(): iterable
    {
        foreach (['short', 'long', 'table', 'row', 'target'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidPolicies')]
    public function testCannotTreatCredentialOrEncryptedValueAsRawKey(string $storage, TenantSecretPolicy $policy): void
    {
        $this->expectException(Backup\CompanyBackupDataSourceException::class);
        $this->set($storage, $policy);
    }

    /** @return iterable<string,array{string,TenantSecretPolicy}> */
    public static function invalidPolicies(): iterable
    {
        yield 'encrypted domain secret' => ['application_encrypted', TenantSecretPolicy::ProtectedDomainSecret];
        yield 'optional credential' => ['raw', TenantSecretPolicy::OptionalCredential];
    }

    #[DataProvider('payloadDirections')]
    public function testRejectsInvalidKeyBeforePublishingOrImportingEnvelope(bool $reading): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:supplier');
        self::assertNotNull($definition);
        $registry = TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(1, [$definition],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE]), TenantDataRegistry::COMPANY_BACKUP_PROFILE);
        $value = Backup\CompanyBackupSecretValue::fromPlaintext('table:supplier',
            Backup\CompanyBackupSecretScope::Column, 'ai_pseudo_salt', ['id' => 7], str_repeat('s', $reading ? 32 : 31));
        if ($reading) {
            $payload = Backup\CompanyBackupSecretPayload::fromValues([$value], $registry)->toArray();
            $payload['declarations'][0]['values'][0]['value_base64'] = base64_encode(str_repeat('s', 31));
        }
        $this->expectException(Backup\CompanyBackupSecretPayloadException::class);
        $this->expectExceptionMessage('secret_payload_value_invalid');
        if ($reading) {
            Backup\CompanyBackupSecretPayload::fromArray($payload, $registry);
        } else {
            Backup\CompanyBackupSecretPayload::fromValues([$value], $registry);
        }
    }

    /** @return iterable<string,array{bool}> */
    public static function payloadDirections(): iterable
    {
        yield 'export' => [false];
        yield 'import' => [true];
    }

    private function set(string $storage = 'raw', TenantSecretPolicy $policy = TenantSecretPolicy::ProtectedDomainSecret): Backup\CompanyBackupProtectedSecretMaterializationSet
    {
        return Backup\CompanyBackupProtectedSecretMaterializationSet::fromArray([[
            'materializer' => 'raw_bytes_v1', 'secret_column' => 'ai_pseudo_salt',
            'tenant_id_column' => 'id', 'nullable' => true, 'bytes' => 32,
        ]], 'table:supplier', ['id'], ['id'], ['strategy' => 'selected_supplier', 'column' => 'id'], [],
            ['ai_pseudo_salt' => $policy], ['ai_pseudo_salt' => ['policy' => $policy->value, 'storage' => $storage]]);
    }

    private function sensitiveData(): PayrollSensitiveData
    {
        $config = new Config(['app' => [
            'secret_encryption_key' => base64_encode(str_repeat('s', 32)),
            'payroll_hash_key' => base64_encode(str_repeat('h', 32)),
        ]]);
        return new PayrollSensitiveData(new SecretEncryption($config), $config);
    }
}
