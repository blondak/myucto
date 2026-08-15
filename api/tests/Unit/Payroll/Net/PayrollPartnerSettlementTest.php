<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\PayoutAllocationRequest;
use MyInvoice\Service\Payroll\Net\PayrollPartnerSettlement;
use PHPUnit\Framework\TestCase;

final class PayrollPartnerSettlementTest extends TestCase
{
    public function testSettlementAllocationRequiresSettlementAccountReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vyžadují referenci cíle');
        PayoutAllocationRequest::remainder(
            'partner-settlement',
            PayrollPartnerSettlement::KIND,
            null,
            1,
        );
    }

    public function testSettlementAllocationRejectsEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vyžadují referenci cíle');
        PayoutAllocationRequest::fixed(
            'partner-settlement',
            PayrollPartnerSettlement::KIND,
            '',
            10_000,
            1,
        );
    }

    public function testSettlementAllocationRejectsReferenceThatIsNotAnAccountCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kód účtu zápočtu');
        PayoutAllocationRequest::remainder(
            'partner-settlement',
            PayrollPartnerSettlement::KIND,
            'account:7',
            1,
        );
    }

    public function testSettlementAllocationAcceptsAnalyticAccountCode(): void
    {
        $request = PayoutAllocationRequest::remainder(
            'partner-settlement',
            PayrollPartnerSettlement::KIND,
            '365.100',
            1,
        );

        self::assertSame(PayrollPartnerSettlement::KIND, $request->destinationKind);
        self::assertSame('365.100', $request->destinationReference);
    }

    public function testEligibilityAcceptsPartnerIncomeAndOfficeReward(): void
    {
        PayrollPartnerSettlement::assertEligible(['partner_dependent'], 11);
        PayrollPartnerSettlement::assertEligible(['statutory_body'], 11);
        PayrollPartnerSettlement::assertEligible(['employment', 'statutory_body'], 11);

        self::assertSame(
            ['partner_dependent', 'statutory_body'],
            PayrollPartnerSettlement::RELATION_TYPES,
        );
    }

    public function testEligibilityRefusesOrdinaryEmployee(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Osoba 11 takový pracovní vztah nemá');
        PayrollPartnerSettlement::assertEligible(['employment', 'dpp'], 11);
    }

    public function testEligibilityRefusesPersonWithoutAnyRelation(): void
    {
        $this->expectException(\DomainException::class);
        PayrollPartnerSettlement::assertEligible([], 42);
    }
}
