<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlFileReferenceSource;
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

    public function testStreamsOnlyFileReferencesOwnedBySelectedSupplier(): void
    {
        $pdo = $this->db->pdo();
        $ownSupplierLogo = 'storage/supplier-logos/sup-'
            . $this->supplierId . '.png';
        $foreignSupplierLogo = 'storage/supplier-logos/sup-'
            . $this->foreignSupplierId . '.png';
        $statement = $pdo->prepare('UPDATE supplier SET logo_path = ? WHERE id = ?');
        $statement->execute([$ownSupplierLogo, $this->supplierId]);
        $statement->execute([$foreignSupplierLogo, $this->foreignSupplierId]);

        $ownProfileId = $this->createBrandingProfile(
            $pdo,
            $this->supplierId,
            'Company backup vlastní profil',
        );
        $foreignProfileId = $this->createBrandingProfile(
            $pdo,
            $this->foreignSupplierId,
            'Company backup cizí profil',
        );
        $ownProfileLogo = 'storage/supplier-logos/sup-' . $this->supplierId
            . '-brand-' . $ownProfileId . '-abcdef123456.png';
        $foreignProfileLogo = 'storage/supplier-logos/sup-'
            . $this->foreignSupplierId . '-brand-' . $foreignProfileId
            . '-123456abcdef.png';
        $statement = $pdo->prepare(
            'UPDATE branding_profiles SET logo_path = ? WHERE id = ?',
        );
        $statement->execute([$ownProfileLogo, $ownProfileId]);
        $statement->execute([$foreignProfileLogo, $foreignProfileId]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('file-area:supplier-logos');
        self::assertNotNull($definition);
        $references = iterator_to_array(
            (new CompanyBackupSqlFileReferenceSource(batchSize: 1))->references(
                $pdo,
                $this->supplierId,
                $definition,
                $registry,
            ),
        );
        $paths = array_column($references, 'sourcePath');

        self::assertContains(
            substr($ownProfileLogo, strlen('storage/supplier-logos/')),
            $paths,
        );
        self::assertContains(
            substr($ownSupplierLogo, strlen('storage/supplier-logos/')),
            $paths,
        );
        self::assertNotContains(
            substr($foreignProfileLogo, strlen('storage/supplier-logos/')),
            $paths,
        );
        self::assertNotContains(
            substr($foreignSupplierLogo, strlen('storage/supplier-logos/')),
            $paths,
        );

        $unscoped = $pdo->prepare(
            'SELECT logo_path FROM branding_profiles WHERE id IN (?, ?) ORDER BY id',
        );
        $unscoped->execute([$ownProfileId, $foreignProfileId]);
        self::assertContains(
            $foreignProfileLogo,
            $unscoped->fetchAll(PDO::FETCH_COLUMN),
            'Negativní kontrola musí bez tenantového filtru najít i cizí logo.',
        );
    }

    public function testProductionCurrenciesProjectionMatchesMigratedSchema(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:currencies');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);

        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );

        self::assertContains('supplier_id', $schema->columns);
        self::assertSame(['id'], $schema->primaryKey);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );
    }

    public function testProductionBrandingProfilesProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'branding_profiles',
            [
                'supplier_id',
                'email_profile_id',
                'logo_path',
                'branding_enabled',
                'is_active',
            ],
        );
    }

    public function testStreamsOnlyBrandingProfilesOfSelectedSupplier(): void
    {
        $pdo = $this->db->pdo();
        $ownProfileId = $this->createBrandingProfile(
            $pdo,
            $this->supplierId,
            'Company backup vlastní stream profil',
        );
        $foreignProfileId = $this->createBrandingProfile(
            $pdo,
            $this->foreignSupplierId,
            'Company backup cizí stream profil',
        );
        $definition = TenantDataRegistryFactory::draftV1()
            ->definition('table:branding_profiles');
        self::assertNotNull($definition);

        $rows = iterator_to_array((new CompanyBackupSqlRowSource(batchSize: 1))->rows(
            $pdo,
            $this->supplierId,
            $definition,
        ));
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        self::assertContains($ownProfileId, $ids);
        self::assertNotContains($foreignProfileId, $ids);

        $unscoped = $pdo->prepare(
            'SELECT id FROM branding_profiles WHERE id IN (?, ?) ORDER BY id',
        );
        $unscoped->execute([$ownProfileId, $foreignProfileId]);
        self::assertContains(
            $foreignProfileId,
            array_map('intval', $unscoped->fetchAll(PDO::FETCH_COLUMN)),
            'Negativní kontrola musí bez tenantového filtru najít cizí profil.',
        );
    }

    public function testProductionAccountingPeriodsProjectionMatchesMigratedSchema(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:accounting_periods');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);

        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        self::assertContains('approved_by', $schema->columns);
        self::assertContains('reviewed_by', $schema->columns);
    }

    public function testProductionChartOfAccountsProjectionMatchesMigratedSchema(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:chart_of_accounts');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);

        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        self::assertContains('parent_id', $schema->columns);
        self::assertContains('is_clearing', $schema->columns);
    }

    public function testProductionPostingRulesProjectionMatchesMigratedSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'posting_rules',
            ['debit_account_code', 'credit_account_code'],
        );
    }

    public function testProductionCostCentersProjectionMatchesMigratedSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'cost_centers',
            ['supplier_id', 'updated_at'],
        );
    }

    public function testProductionAccountingSupplierSettingsProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'accounting_supplier_settings',
            ['automation_level', 'automation_digest_enabled', 'single_analytic_redirect'],
        );
    }

    public function testProductionAccountingDocumentSeriesProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'accounting_document_series',
            ['series_code', 'register_id', 'number_format', 'next_number'],
        );
    }

    public function testProductionAccountingClosingStepsProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'accounting_closing_steps',
            ['period_id', 'payload', 'done_by', 'updated_at'],
        );
    }

    public function testProductionJournalEntriesProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'journal_entries',
            ['period_id', 'source_type', 'source_id', 'posted_by', 'reversed_by', 'updated_at'],
        );

        $statement = $this->db->pdo()->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entries'"
            . " AND COLUMN_NAME = 'source_type'",
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze načíst ENUM journal_entries.source_type.');
        }
        $columnType = $statement->fetchColumn();
        if (!is_string($columnType)
            || preg_match_all("/'([a-z_]+)'/D", $columnType, $matches) === false
        ) {
            throw new \RuntimeException('journal_entries.source_type nemá očekávaný ENUM.');
        }
        $schemaSourceTypes = $matches[1];
        sort($schemaSourceTypes, SORT_STRING);

        $definition = TenantDataRegistryFactory::draftV1()->definition('table:journal_entries');
        self::assertNotNull($definition);
        $references = CompanyBackupTableProjection::fromDefinition($definition)
            ->polymorphicReferences
            ->references;
        $sourceReference = $references[0] ?? null;
        if ($sourceReference === null) {
            throw new \LogicException('journal_entries nemá polymorfní source_id kontrakt.');
        }
        $projectedSourceTypes = array_map(
            static fn ($case): string => $case->equals,
            $sourceReference->cases,
        );
        sort($projectedSourceTypes, SORT_STRING);

        self::assertSame(
            $schemaSourceTypes,
            $projectedSourceTypes,
            'Každá ENUM varianta source_type musí mít explicitní source_id strategii.',
        );
    }

    public function testProductionJournalEntryLinesProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'journal_entry_lines',
            ['entry_id', 'account_id', 'currency_code', 'cost_center', 'project_id', 'line_no'],
        );
    }

    public function testProductionProjectsProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'projects',
            [
                'client_id',
                'currency_id',
                'billing_emails_mode',
                'payment_due_unit',
                'default_revenue_category_id',
            ],
        );
    }

    public function testProductionRevenueCategoriesProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'revenue_categories',
            [
                'supplier_id',
                'code',
                'invoice_number_format',
                'invoice_number_period',
            ],
        );
    }

    public function testProductionExpenseCategoriesProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'expense_categories',
            ['supplier_id', 'code', 'fixed_or_var', 'archived'],
        );
    }

    public function testProductionCountriesProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'countries',
            ['iso2', 'iso3', 'name_cs', 'name_en', 'is_eu'],
        );
    }

    public function testProductionVatRatesProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'vat_rates',
            ['code', 'rate_percent', 'country', 'valid_from', 'valid_to'],
        );
    }

    public function testProductionClientsProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'clients',
            [
                'supplier_id',
                'country_id',
                'currency_default_id',
                'vat_rate_default_id',
                'idoklad_id',
                'fakturoid_id',
                'default_branding_profile_id',
                'default_expense_category_id',
                'default_revenue_category_id',
            ],
        );
    }

    public function testProductionInvoiceSettlementsProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'invoice_settlements',
            [
                'supplier_id',
                'doc_type',
                'doc_id',
                'account_id',
                'journal_entry_id',
                'reversal_entry_id',
                'invoice_payment_id',
                'created_by',
            ],
        );
    }

    public function testProductionOffsetAgreementsProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'offset_agreements',
            [
                'supplier_id',
                'partner_id',
                'document_no',
                'journal_entry_id',
                'created_by',
            ],
        );
    }

    public function testProductionOffsetAgreementItemsProjectionMatchesSchema(): void
    {
        $this->assertProductionProjectionMatchesSchema(
            'offset_agreement_items',
            [
                'agreement_id',
                'supplier_id',
                'doc_type',
                'doc_id',
                'invoice_payment_id',
            ],
        );
    }

    public function testStreamsOnlyClientsOfCompanyAndPreservesExternalIds(): void
    {
        $pdo = $this->db->pdo();
        $countryId = $this->scalarInt(
            $pdo,
            "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
        );
        $currencyId = $this->scalarInt(
            $pdo,
            "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
        );
        $ownClientId = $this->createClient(
            $pdo,
            $this->supplierId,
            'Company backup SQL vlastní externí klient s.r.o.',
            $countryId,
            $currencyId,
        );
        $foreignClientId = $this->createClient(
            $pdo,
            $this->foreignSupplierId,
            'Company backup SQL cizí externí klient s.r.o.',
            $countryId,
            $currencyId,
        );
        $statement = $pdo->prepare(
            'UPDATE clients SET idoklad_id = ?, fakturoid_id = ? WHERE id = ?',
        );
        $statement->execute([910001, 910002, $ownClientId]);
        $statement->execute([920001, 920002, $foreignClientId]);

        $definition = TenantDataRegistryFactory::draftV1()->definition('table:clients');
        self::assertNotNull($definition);
        $rows = iterator_to_array((new CompanyBackupSqlRowSource(batchSize: 1))->rows(
            $pdo,
            $this->supplierId,
            $definition,
        ));
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        self::assertArrayHasKey($ownClientId, $byId);
        self::assertArrayNotHasKey($foreignClientId, $byId);
        self::assertSame(910001, (int) $byId[$ownClientId]['idoklad_id']);
        self::assertSame(910002, (int) $byId[$ownClientId]['fakturoid_id']);
    }

    public function testStreamsOnlyGlobalRowsReferencedBySelectedSupplier(): void
    {
        $pdo = $this->db->pdo();
        $ownCountryId = $this->createCountry($pdo, 'XQ', 'XQX', 'Vlastní testovací země');
        $foreignCountryId = $this->createCountry($pdo, 'XR', 'XRX', 'Cizí testovací země');
        $ownVatRateId = $this->createVatRate($pdo, 'backup_owner_vat');
        $foreignVatRateId = $this->createVatRate($pdo, 'backup_foreign_vat');
        $statement = $pdo->prepare(
            'UPDATE supplier SET country_id = ?, default_vat_rate_id = ? WHERE id = ?',
        );
        $statement->execute([$ownCountryId, $ownVatRateId, $this->supplierId]);
        $statement->execute([
            $foreignCountryId,
            $foreignVatRateId,
            $this->foreignSupplierId,
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $countries = $registry->definition('table:countries');
        $vatRates = $registry->definition('table:vat_rates');
        self::assertNotNull($countries);
        self::assertNotNull($vatRates);
        $source = new CompanyBackupSqlRowSource(batchSize: 1);
        $countryIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            iterator_to_array($source->rows($pdo, $this->supplierId, $countries)),
        );
        $vatRateIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            iterator_to_array($source->rows($pdo, $this->supplierId, $vatRates)),
        );

        self::assertContains($ownCountryId, $countryIds);
        self::assertNotContains($foreignCountryId, $countryIds);
        self::assertContains($ownVatRateId, $vatRateIds);
        self::assertNotContains($foreignVatRateId, $vatRateIds);
    }

    public function testStreamsOnlyProjectsOwnedThroughSelectedClient(): void
    {
        $pdo = $this->db->pdo();
        $countryId = $this->scalarInt(
            $pdo,
            "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
        );
        $currencyId = $this->scalarInt(
            $pdo,
            "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
        );
        $ownClientId = $this->createClient(
            $pdo,
            $this->supplierId,
            'Company backup SQL vlastní klient s.r.o.',
            $countryId,
            $currencyId,
        );
        $foreignClientId = $this->createClient(
            $pdo,
            $this->foreignSupplierId,
            'Company backup SQL cizí klient s.r.o.',
            $countryId,
            $currencyId,
        );
        $ownProjectId = $this->createProject(
            $pdo,
            $ownClientId,
            'Company backup SQL vlastní zakázka',
            $currencyId,
        );
        $foreignProjectId = $this->createProject(
            $pdo,
            $foreignClientId,
            'Company backup SQL cizí zakázka',
            $currencyId,
        );

        $definition = TenantDataRegistryFactory::draftV1()->definition('table:projects');
        self::assertNotNull($definition);
        $rows = iterator_to_array((new CompanyBackupSqlRowSource(batchSize: 1))->rows(
            $pdo,
            $this->supplierId,
            $definition,
        ));
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        self::assertContains($ownProjectId, $ids);
        self::assertNotContains(
            $foreignProjectId,
            $ids,
            'Nepřímý selector nesmí přes klienta propustit zakázku jiné firmy.',
        );

        $unscopedStatement = $pdo->prepare(
            'SELECT id FROM projects WHERE id IN (?, ?) ORDER BY id',
        );
        $unscopedStatement->execute([$ownProjectId, $foreignProjectId]);
        $unscoped = array_map('intval', $unscopedStatement->fetchAll(PDO::FETCH_COLUMN));
        self::assertContains(
            $foreignProjectId,
            $unscoped,
            'Negativní kontrola musí bez tenantového selectoru cizí zakázku najít.',
        );
    }

    /** @param list<string> $expectedColumns */
    private function assertProductionProjectionMatchesSchema(
        string $table,
        array $expectedColumns,
    ): void {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:' . $table);
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);

        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->polymorphicReferences->assertRegistryTargets($registry);
        foreach ($expectedColumns as $column) {
            self::assertContains($column, $schema->columns);
        }
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
                    'embedded_references' => [],
                    'generated_columns' => $generatedColumns,
                    'omit_columns' => [],
                    'references' => [
                        $this->actorReference('approved_by'),
                        $this->actorReference('closed_by'),
                        $this->actorReference('reviewed_by'),
                        [
                            'columns' => ['supplier_id'],
                            'target' => 'table:supplier',
                            'target_columns' => ['id'],
                            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                            'constraint' => CompanyBackupReferenceConstraint::Required->value,
                            'nullable_columns' => [],
                            'fallbacks' => [],
                        ],
                    ],
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function actorReference(string $column): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => [$column],
            'fallbacks' => ['null', 'restore_actor'],
        ];
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

    private function createBrandingProfile(
        PDO $pdo,
        int $supplierId,
        string $name,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO branding_profiles (supplier_id, name) VALUES (?, ?)',
        );
        $statement->execute([$supplierId, $name]);
        return (int) $pdo->lastInsertId();
    }

    private function createClient(
        PDO $pdo,
        int $supplierId,
        string $name,
        int $countryId,
        int $currencyId,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO clients ('
            . 'supplier_id, company_name, street, city, zip, country_id, currency_default_id'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $name,
            'Testovací 2',
            'Brno',
            '60200',
            $countryId,
            $currencyId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function createProject(
        PDO $pdo,
        int $clientId,
        string $name,
        int $currencyId,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO projects (client_id, name, currency_id) VALUES (?, ?, ?)',
        );
        $statement->execute([$clientId, $name, $currencyId]);
        return (int) $pdo->lastInsertId();
    }

    private function createCountry(
        PDO $pdo,
        string $iso2,
        string $iso3,
        string $name,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO countries (iso2, iso3, name_cs, name_en, is_eu)'
            . ' VALUES (?, ?, ?, ?, 0)',
        );
        $statement->execute([$iso2, $iso3, $name, $name]);
        return (int) $pdo->lastInsertId();
    }

    private function createVatRate(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO vat_rates ('
            . 'code, rate_percent, country, label_cs, label_en, valid_from'
            . ') VALUES (?, 17.00, ?, ?, ?, ?)',
        );
        $statement->execute([
            $code,
            'CZ',
            'Syntetická sazba 17 %',
            'Synthetic rate 17%',
            '2001-01-01',
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
