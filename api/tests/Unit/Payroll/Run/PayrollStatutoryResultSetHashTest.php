<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollStatutoryResultSetHash;
use PHPUnit\Framework\TestCase;

final class PayrollStatutoryResultSetHashTest extends TestCase
{
    public function testBuildsCanonicalThreeLevelResultSetContract(): void
    {
        $people = [[
            'employee_id' => 17,
            'input_snapshot' => ['period' => '2026-06'],
            'relationships' => [[
                'employment_id' => 19,
                'input_snapshot' => ['base_minor' => 100_000],
                'result_snapshot' => ['employee_minor' => 7_100],
                'result_status' => 'calculated',
            ]],
            'result_snapshot' => ['employee_minor' => 7_100],
            'result_status' => 'calculated',
        ]];

        $canonical = PayrollStatutoryResultSetHash::canonical(
            'social_insurance',
            ['period' => '2026-06'],
            $people,
            ['employer_minor' => 24_800],
            'calculated',
            str_repeat('a', 64),
            'cz-social-2026.1',
            'payroll-social-result.v1',
        );

        self::assertSame(
            '{"calculation_kind":"social_insurance",'
                . '"input_snapshot":{"period":"2026-06"},'
                . '"people":[{"employee_id":17,'
                . '"input_snapshot":{"period":"2026-06"},'
                . '"relationships":[{"employment_id":19,'
                . '"input_snapshot":{"base_minor":100000},'
                . '"result_snapshot":{"employee_minor":7100},'
                . '"result_status":"calculated"}],'
                . '"result_snapshot":{"employee_minor":7100},'
                . '"result_status":"calculated"}],'
                . '"result_snapshot":{"employer_minor":24800},'
                . '"result_status":"calculated",'
                . '"ruleset_hash":"' . str_repeat('a', 64) . '",'
                . '"ruleset_id":"cz-social-2026.1",'
                . '"schema_version":"payroll-social-result.v1"}',
            $canonical,
        );
        self::assertSame(
            hash('sha256', $canonical),
            PayrollStatutoryResultSetHash::calculate(
                'social_insurance',
                ['period' => '2026-06'],
                $people,
                ['employer_minor' => 24_800],
                'calculated',
                str_repeat('a', 64),
                'cz-social-2026.1',
                'payroll-social-result.v1',
            ),
        );
    }

    public function testIncludesSurrogateReferencesInResultSetHash(): void
    {
        $arguments = [
            'social_insurance',
            ['period' => '2026-06'],
            [[
                'employee_id' => 17,
                'input_snapshot' => [],
                'relationships' => [[
                    'employment_id' => 19,
                    'input_snapshot' => [],
                    'result_snapshot' => [],
                    'result_status' => 'calculated',
                ]],
                'result_snapshot' => [],
                'result_status' => 'calculated',
            ]],
            ['employer_minor' => 24_800],
            'calculated',
            str_repeat('a', 64),
            'cz-social-2026.1',
            'payroll-social-result.v1',
        ];
        $sourceHash = PayrollStatutoryResultSetHash::calculate(...$arguments);
        $arguments[2][0]['employee_id'] = 117;
        $arguments[2][0]['relationships'][0]['employment_id'] = 219;

        self::assertNotSame(
            $sourceHash,
            PayrollStatutoryResultSetHash::calculate(...$arguments),
        );
    }
}
