<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Payroll;

use MyInvoice\Action\Accounting\PayrollEmployeeAction;
use PHPUnit\Framework\TestCase;

/**
 * Nesoulad pracovněprávního vztahu a typu poplatníka se HLÁSÍ, ale NEBLOKUJE.
 *
 * `employment_type` (§ 59 ZOK vs. dohoda vs. pracovní poměr) a `taxpayer_type`
 * (kontace 521/331 vs. 522/366) popisují dvě různé věci. Vynutit jedno druhým by
 * z jednoho pole udělalo dvě autority nad týmž faktem — a kombinace „výkon funkce
 * + zaměstnanec" může legitimně vzniknout u jednatele, který má u téže firmy
 * i pracovní poměr.
 */
final class PayrollEmployeeConsistencyWarningsTest extends TestCase
{
    public function testStatutoryBodyWithEmployeeTaxpayerTypeIsWarnedNotBlocked(): void
    {
        $warnings = PayrollEmployeeAction::consistencyWarnings([
            'employment_type' => 'statutory_body',
            'taxpayer_type' => 'employee',
        ]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('522/366', $warnings[0]);
    }

    public function testStatutoryBodyWithManagingPartnerIsSilent(): void
    {
        self::assertSame([], PayrollEmployeeAction::consistencyWarnings([
            'employment_type' => 'statutory_body',
            'taxpayer_type' => 'managing_partner',
        ]));
    }

    /**
     * Opačný směr se NEHLÁSÍ: jednatel-společník s pracovním poměrem nebo dohodou je
     * běžný stav (odměna za výkon funkce se u něj eviduje jinde), takže by varování
     * bylo jen šum.
     */
    public function testOtherEmploymentTypesNeverWarn(): void
    {
        foreach (['hpp', 'dpp', 'dpc'] as $type) {
            foreach (['employee', 'managing_partner'] as $taxpayer) {
                self::assertSame([], PayrollEmployeeAction::consistencyWarnings([
                    'employment_type' => $type,
                    'taxpayer_type' => $taxpayer,
                ]), "{$type} × {$taxpayer} nemá co hlásit.");
            }
        }
    }

    /** Chybějící klíče (starší karta, částečný update) nesmí spadnout ani vyrobit šum. */
    public function testMissingKeysFallBackToDefaults(): void
    {
        self::assertSame([], PayrollEmployeeAction::consistencyWarnings([]));
    }
}
