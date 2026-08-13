<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\PayrollPersonCreateValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
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
        // `partner_dependent` protějšek v ENUMu `payroll_employees.employment_type` nemá,
        // takže spadá na `hpp`; kontaci 522/366 mu zajistí `taxpayer_type`.
        yield 'závislý příjem společníka' => ['partner_dependent', 'managing_partner', 'hpp'];
        // Migrace 1302 — do té doby dostal člen statutárního orgánu na legacy kartě
        // „pracovní poměr", což u odměny podle § 6 odst. 1 písm. c) ZDP není pravda.
        // Klíč je v obou větvích SHODNÝ, takže je mapování identita.
        yield 'výkon funkce' => ['statutory_body', 'managing_partner', 'statutory_body'];
    }

    #[DataProvider('relationMappings')]
    public function testMapsModernRelationshipToSharedLegacyProjection(
        string $relationType,
        string $taxpayerType,
        string $employmentType,
    ): void {
        $result = (new PayrollPersonCreateValidator(new PayrollEmploymentValidator(
            new PayrollEmploymentJmhzEvidenceCatalog(
                new JmhzSpecPackageCatalog(),
                new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog()),
            ),
        )))->validate([
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
