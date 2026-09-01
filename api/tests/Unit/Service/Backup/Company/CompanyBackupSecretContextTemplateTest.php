<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretContextTemplate;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSecretContextTemplateTest extends TestCase
{
    public function testResolvesOnlyDeclaredIdentityCoordinates(): void
    {
        $template = CompanyBackupSecretContextTemplate::fromString(
            'payroll:{supplier_id}:{id}:bank_account',
            'table:payroll_institution_accounts',
            'bank_account_ciphertext',
        );
        $template->assertAllowedColumns(
            ['id', 'supplier_id'],
            'table:payroll_institution_accounts',
            'bank_account_ciphertext',
        );

        self::assertSame(['supplier_id', 'id'], $template->columns);
        self::assertSame(
            'payroll:42:73:bank_account',
            $template->resolve(
                ['id' => 73, 'supplier_id' => 42],
                'table:payroll_institution_accounts',
                'bank_account_ciphertext',
            ),
        );
    }

    public function testKeepsValidatedFixedContextCompatible(): void
    {
        $template = CompanyBackupSecretContextTemplate::fromString(
            'backup:synthetic-context',
            'table:synthetic',
            'secret_value',
        );

        self::assertSame([], $template->columns);
        self::assertSame(
            'backup:synthetic-context',
            $template->resolve([], 'table:synthetic', 'secret_value'),
        );
    }

    public function testRejectsMalformedDuplicateAndUnownedPlaceholders(): void
    {
        foreach ([
            'payroll:{id}:{id}:bank_account',
            'payroll:{id:bank_account',
            'payroll:{bad-column}:bank_account',
        ] as $invalid) {
            try {
                CompanyBackupSecretContextTemplate::fromString(
                    $invalid,
                    'table:synthetic',
                    'secret_value',
                );
                self::fail('Nekanonický context template nesmí projít.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('secret_source_storage_invalid', $e->errorCode);
                self::assertSame('secret_value', $e->column);
            }
        }

        $unknown = CompanyBackupSecretContextTemplate::fromString(
            'payroll:{employee_id}:bank_account',
            'table:synthetic',
            'secret_value',
        );
        try {
            $unknown->assertAllowedColumns(
                ['id', 'supplier_id'],
                'table:synthetic',
                'secret_value',
            );
            self::fail('Kontext nesmí číst souřadnici mimo PK a ownership.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_source_storage_invalid', $e->errorCode);
            self::assertSame('secret_value', $e->column);
        }
    }

    public function testRejectsNonCanonicalRuntimeValueWithoutLeakingIt(): void
    {
        $template = CompanyBackupSecretContextTemplate::fromString(
            'payroll:{supplier_id}:{id}:bank_account',
            'table:synthetic',
            'secret_value',
        );

        try {
            $template->resolve(
                ['supplier_id' => 42, 'id' => '73:foreign'],
                'table:synthetic',
                'secret_value',
            );
            self::fail('Nekanonická hodnota AAD souřadnice nesmí projít.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_source_context_invalid', $e->errorCode);
            self::assertSame('secret_value', $e->column);
            self::assertStringNotContainsString('73:foreign', $e->getMessage());
        }
    }
}
