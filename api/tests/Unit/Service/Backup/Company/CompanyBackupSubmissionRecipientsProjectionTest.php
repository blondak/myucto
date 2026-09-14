<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSubmissionRecipientsProjection as Projection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Registry\CompanyBackupSubmissionRecipientsDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSubmissionRecipientsProjectionTest extends TestCase
{
    public function testFullPersistedContractAndDistinctSourceIdentities(): void
    {
        $definition = CompanyBackupSubmissionRecipientsDefinition::definition();
        Projection::assertDefinition($definition);
        $table = CompanyBackupTableProjection::fromDefinition($definition);
        self::assertSame([
            'id', 'supplier_id', 'code', 'name', 'business_id', 'address',
            'kind', 'isds_box_id', 'source_url', 'source_note', 'is_active',
            'verified_in_isds_at', 'created_by', 'created_at', 'updated_at',
        ], $table->dataColumns);
        self::assertSame(['business_id', 'isds_box_id'], $table->preservedIdentifiers->columns);
        self::assertSame([], $table->omitColumns);
        self::assertSame([], $table->generatedColumns);
        self::assertSame([
            CompanyBackupReferenceConstraint::Required,
            CompanyBackupReferenceConstraint::Required,
        ], array_map(static fn ($reference) => $reference->constraint,
            $table->references->references));

        $identity = CompanyBackupSourceIdentityProjection::fromDefinition($definition);
        $own = $identity->identityForRow(['id' => 11, 'supplier_id' => 7, 'code' => 'my_tax_office']);
        self::assertSame(TenantDataPolicy::TenantOwned, $own->policy);
        self::assertSame(['id' => 11], $own->primaryKey->values);
        self::assertSame(['supplier_id' => 7, 'id' => 11], $own->tenantScopedPrimaryKey?->values);
        self::assertNull($own->naturalKey);
        self::assertSame([], $own->referenceKeys);

        $system = $identity->identityForRow(['id' => 12, 'supplier_id' => null, 'code' => 'cssz']);
        self::assertSame(TenantDataPolicy::GlobalReference, $system->policy);
        self::assertSame(['id' => 12], $system->primaryKey->values);
        self::assertNull($system->tenantScopedPrimaryKey);
        self::assertSame(['code' => 'cssz'], $system->naturalKey?->values);
        self::assertSame([], $system->referenceKeys);
        self::assertSame($own->toArray(), CompanyBackupSourceIdentity::fromArray($own->toArray())->toArray());
        self::assertSame($system->toArray(), CompanyBackupSourceIdentity::fromArray($system->toArray())->toArray());
    }

    public function testChangedDefinitionAndInvalidRowScopeFailClosed(): void
    {
        $definition = CompanyBackupSubmissionRecipientsDefinition::definition();
        $details = $definition->details;
        $details['ownership'] = ['strategy' => 'supplier_id', 'column' => 'supplier_id'];
        $altered = new TenantDataDefinition(
            $definition->key, $definition->kind, $definition->policy,
            $definition->profiles, $details,
        );
        try {
            CompanyBackupSourceIdentityProjection::fromDefinition($altered);
            self::fail('Změněná definice nesmí získat mixed-row výjimku.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('data_submission_recipients_contract_invalid', $e->errorCode);
        }

        $identity = CompanyBackupSourceIdentityProjection::fromDefinition($definition);
        foreach ([0, -1, '', '7', false] as $invalid) {
            try {
                $identity->identityForRow(['id' => 12, 'supplier_id' => $invalid, 'code' => 'cssz']);
                self::fail('Neplatná identita příjemce nesmí projít.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('data_submission_recipient_scope_invalid', $e->errorCode);
            }
        }
    }

    public function testSelectsOwnAndOnlyUsedSystemRecipients(): void
    {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('CREATE TABLE submission_recipients (id INTEGER PRIMARY KEY, supplier_id INTEGER NULL)');
        $database->exec('CREATE TABLE submission_outbox (supplier_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL)');
        $database->exec('INSERT INTO submission_recipients VALUES (101, 7), (102, 8), (103, NULL), (104, NULL), (105, NULL)');
        $database->exec('INSERT INTO submission_outbox VALUES (7, 103), (8, 104)');
        $projection = CompanyBackupTableProjection::fromDefinition(
            CompanyBackupSubmissionRecipientsDefinition::definition(),
        );
        $selector = new CompanyBackupTenantSqlSelector();
        foreach ([7 => [101, 103], 8 => [102, 104], 9 => []] as $supplierId => $expected) {
            $selection = $selector->select($projection, $supplierId);
            self::assertSame([$supplierId, $supplierId], $selection->params);
            $statement = $database->prepare(
                'SELECT `_company_source`.`id` FROM `submission_recipients` AS `_company_source`'
                . ' WHERE ' . $selection->where . ' ORDER BY `_company_source`.`id`',
            );
            $statement->execute($selection->params);
            self::assertSame($expected, array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        }
    }
}
