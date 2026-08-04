<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollPersonCreateValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollPersonCreateValidatorTest extends TestCase
{
    /** @return iterable<string,array{string,string,string}> */
    public static function relationMappings(): iterable
    {
        yield 'pracovní poměr' => ['employment', 'employee', 'hpp'];
        yield 'zaměstnání malého rozsahu' => ['small_scale_employment', 'employee', 'hpp'];
        yield 'DPP' => ['dpp', 'employee', 'dpp'];
        yield 'DPČ' => ['dpc', 'employee', 'dpc'];
        yield 'závislý příjem společníka' => ['partner_dependent', 'managing_partner', 'hpp'];
        yield 'výkon funkce' => ['statutory_body', 'managing_partner', 'hpp'];
    }

    #[DataProvider('relationMappings')]
    public function testMapsModernRelationshipToSharedLegacyProjection(
        string $relationType,
        string $taxpayerType,
        string $employmentType,
    ): void {
        $result = (new PayrollPersonCreateValidator(new PayrollEmploymentValidator()))->validate([
            'full_name' => 'Syntetická Osoba',
            'birth_date' => null,
            'birth_number' => null,
            'relation_type' => $relationType,
            'planned_start_on' => '2026-08-10',
            'monthly_gross' => 12_500,
        ]);

        self::assertSame($taxpayerType, $result['employee']['taxpayer_type']);
        self::assertSame($employmentType, $result['employee']['employment_type']);
        self::assertSame(12_500, $result['employee']['monthly_gross']);
        self::assertSame(1_250_000, $result['employment']['monthly_gross_minor']);
        self::assertSame($relationType, $result['employment']['relation_type']);
        self::assertTrue($result['employment']['terms']['is_primary']);
    }
}
