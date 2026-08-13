<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzControlCatalogMigrationTest extends TestCase
{
    public function testControlSourceRegistryIsImmutableAndPackageScoped(): void
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/1336_payroll_jmhz_control_catalog.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        foreach ([
            'payroll_jmhz_control_catalogs',
            'payroll_jmhz_control_definitions',
            'payroll_jmhz_control_attribute_refs',
            'payroll_jmhz_control_parameters',
            'payroll_jmhz_control_parameter_refs',
            'payroll_jmhz_control_parameter_values',
        ] as $table) {
            self::assertStringContainsString("CREATE TABLE IF NOT EXISTS {$table}", $sql);
        }
        self::assertSame(17, substr_count($sql, 'CREATE TRIGGER IF NOT EXISTS'));
        self::assertSame(17, substr_count($sql, 'SIGNAL SQLSTATE'));
        self::assertStringContainsString('REFERENCES payroll_jmhz_dictionary_attributes', $sql);
        self::assertStringContainsString("resolution = 'missing'", $sql);
        self::assertStringContainsString('detail_formula', $sql);
        self::assertStringContainsString('error_message_formula', $sql);
        self::assertStringNotContainsString('implementation_class', $sql);
    }
}
