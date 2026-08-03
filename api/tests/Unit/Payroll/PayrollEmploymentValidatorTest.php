<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use PHPUnit\Framework\TestCase;

final class PayrollEmploymentValidatorTest extends TestCase
{
    public function testAcceptsSmallScaleEmploymentAndHistoricalInputs(): void
    {
        $result = (new PayrollEmploymentValidator())->create([
            'code' => 'ZMR-2026-01',
            'relation_type' => 'small_scale_employment',
            'monthly_gross_minor' => 450000,
            'terms' => $this->terms(),
        ]);

        self::assertSame('small_scale_employment', $result['relation_type']);
        self::assertSame('20.00', $result['terms']['weekly_hours']);
        self::assertSame('CZ', $result['terms']['foreign_legislation_country_code']);
        self::assertTrue($result['terms']['is_primary']);
    }

    public function testForeignModeRequiresCountry(): void
    {
        $terms = $this->terms();
        $terms['foreign_legislation_country_code'] = null;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kód státu');
        (new PayrollEmploymentValidator())->terms($terms);
    }

    public function testRejectsInvalidDatesAndWorkload(): void
    {
        $terms = $this->terms();
        $terms['fixed_term_end_on'] = '2025-12-31';
        $terms['workload_basis_points'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        (new PayrollEmploymentValidator())->terms($terms);
    }

    public function testInitialCreateCannotBypassActualStartTransition(): void
    {
        $terms = $this->terms();
        $terms['actual_start_on'] = '2026-01-01';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Skutečný nástup');
        (new PayrollEmploymentValidator())->create([
            'code' => 'HPP-1',
            'relation_type' => 'employment',
            'monthly_gross_minor' => 4000000,
            'terms' => $terms,
        ]);
    }

    /** @return array<string,mixed> */
    private function terms(): array
    {
        return [
            'office_id' => 1,
            'effective_from' => '2026-01-01',
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2026-01-01',
            'actual_start_on' => null,
            'fixed_term_end_on' => '2026-12-31',
            'weekly_hours' => '20',
            'workload_basis_points' => 5000,
            'work_place' => 'Praha',
            'regular_workplace' => 'Praha',
            'cz_isco_code' => '43110',
            'activity_code' => '1',
            'social_insurance_participation' => 'foreign',
            'health_insurance_participation' => 'foreign',
            'tax_regime' => 'foreign',
            'foreign_legislation_country_code' => 'cz',
            'a1_certificate_until' => '2026-12-31',
            'risky_work' => false,
            'tax_declaration_signed' => true,
            'is_primary' => true,
            'change_reason' => 'Počáteční podmínky',
        ];
    }
}
