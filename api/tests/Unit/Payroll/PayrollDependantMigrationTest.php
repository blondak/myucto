<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollDependantMigrationTest extends TestCase
{
    public function testDependantTableStoresBirthNumberOnlyEncrypted(): void
    {
        $sql = $this->migration();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS payroll_dependants', $sql);
        self::assertStringContainsString('birth_number_ciphertext VARCHAR(512) NULL', $sql);
        self::assertStringContainsString('birth_number_hash       BINARY(32) NULL', $sql);
        self::assertStringContainsString('birth_number_masked     VARCHAR(191) NULL', $sql);
        self::assertStringNotContainsString('birth_number            VARCHAR', $sql);
        self::assertStringContainsString('chk_payroll_dependant_secret', $sql);
    }

    public function testDependantIsTenantScopedAndBoundToSharedEmployee(): void
    {
        $sql = $this->migration();

        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, employee_id)' . "\n"
            . '    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_dependant_birth_number' . "\n"
            . '    (supplier_id, employee_id, birth_number_hash)',
            $sql,
        );
        self::assertStringContainsString('idx_payroll_dependant_tenant_hash', $sql);
        self::assertStringNotContainsString('payroll_persons', $sql);
    }

    public function testClaimEvidenceIsExtendedInsteadOfDuplicated(): void
    {
        $sql = $this->migration();

        self::assertStringNotContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_dependant_claims',
            $sql,
        );
        self::assertStringContainsString(
            'ALTER TABLE payroll_person_tax_child_claims',
            $sql,
        );
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS dependant_id', $sql);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS claim_reason', $sql);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS superseded_by_id', $sql);
        self::assertStringContainsString('fk_pp_tax_child_dependant', $sql);
    }

    public function testMigrationIsIdempotent(): void
    {
        $sql = $this->migration();

        self::assertStringContainsString('DROP FOREIGN KEY IF EXISTS', $sql);
        self::assertSame(3, substr_count($sql, 'ADD COLUMN IF NOT EXISTS'));
        self::assertSame(3, substr_count($sql, 'ADD KEY IF NOT EXISTS'));
        self::assertStringNotContainsString('PREPARE', $sql);
    }

    public function testCreditRatesStayInTheRuleset(): void
    {
        $sql = $this->migration();

        self::assertStringNotContainsString('126700', $sql);
        self::assertStringNotContainsString('186000', $sql);
        self::assertStringNotContainsString('232000', $sql);
    }

    private function migration(): string
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/1312_payroll_dependants.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql, 'Migrace 1312 nebyla nalezena.');

        // Bez normalizace schválně: víceřádkové asserty porovnávají na "\n"
        // a `.gitattributes` drží `*.sql text eol=lf` na všech platformách.
        // Kdyby to pravidlo někdo odstranil, tenhle test to má odhalit —
        // lokální str_replace by regresi jen zamaskoval.
        return $sql;
    }
}
