<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistryCoverage;
use MyInvoice\Service\Backup\Registry\TenantDataCoverageIssue;
use MyInvoice\Service\Backup\Registry\TenantDataCoverageReport;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryCoverageValidator;
use PDO;

/** Default-deny coverage celého DB schématu a explicitních company projekcí. */
final readonly class CompanyBackupDatabaseCoverageValidator implements CompanyBackupDatabaseCoverageGate
{
    public function __construct(
        private TenantDataRegistryCoverageValidator $objectCoverage = new TenantDataRegistryCoverageValidator(),
        private CompanyBackupTableSchemaReader $schemaReader = new CompanyBackupTableSchemaReader(),
    ) {}

    public function evaluate(PDO $pdo, TenantDataRegistry $registry): TenantDataCoverageReport
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $tableNames = $this->schemaReader->tableNames($pdo);
        $inventory = array_map(
            static fn (string $table): string => 'table:' . $table,
            $tableNames,
        );
        foreach ($registry->definitionsFor($profile) as $definition) {
            if ($definition->kind !== TenantDataObjectKind::Table) {
                $inventory[] = $definition->key;
            }
        }
        sort($inventory, SORT_STRING);

        $issues = $this->objectCoverage->evaluate(
            $registry,
            $profile,
            $inventory,
        )->issues;
        $runtimeTables = array_fill_keys($tableNames, true);
        foreach ($registry->definitionsFor($profile) as $definition) {
            if ($definition->kind !== TenantDataObjectKind::Table
                || !isset($runtimeTables[$definition->name()])
            ) {
                continue;
            }
            if ($definition->policy === TenantDataPolicy::OptionalCredential) {
                try {
                    $credential = CompanyBackupCredentialTableProjection::fromDefinition(
                        $definition,
                    );
                    $credential->assertRegistryTargets($registry);
                    $credential->assertRuntimeSchema(
                        $this->schemaReader->readCredential($pdo, $credential),
                        $this->schemaReader->readCredentialReferences(
                            $pdo,
                            $credential,
                        ),
                    );
                } catch (CompanyBackupDataSourceException $e) {
                    $issues[] = new TenantDataCoverageIssue(
                        $e->errorCode,
                        $definition->key,
                        'Credential projekce neodpovídá registru.'
                        . ($e->column === null ? '' : ' Sloupec: ' . $e->column . '.'),
                    );
                }
                continue;
            }
            if (!$definition->policy->hasMachineDataPayload()) {
                continue;
            }
            try {
                $projection = CompanyBackupTableProjection::fromDefinition($definition);
                $schema = $this->schemaReader->read($pdo, $projection);
                $projection->assertRuntimeSchema(
                    $schema->columns,
                    $schema->generatedColumns,
                    $schema->primaryKey,
                    $schema->binaryColumns,
                );
                $projection->references->assertRegistryTargets($registry);
                $projection->encodedReferences->assertRegistryTargets($registry);
                $projection->embeddedReferences->assertRegistryTargets($registry);
                $projection->embeddedHashReferences->assertRegistryTargets($registry);
                $projection->polymorphicReferences->assertRegistryTargets($registry);
                $projection->references->assertRuntimeSchema(
                    $this->schemaReader->readReferences($pdo, $projection),
                );
            } catch (CompanyBackupDataSourceException $e) {
                $issues[] = new TenantDataCoverageIssue(
                    $e->errorCode,
                    $definition->key,
                    'DB projekce neodpovídá registru.'
                    . ($e->column === null ? '' : ' Sloupec: ' . $e->column . '.'),
                );
            }
        }
        usort($issues, static function (
            TenantDataCoverageIssue $left,
            TenantDataCoverageIssue $right,
        ): int {
            $object = strcmp($left->object, $right->object);
            return $object !== 0 ? $object : strcmp($left->code, $right->code);
        });
        return new TenantDataCoverageReport($issues);
    }

    public function assertSafe(PDO $pdo, TenantDataRegistry $registry): void
    {
        $report = $this->evaluate($pdo, $registry);
        if (!$report->isSafe()) {
            throw new IncompleteTenantDataRegistryCoverage($report);
        }
    }
}
