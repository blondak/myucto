<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceCounterKey;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoiceCounterKeyTest extends TestCase
{
    public function testGeneralAndScopedKeysSurviveCanonicalRoundTrip(): void
    {
        foreach ([[0, 0], [17, 0], [0, 19], [17, 19]] as [$client, $category]) {
            foreach (['invoice', 'proforma', 'credit_note'] as $type) {
                $values = ['supplier_id' => 7, 'client_id' => $client, 'revenue_category_id' => $category,
                    'invoice_type' => $type, 'period' => 'ALL'];
                $key = CompanyBackupSourceKey::fromValues(CompanyBackupInvoiceCounterKey::REGISTRY_KEY, $values);
                self::assertSame($values, $key->values);
                self::assertTrue($key->equals(CompanyBackupSourceKey::fromArray($key->toArray())));
            }
        }
    }

    public function testZeroExceptionCannotCreateAZeroEntityOrPartialCounterKey(): void
    {
        $values = ['supplier_id' => 7, 'client_id' => 0, 'revenue_category_id' => 0,
            'invoice_type' => 'invoice', 'period' => 'ALL'];
        foreach ([
            ['table:clients', ['id' => 0]],
            ['table:revenue_categories', ['id' => 0]],
            ['table:other_counters', $values],
            [CompanyBackupInvoiceCounterKey::REGISTRY_KEY, ['client_id' => 0]],
            [CompanyBackupInvoiceCounterKey::REGISTRY_KEY, array_replace($values, ['supplier_id' => 0])],
            [CompanyBackupInvoiceCounterKey::REGISTRY_KEY, array_replace($values, ['client_id' => -1])],
            [CompanyBackupInvoiceCounterKey::REGISTRY_KEY, array_replace($values, ['invoice_type' => 'unknown'])],
            [CompanyBackupInvoiceCounterKey::REGISTRY_KEY, array_replace($values, ['period' => ''])],
            [CompanyBackupInvoiceCounterKey::REGISTRY_KEY, [...$values, 'id' => 1]],
        ] as [$table, $invalid]) {
            try {
                CompanyBackupSourceKey::fromValues($table, $invalid);
                self::fail('Nulové ID je přípustné jen jako osa úplného platného klíče čítače.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('source_key_value_invalid', $e->errorCode);
            }
        }
    }
}
