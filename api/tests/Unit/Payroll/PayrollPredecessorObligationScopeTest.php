<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollPredecessorObligationScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollPredecessorObligationScopeTest extends TestCase
{
    /**
     * @return iterable<string,array{0:?string,1:string,2:string,3:?string,4:?string,5:?string,6:bool}>
     */
    public static function cases(): iterable
    {
        yield 'nástup v březnu, mzdy od září' => ['2026-09', 'social_jmhz_registration', 'onboarding', null, '2026-03-01', null, true];
        yield 'termín v září, nástup v srpnu' => ['2026-09', 'health_insurance_registration', 'onboarding', '2026-09-05', '2026-08-28', null, true];
        yield 'nástup v září' => ['2026-09', 'employment_contract', 'onboarding', '2026-09-10', '2026-09-10', null, false];
        yield 'nástup první den startu' => ['2026-09-01', 'tax_declaration', 'onboarding', '2026-10-01', '2026-09-01', null, false];
        yield 'skončení v květnu' => ['2026-09', 'termination_document', 'offboarding', null, '2013-03-01', '2026-05-31', true];
        yield 'skončení v září' => ['2026-09', 'health_insurance_deregistration', 'offboarding', '2026-09-08', '2026-01-01', '2026-09-30', false];
        yield 'bez začátku vedení mezd' => [null, 'employment_contract', 'onboarding', '2026-03-01', '2026-03-01', null, false];
        yield 'nástup neznámý' => ['2026-09', 'employment_contract', 'onboarding', null, null, null, false];
        yield 'chybějící datum nástupu je údaj, ne povinnost' => ['2026-09', 'legacy_start_date', 'onboarding', null, '2026-03-01', null, false];
        yield 'změna s termínem před startem' => ['2026-09', 'health_insurance_change', 'change', '2026-03-09', '2013-03-01', null, true];
        yield 'změna bez termínu' => ['2026-09', 'contract_amendment', 'change', null, '2013-03-01', null, false];
        yield 'potvrzení bez žádosti po starém skončení' => ['2026-09', 'taxable_income_confirmation', 'offboarding', null, '2026-01-01', '2026-02-28', true];
        yield 'potvrzení na žádost v říjnu' => ['2026-09', 'taxable_income_confirmation', 'offboarding', '2026-10-12', '2026-01-01', '2026-02-28', false];
    }

    public function testChangeBeforeStartIsKnownWhenTheItemIsCreated(): void
    {
        self::assertTrue(PayrollPredecessorObligationScope::handledByPredecessor(
            '2026-09', 'contract_amendment', 'change', null, '2026-01-01', null, '2026-03-01',
        ));
        self::assertFalse(PayrollPredecessorObligationScope::handledByPredecessor(
            '2026-09', 'contract_amendment', 'change', null, '2026-01-01', null, '2026-09-01',
        ));
    }

    #[DataProvider('cases')]
    public function testHandledByPredecessor(
        ?string $startPeriod,
        string $itemKey,
        string $phase,
        ?string $dueOn,
        ?string $startOn,
        ?string $endOn,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            PayrollPredecessorObligationScope::handledByPredecessor($startPeriod, $itemKey, $phase, $dueOn, $startOn, $endOn),
        );
    }
}
