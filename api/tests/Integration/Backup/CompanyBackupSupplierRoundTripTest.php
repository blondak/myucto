<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company as Backup;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Produkční projekce kořene a skutečné NOT NULL FK, nikoli náhradní supplier. */
#[Group('integration')]
final class CompanyBackupSupplierRoundTripTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const PASSWORD = 'synthetic-supplier-roundtrip-password';
    private const APP_VERSION = '5.28.1';

    private ?Connection $connection = null;
    private string $directory = '';

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class) || !defined('ZipArchive::EM_AES_256')) {
            self::markTestSkipped('Test vyžaduje ZIP s AES-256.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $config = $container->get(Config::class);
            $this->connection = Connection::withoutSharedTestConnection(static fn () => new Connection($config));
            $pdo = $this->connection->pdo();
        } catch (\Throwable $e) {
            $this->connection = null;
            self::markTestSkipped('Testovací MariaDB není dostupná: ' . $e->getMessage());
        }
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-supplier-roundtrip-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->connection->pdo()->inTransaction()) {
                $this->connection->pdo()->rollBack();
            }
            $this->connection->close();
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            foreach (new \DirectoryIterator($this->directory) as $entry) {
                if (!$entry->isDot()) {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->directory);
        }
    }

    #[DataProvider('salts')]
    public function testEncryptedArchiveRestoresRealSupplierGraphAndKeepsSourceUnchanged(bool $withSalt): void
    {
        $pdo = $this->connection->pdo();
        self::assertSame('19-1000000005/0100',
            (new \MyInvoice\Service\Payment\CzechBankAccountValidator())->normalize('19-1000000005 / 0100'));
        $registry = $this->registry();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $existingCurrency = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $vat, $existingCurrency, $actor), 'Syntetická DB musí mít základní číselníky.');
        $salt = $withSalt ? hex2bin(str_repeat('00ff', 16)) : null;
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, email,
            country_id, default_vat_rate_id, default_currency_id, default_prices_include_vat,
            auto_send_reminders, auto_generate_recurring, ai_assist_enabled, ai_pseudo_salt)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1, ?, ?)')->execute([
                'Synthetic backup supplier', 'Testovací 1', 'Praha', '11000', 'supplier@example.test',
                $country, $vat, $existingCurrency, $withSalt ? 1 : 0, $salt,
            ]);
        $supplier = (int) $pdo->lastInsertId();
        $hostname = 'synthetic-' . bin2hex(random_bytes(8)) . '.example.test';
        $pdo->prepare("INSERT INTO supplier_domains (supplier_id, hostname, purpose, status,
            is_primary_portal, is_primary_public, verification_token, created_by)
            VALUES (?, ?, 'all', 'active', 1, 1, ?, ?)")
            ->execute([$supplier, $hostname, str_repeat('d', 64), $actor]);
        $domainId = (int) $pdo->lastInsertId();
        $domainBefore = $pdo->query('SELECT * FROM supplier_domains WHERE id = ' . $domainId)->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en,
            account_number, bank_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $supplier, 'CZK', 'Synthetic account', 'Kč', 'Synthetic', 'Synthetic', '19-1000000005', '0100',
            ]);
        $currency = (int) $pdo->lastInsertId();
        $statementHash = hash('sha256', 'synthetic-legacy-' . bin2hex(random_bytes(8)));
        $pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash,
            account_number, bank_code, currency, statement_number, statement_date,
            prev_balance, curr_balance, transaction_count)
            VALUES (NULL, 'gpc', 'synthetic.gpc', ?, '19-1000000005', '0100', 'CZK', 'SYN-1', '2026-01-01', 0, 0, 0)")
            ->execute([$statementHash]);
        $legacyStatementId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO branding_profiles (supplier_id, name) VALUES (?, ?)')
            ->execute([$supplier, 'Synthetic branding']);
        $branding = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ?, default_branding_profile_id = ?,
            updated_at = ? WHERE id = ?')->execute([$currency, $branding, '2021-01-01 12:00:00', $supplier]);
        $before = $this->supplierRow($pdo, $supplier);
        $supplierCount = (int) $pdo->query('SELECT COUNT(*) FROM supplier')->fetchColumn();
        $archive = $this->archive($pdo, $registry, $supplier);
        $inspection = (new Backup\CompanyBackupArchiveInspector(
            new Backup\CompanyBackupFormat([Backup\CompanyBackupSecretEnvelopeDescriptor::CAPABILITY]),
            BackupUpcasterRegistry::empty(),
        ))->inspect($archive, self::PASSWORD, self::APP_VERSION, Backup\CompanyBackupFormat::CURRENT_SCHEMA_REVISION);
        $validation = new Backup\CompanyBackupTechnicalValidation($inspection, $registry,
            self::APP_VERSION, Backup\CompanyBackupFormat::CURRENT_SCHEMA_REVISION);
        $preflight = (new Backup\CompanyBackupDataPreflight())->inspect($archive, self::PASSWORD, $validation, $pdo);
        self::assertSame(7, $preflight->rowCount);
        self::assertTrue($preflight->bankAccountCollision);
        self::assertSame([Backup\CompanyBackupBankWarning::collision()], $preflight->toArray()['warnings']);
        // Simulace jiného cíle: pouze lookup čítače vlastních účtů je prázdný.
        // Zdrojové strojové JSONL i technická validace zůstávají identické.
        $pdo->exec('CREATE TEMPORARY TABLE currencies (supplier_id INT, account_number VARCHAR(30), iban VARCHAR(34))');
        try {
            $cleanPreflight = (new Backup\CompanyBackupDataPreflight())->inspect($archive, self::PASSWORD, $validation, $pdo);
            self::assertFalse($cleanPreflight->bankAccountCollision);
            self::assertSame([], $cleanPreflight->toArray()['warnings']);
            self::assertNotSame($preflight->bindingSha256, $cleanPreflight->bindingSha256);
        } finally {
            $pdo->exec('DROP TEMPORARY TABLE currencies');
        }
        self::assertCount(2, $preflight->externalReferences->requirements);
        $choices = [];
        foreach ($preflight->externalReferences->requirements as $requirement) {
            self::assertSame(Backup\CompanyBackupReferenceMapping::GlobalNaturalKey, $requirement->mapping);
            $choices[] = [
                'requirement_id' => $requirement->id, 'mapping' => $requirement->mapping->value,
                'target_registry_key' => $requirement->targetRegistryKey, 'action' => 'map_existing',
                'target_primary_key' => ['id' => match ($requirement->targetRegistryKey) {
                    'table:countries' => $country, 'table:vat_rates' => $vat,
                }],
            ];
        }
        $decisions = Backup\CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => Backup\CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => Backup\CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256, 'decisions' => $choices,
        ], $preflight, $registry, self::INSTANCE_ID, $actor);
        $config = new Config(['app' => [
            'secret_encryption_key' => base64_encode(str_repeat('s', 32)),
            'payroll_hash_key' => base64_encode(str_repeat('h', 32)),
        ]]);
        $source = new Backup\CompanyBackupImportArchiveSource($archive, self::PASSWORD, $validation);
        try {
            $result = (new Backup\CompanyBackupDatabaseImporter($pdo))->restore($source, $preflight,
                $decisions, new PayrollSensitiveData(new SecretEncryption($config), $config));
        } finally {
            $source->close();
        }
        self::assertTrue($pdo->inTransaction(), 'Commit patří až koordinátoru, ne importéru.');
        self::assertNotSame($supplier, $result->supplierId);
        self::assertSame(4, $result->insertedRows);
        self::assertSame(2, $result->mappedGlobalRows);
        self::assertNotNull($result->manualConfiguration);
        self::assertSame([['hostname' => $hostname, 'purpose' => 'all',
            'is_primary_portal' => true, 'is_primary_public' => true]], $result->manualConfiguration->domains);
        self::assertSame(2, $result->manualConfiguration->sourceKeyCount);
        self::assertCount(3, $result->manualConfiguration->toArray()['required_actions']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM supplier_domains WHERE supplier_id = '
            . $result->supplierId)->fetchColumn());
        self::assertSame($domainBefore,
            $pdo->query('SELECT * FROM supplier_domains WHERE id = ' . $domainId)->fetch(PDO::FETCH_ASSOC));
        $postImport = (new Backup\CompanyBackupRegistryPostImportValidator())->validate($pdo, $source, $preflight, $result);
        self::assertSame(4, $postImport->checkedTenantRows);
        self::assertSame($result->manualConfiguration->bindingSha256(), $postImport->manualConfigurationBindingSha256);
        $committedShape = new Backup\CompanyBackupRestoreResult($result, $postImport, 0);
        self::assertSame($result->manualConfiguration, $committedShape->database->manualConfiguration);
        self::assertSame($withSalt ? 1 : 0, $result->protectedSecretCount);
        self::assertSame($supplierCount + 1, (int) $pdo->query('SELECT COUNT(*) FROM supplier')->fetchColumn());
        $restored = $this->supplierRow($pdo, $result->supplierId);
        self::assertNotSame($currency, (int) $restored['default_currency_id']);
        self::assertNotSame($branding, (int) $restored['default_branding_profile_id']);
        self::assertSame($result->supplierId, (int) $pdo->query('SELECT supplier_id FROM currencies WHERE id = '
            . (int) $restored['default_currency_id'])->fetchColumn());
        self::assertSame($result->supplierId, (int) $pdo->query('SELECT supplier_id FROM branding_profiles WHERE id = '
            . (int) $restored['default_branding_profile_id'])->fetchColumn());
        self::assertSame($salt, $restored['ai_pseudo_salt']);
        self::assertSame(0, (int) $restored['auto_send_reminders']);
        self::assertSame(0, (int) $restored['auto_generate_recurring']);
        self::assertSame(0, (int) $restored['ai_assist_enabled']);
        self::assertSame(1, (int) $restored['default_prices_include_vat']);
        self::assertSame('2021-01-01 12:00:00', $restored['updated_at']);
        self::assertSame($before, $this->supplierRow($pdo, $supplier));
        self::assertNull($pdo->query('SELECT supplier_id FROM bank_statements WHERE id = ' . $legacyStatementId)->fetchColumn());
        $statementQuery = $pdo->prepare('SELECT supplier_id FROM bank_statements WHERE file_hash = ? AND supplier_id = ?');
        $statementQuery->execute([$statementHash, $result->supplierId]);
        self::assertSame($result->supplierId, (int) $statementQuery->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn());
        $pdo->prepare("INSERT INTO supplier_domains (supplier_id, hostname, verification_token)
            VALUES (?, ?, ?)")->execute([$result->supplierId, 'unexpected-' . $hostname, str_repeat('e', 64)]);
        try {
            (new Backup\CompanyBackupRegistryPostImportValidator())->validate($pdo, $source, $preflight, $result);
            self::fail('Ani neaktivní doménová vazba nesmí vzniknout při obnově.');
        } catch (Backup\CompanyBackupPostImportException $e) {
            self::assertSame('post_import_row_count_mismatch', $e->errorCode);
            self::assertSame('table:supplier_domains', $e->registryKey);
        }
        $pdo->rollBack();
        self::assertSame([], $this->supplierRow($pdo, $result->supplierId));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM currencies WHERE id = '
            . (int) $restored['default_currency_id'])->fetchColumn());
    }

    /** @return iterable<string,array{bool}> */
    public static function salts(): iterable
    {
        yield 'binary key' => [true];
        yield 'no key yet' => [false];
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $draft = TenantDataRegistryFactory::draftV1();
        $definitions = [];
        // Úplná uzavřená testovací podmnožina, produkční definice beze změn.
        foreach (['supplier', 'currencies', 'branding_profiles', 'email_profiles',
            'signing_profiles', 'countries', 'vat_rates', 'users', 'supplier_domains',
            'supplier_domain_login_requests', 'bank_statements'] as $table) {
            $definition = $draft->definition('table:' . $table);
            self::assertNotNull($definition);
            $definitions[] = $definition;
        }
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(1, $definitions,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE]), TenantDataRegistry::COMPANY_BACKUP_PROFILE);
    }

    private function archive(PDO $pdo, TenantDataRegistrySnapshot $registry, int $supplier): string
    {
        $objects = [];
        $files = [];
        $source = new Backup\CompanyBackupSqlRowSource();
        $writer = new Backup\CompanyBackupJsonlWriter();
        foreach (Backup\CompanyBackupDataInventory::payloadDefinitions($registry) as $index => $definition) {
            $path = $this->directory . DIRECTORY_SEPARATOR . 'data-' . $index . '.jsonl';
            $object = $writer->write($definition, $index + 1, $source->rows($pdo, $supplier, $definition), $path);
            $objects[] = $object;
            $files[$object->path] = $path;
        }
        $config = new Config(['app' => ['secret_encryption_key' => base64_encode(str_repeat('s', 32))]]);
        $protected = new Backup\CompanyBackupSqlProtectedSecretSource(new SecretEncryption($config));
        $values = iterator_to_array($protected->values($pdo, $supplier,
            Backup\CompanyBackupProtectedSecretProjection::fromDefinition($registry->registry->definition('table:supplier'))));
        $payload = Backup\CompanyBackupSecretPayload::fromValues($values, $registry);
        $envelope = (new Backup\CompanyBackupSecretEnvelopeCipher())->seal($payload->toJson(), self::PASSWORD,
            self::BACKUP_ID, $registry->fingerprint);
        $counts = [];
        foreach (Backup\CompanyBackupSecretInventory::requiredDeclarations($registry) as $declaration) {
            $counts[$declaration->signature()] = 0;
        }
        $snapshot = new Backup\CompanyBackupMachineSnapshot($supplier, self::BACKUP_ID, $registry,
            Backup\CompanyBackupDataInventory::fromObjects($objects, $registry),
            Backup\CompanyBackupFileInventory::fromArray([
                'format' => Backup\CompanyBackupFileInventory::FORMAT,
                'version' => Backup\CompanyBackupFileInventory::VERSION, 'areas' => [],
            ], $registry), Backup\CompanyBackupSecretInventory::fromCounts($counts, $registry)->withEnvelope($envelope->descriptor),
            $envelope, $files, $files);
        $archive = $this->directory . DIRECTORY_SEPARATOR . 'supplier.zip';
        (new Backup\CompanyBackupMachineArchiveWriter())->write($snapshot, $archive, self::PASSWORD,
            self::APP_VERSION, 'Syntetický test kořene firmy.');
        return $archive;
    }

    /** @return array<string,mixed> */
    private function supplierRow(PDO $pdo, int $supplier): array
    {
        $query = $pdo->prepare('SELECT * FROM supplier WHERE id = ?');
        $query->execute([$supplier]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
