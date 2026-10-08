<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollEmployerIdentifierSql;
use MyInvoice\Service\Payroll\Submission\CsszEmployerVariableSymbol;
use PHPUnit\Framework\TestCase;

final class CsszEmployerVariableSymbolTest extends TestCase
{
    public function testTestEnvironmentPrefersTheOfficeTestSymbol(): void
    {
        self::assertSame(
            '8880001234',
            CsszEmployerVariableSymbol::forEnvironment('test', '9990001234', '8880001234'),
        );
    }

    public function testProductionNeverGetsTheTestSymbol(): void
    {
        self::assertSame(
            '9990001234',
            CsszEmployerVariableSymbol::forEnvironment('production', '9990001234', '8880001234'),
        );
    }

    /** Bez platného testovacího VS zůstává VS účtárny — stejně jako u JMHZ. */
    public function testMissingOrInvalidTestSymbolFallsBackToTheOfficeSymbol(): void
    {
        foreach ([null, '', '  ', '12345', 'abcdefghij'] as $test) {
            self::assertSame(
                '9990001234',
                CsszEmployerVariableSymbol::forEnvironment('test', '9990001234', $test),
            );
        }
    }

    public function testUnknownEnvironmentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsszEmployerVariableSymbol::forEnvironment('staging', '9990001234', null);
    }

    /** Řádek kontextu vztahu dostane symbol prostředí a pomocný sloupec ztratí. */
    public function testEmploymentContextRowIsResolvedForTheEnvironment(): void
    {
        $row = [
            'employment_id' => 3,
            'employer_variable_symbol' => '9990001234',
            'employer_test_variable_symbol' => '8880001234',
        ];

        $test = PayrollEmployerIdentifierSql::resolveVariableSymbol($row, 'test');
        $production = PayrollEmployerIdentifierSql::resolveVariableSymbol($row, 'production');

        self::assertSame('8880001234', $test['employer_variable_symbol']);
        self::assertSame('9990001234', $production['employer_variable_symbol']);
        self::assertArrayNotHasKey('employer_test_variable_symbol', $test);
        self::assertSame(3, $test['employment_id']);
    }
}
