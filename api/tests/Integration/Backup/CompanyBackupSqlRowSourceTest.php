<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchemaReader;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretColumnDetector;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Živá MariaDB kontrola syntaxe a tenantové izolace registry-driven streamu. */
#[Group('integration')]
final class CompanyBackupSqlRowSourceTest extends TestCase
{
    private Connection $db;
    private int $supplierId = 0;
    private int $foreignSupplierId = 0;
    private int $ownPeriodId = 0;
    private int $foreignPeriodId = 0;
    private bool $connected = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                throw new \RuntimeException('Aplikace nemá DI kontejner.');
            }
            $connection = $container->get(Connection::class);
            if (!$connection instanceof Connection) {
                throw new \RuntimeException('DI nevrátilo databázové spojení.');
            }
            $this->db = $connection;
            $pdo = $this->db->pdo();
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Testovací DB není dostupná: ' . $e->getMessage());
        }

        $currencyId = $this->scalarInt(
            $pdo,
            "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
        );
        $vatRateId = $this->scalarInt(
            $pdo,
            'SELECT id FROM vat_rates ORDER BY id LIMIT 1',
        );
        $countryId = $this->scalarInt(
            $pdo,
            "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
        );
        if ($currencyId < 1 || $vatRateId < 1 || $countryId < 1) {
            $this->markTestSkipped('Testovací DB nemá základní syntetické číselníky.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createSupplier(
            $pdo,
            'Company backup SQL vlastník s.r.o.',
            'company-backup-owner@example.test',
            $countryId,
            $currencyId,
            $vatRateId,
        );
        $this->foreignSupplierId = $this->createSupplier(
            $pdo,
            'Company backup SQL cizí s.r.o.',
            'company-backup-foreign@example.test',
            $countryId,
            $currencyId,
            $vatRateId,
        );
        $this->ownPeriodId = $this->createPeriod($pdo, $this->supplierId, 1891);
        $this->foreignPeriodId = $this->createPeriod($pdo, $this->foreignSupplierId, 1892);
    }

    protected function tearDown(): void
    {
        if ($this->connected) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testStreamsOnlyRowsOwnedBySelectedSupplier(): void
    {
        $pdo = $this->db->pdo();
        $rows = iterator_to_array((new CompanyBackupSqlRowSource(batchSize: 1))->rows(
            $pdo,
            $this->supplierId,
            $this->accountingPeriodsDefinition($pdo),
        ));
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        self::assertContains(
            $this->ownPeriodId,
            $ids,
            'Stream musí obsahovat syntetický řádek vybrané firmy.',
        );
        self::assertNotContains(
            $this->foreignPeriodId,
            $ids,
            'Stream nesmí obsahovat syntetický řádek cizí firmy.',
        );

        $unscopedStatement = $pdo->prepare(
            'SELECT id FROM accounting_periods WHERE id IN (?, ?) ORDER BY id'
        );
        $unscopedStatement->execute([$this->ownPeriodId, $this->foreignPeriodId]);
        $unscoped = array_map('intval', $unscopedStatement->fetchAll(PDO::FETCH_COLUMN));
        self::assertContains(
            $this->foreignPeriodId,
            $unscoped,
            'Negativní kontrola musí bez tenantového filtru cizí řádek skutečně najít.',
        );
    }

    public function testProductionCurrenciesProjectionMatchesMigratedSchema(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:currencies');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schema = (new CompanyBackupTableSchemaReader())->read($this->db->pdo(), $projection);

        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
        );

        self::assertContains('supplier_id', $schema->columns);
        self::assertSame(['id'], $schema->primaryKey);
    }

    private function accountingPeriodsDefinition(PDO $pdo): TenantDataDefinition
    {
        $statement = $pdo->query(
            'SELECT COLUMN_NAME, EXTRA, GENERATION_EXPRESSION'
            . ' FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounting_periods'"
            . ' ORDER BY ORDINAL_POSITION'
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze načíst schéma accounting_periods.');
        }
        $dataColumns = [];
        $generatedColumns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $name = (string) $column['COLUMN_NAME'];
            self::assertFalse(
                TenantSecretColumnDetector::matches($name),
                'Integrační fixture nesmí automaticky prohlásit secret-like sloupec za běžná data.',
            );
            $generation = $column['GENERATION_EXPRESSION'];
            $generated = (is_string($generation) && $generation !== '')
                || str_contains(strtoupper((string) $column['EXTRA']), 'GENERATED');
            if ($generated) {
                $generatedColumns[] = $name;
            } else {
                $dataColumns[] = $name;
            }
        }

        return new TenantDataDefinition(
            'table:accounting_periods',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => $dataColumns,
                    'generated_columns' => $generatedColumns,
                    'omit_columns' => [],
                ],
            ],
        );
    }

    private function createSupplier(
        PDO $pdo,
        string $name,
        string $email,
        int $countryId,
        int $currencyId,
        int $vatRateId,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO supplier ('
            . 'company_name, street, city, zip, country_id, email,'
            . ' default_currency_id, default_vat_rate_id'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $name,
            'Testovací 1',
            'Praha',
            '11000',
            $countryId,
            $email,
            $currencyId,
            $vatRateId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function createPeriod(PDO $pdo, int $supplierId, int $year): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO accounting_periods ('
            . 'supplier_id, fiscal_year, starts_on, ends_on, status'
            . ') VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $year,
            $year . '-01-01',
            $year . '-12-31',
            'open',
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function scalarInt(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Nelze načíst syntetický číselník testu.');
        }
        $value = $statement->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }
}
