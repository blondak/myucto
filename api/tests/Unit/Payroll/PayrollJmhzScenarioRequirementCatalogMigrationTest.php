<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzScenarioRequirementCatalogMigrationTest extends TestCase
{
    public function testScenarioRequirementRegistryIsImmutablePackageScopedAndSourceOnly(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1339_payroll_jmhz_scenario_requirement_catalog.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        foreach ([
            'payroll_jmhz_scenario_catalogs',
            'payroll_jmhz_scenario_definitions',
            'payroll_jmhz_interaction_definitions',
            'payroll_jmhz_interaction_attribute_refs',
            'payroll_jmhz_requirement_matrices',
            'payroll_jmhz_field_requirements',
            'payroll_jmhz_master_attribute_axis',
            'payroll_jmhz_matrix_evidence_axes',
            'payroll_jmhz_matrix_evidence_members',
        ] as $table) {
            self::assertStringContainsString("CREATE TABLE IF NOT EXISTS {$table}", $sql);
        }
        self::assertSame(26, substr_count($sql, 'CREATE TRIGGER IF NOT EXISTS'));
        self::assertSame(27, substr_count($sql, 'SIGNAL SQLSTATE'));
        self::assertStringContainsString('REFERENCES payroll_jmhz_dictionary_attributes', $sql);
        self::assertStringContainsString("matrix_kind IN ('part','scenario','foundation','interaction')", $sql);
        self::assertStringContainsString("axis_kind IN ('reconciliation','derived_binary')", $sql);
        self::assertStringContainsString(
            "trigger_kind IN ('attribute_raw','virtual_raw','compound_raw','month_raw')",
            $sql,
        );
        self::assertStringContainsString('Only derived JMHZ evidence axes have sparse members', $sql);
        self::assertDoesNotMatchRegularExpression('/^\s+supplier_id\s+/m', $sql);
        self::assertStringNotContainsString('implementation_class', $sql);
        self::assertStringNotContainsString('expression_sql', $sql);
        self::assertStringNotContainsString('evaluator', $sql);

        self::assertMatchesRegularExpression('/source_cell\s+VARCHAR\(128\) NOT NULL/', $sql);
        self::assertStringContainsString('dictionary_formula_vector_sha256', $sql);
        self::assertStringContainsString('chk_jmhz_matrix_evidence_source_fidelity', $sql);

        $fidelity = file_get_contents(
            dirname(__DIR__, 4) . '/db/migrations/1341_payroll_jmhz_scenario_evidence_fidelity.sql',
        );
        self::assertIsString($fidelity);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS source_sheet', $fidelity);
        self::assertStringContainsString('DROP CONSTRAINT IF EXISTS', $fidelity);
    }
}
