<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupBankProjectionTest extends TestCase
{
    public function testStatementOwnershipCannotBeDeferredIntoLegacyDedupScope(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_statements');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        self::assertFalse($projection->allowsDeferredUpdates);
    }

    public function testCompanySelectionIncludesUnmatchedTransactionsOnlyOfOwnedStatement(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_transactions');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $selection = (new CompanyBackupTenantSqlSelector())->select($projection, 11);
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE bank_statements (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $db->exec('CREATE TABLE bank_transactions (id INTEGER PRIMARY KEY, statement_id INTEGER,
            matched_invoice_id INTEGER)');
        $db->exec('CREATE TABLE payment_matches (bank_transaction_id INTEGER, supplier_id INTEGER)');
        $db->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER)');
        $db->exec('CREATE TABLE client_bank_accounts (last_bank_transaction_id INTEGER, supplier_id INTEGER)');
        $db->exec('INSERT INTO supplier VALUES (11), (12)');
        $db->exec('INSERT INTO bank_statements VALUES (1, 11), (2, 12), (3, NULL)');
        $db->exec('INSERT INTO bank_transactions VALUES (21, 1, NULL), (22, 1, 100),
            (23, 2, NULL), (24, 3, NULL)');
        $db->exec('INSERT INTO invoices VALUES (100, 11)');
        $query = $db->prepare('SELECT id FROM bank_transactions AS `_company_source` WHERE '
            . $selection->where . ' ORDER BY id');
        $query->execute($selection->params);
        self::assertSame([21, 22], $query->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('bank_transaction_relationships',
            $definition->details['accounting_archive']['selector']);
    }
}
