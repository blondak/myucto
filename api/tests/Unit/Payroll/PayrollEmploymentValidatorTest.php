<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use PHPUnit\Framework\TestCase;

final class PayrollEmploymentValidatorTest extends TestCase
{
    public function testAcceptsSmallScaleEmploymentAndHistoricalInputs(): void
    {
        $result = $this->validator()->create([
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
        $this->validator()->terms($terms);
    }

    public function testRejectsInvalidDatesAndWorkload(): void
    {
        $terms = $this->terms();
        $terms['fixed_term_end_on'] = '2025-12-31';
        $terms['workload_basis_points'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->validator()->terms($terms);
    }

    public function testInitialCreateCannotBypassActualStartTransition(): void
    {
        $terms = $this->terms();
        $terms['actual_start_on'] = '2026-01-01';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Skutečný nástup');
        $this->validator()->create([
            'code' => 'HPP-1',
            'relation_type' => 'employment',
            'monthly_gross_minor' => 4000000,
            'terms' => $terms,
        ]);
    }

    public function testAcceptsCompleteJmhzCoreEvidenceAndCanonicalizesIt(): void
    {
        $terms = $this->terms();
        $terms['work_place'] = '  Hlavní město Praha  ';
        $terms['jmhz_workplace_municipality_code'] = '554782';
        $terms['jmhz_workplace_country_code'] = 'cz';
        $terms['jmhz_apz_contribution_status'] = 'yes';
        $terms['jmhz_apz_instrument_code'] = '3';
        $terms['jmhz_functional_benefits_status'] = 'no';
        $terms['jmhz_temporary_assignment_status'] = 'unverified';

        $result = $this->validator()->terms($terms);

        self::assertSame('Hlavní město Praha', $result['work_place']);
        self::assertSame('554782', $result['jmhz_workplace_municipality_code']);
        self::assertSame('CZ', $result['jmhz_workplace_country_code']);
        self::assertSame('3', $result['jmhz_apz_instrument_code']);
        self::assertSame(
            JmhzExternalCodebookCatalog::DEFAULT_MANIFEST_SHA256,
            $result['jmhz_external_codebook_manifest_sha256'],
        );
    }

    public function testFutureJmhzEvidenceCanBePlannedButPredatesOverlayCannot(): void
    {
        $future = $this->terms();
        $future['effective_from'] = '2026-09-01';
        $future['work_place'] = 'Hlavní město Praha';
        $future['jmhz_workplace_municipality_code'] = '554782';
        $future['jmhz_workplace_country_code'] = 'CZ';
        self::assertSame('554782', $this->validator()->terms($future)['jmhz_workplace_municipality_code']);

        $past = $future;
        $past['effective_from'] = '2025-12-31';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nejsou pro datum');
        $this->validator()->terms($past);
    }

    public function testRejectsPartialWorkplaceAndUnknownApzCode(): void
    {
        $partial = $this->terms();
        $partial['work_place'] = 'Praha';
        $partial['jmhz_workplace_municipality_code'] = null;
        $partial['jmhz_workplace_country_code'] = 'CZ';

        try {
            $this->validator()->terms($partial);
            self::fail('Neúplné pracoviště musí být odmítnuto.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Pracoviště JMHZ', $e->getMessage());
        }

        $apz = $this->terms();
        $apz['jmhz_apz_contribution_status'] = 'yes';
        $apz['jmhz_apz_instrument_code'] = '9';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nástroje APZ');
        $this->validator()->terms($apz);
    }

    public function testRequiresExplicitTriStateAndClearsNoApzCode(): void
    {
        $missing = $this->terms();
        unset($missing['jmhz_functional_benefits_status']);
        try {
            $this->validator()->terms($missing);
            self::fail('Chybějící tri-state nesmí být vyložen jako ne.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('jmhz_functional_benefits_status', $e->getMessage());
        }

        $no = $this->terms();
        $no['jmhz_apz_contribution_status'] = 'no';
        $no['jmhz_apz_instrument_code'] = '1';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Bez příspěvku APZ');
        $this->validator()->terms($no);
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
            'jmhz_workplace_municipality_code' => null,
            'jmhz_workplace_country_code' => null,
            'jmhz_apz_contribution_status' => 'unverified',
            'jmhz_apz_instrument_code' => null,
            'jmhz_functional_benefits_status' => 'unverified',
            'jmhz_temporary_assignment_status' => 'unverified',
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

    private function validator(): PayrollEmploymentValidator
    {
        return new PayrollEmploymentValidator(
            new PayrollEmploymentJmhzEvidenceCatalog(
                new JmhzSpecPackageCatalog(),
                new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog()),
            ),
        );
    }
}
