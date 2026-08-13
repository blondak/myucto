<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollComponentJmhzMappingMigrationTest extends TestCase
{
    public function testMappingIsTenantScopedVersionedAndRestrictedToPinnedTargets(): void
    {
        $root = dirname(__DIR__, 4) . '/db/migrations/';
        $base = file_get_contents($root . '1342_payroll_component_jmhz_mapping.sql');
        $lifecycle = file_get_contents($root . '1343_payroll_component_jmhz_mapping_lifecycle.sql');
        $guards = file_get_contents($root . '1344_payroll_component_jmhz_mapping_guards.sql');
        self::assertIsString($base);
        self::assertIsString($lifecycle);
        self::assertIsString($guards);

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS payroll_component_jmhz_mappings', $base);
        self::assertStringContainsString('(supplier_id, component_definition_id, spec_package_id)', $base);
        self::assertStringContainsString('REFERENCES payroll_jmhz_dictionary_attributes(package_id, attribute_id)', $base);
        self::assertStringContainsString('CHARACTER SET ascii COLLATE ascii_bin', $base);
        self::assertStringContainsString('row_version > 0', $base);

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS is_active', $lifecycle);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS disabled_at', $lifecycle);
        self::assertStringContainsString('ON UPDATE RESTRICT ON DELETE RESTRICT', $lifecycle);
        self::assertStringContainsString("'10328','10329','10330','10331','10332'", $lifecycle);
        self::assertStringContainsString("'10337','10338','10339','10340','10341','10342','10343','10417'", $lifecycle);
        self::assertStringContainsString('(is_active = 1 AND disabled_at IS NULL)', $lifecycle);
        self::assertStringContainsString('(is_active = 0 AND disabled_at IS NOT NULL)', $lifecycle);
        self::assertStringContainsString(
            'DROP CONSTRAINT IF EXISTS chk_payroll_component_jmhz_mapping_lifecycle',
            $lifecycle,
        );
        self::assertSame(3, substr_count($lifecycle, 'CREATE TRIGGER IF NOT EXISTS'));
        self::assertSame(3, substr_count($guards, 'DROP TRIGGER IF EXISTS'));
        self::assertSame(3, substr_count($guards, 'CREATE TRIGGER trg_payroll_component_jmhz_'));
        self::assertStringContainsString('Active JMHZ mapping requires an included payroll component', $guards);
        self::assertStringContainsString('Disable active JMHZ mapping before changing component treatment', $guards);
        self::assertDoesNotMatchRegularExpression('/\bemployee_id\b/', $base . $lifecycle . $guards);
    }
}
