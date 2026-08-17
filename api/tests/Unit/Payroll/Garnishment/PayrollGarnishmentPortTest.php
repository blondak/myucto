<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseSource;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeItem;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Garnishment\RepositoryPayrollGarnishmentPort;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

final class PayrollGarnishmentPortTest extends TestCase
{
    public function testBuildsPersonMonthResultFromTenantScopedEvidence(): void
    {
        $source = new class implements EnforcementCaseSource {
            /** @var list<list<mixed>> */
            public array $calls = [];

            public function evidenceFor(
                int $supplierId,
                int $employeeId,
                string $period,
                string $paymentDate,
            ): EnforcementPersonMonthEvidence {
                $this->calls[] = func_get_args();

                return new EnforcementPersonMonthEvidence(
                    claims: [
                        new DeductionClaim(
                            'claim-synthetic',
                            DeductionLegalBasis::Statutory,
                            ClaimCategory::NonPriority,
                            10_000_000,
                            '2026-01-15',
                            true,
                            true,
                            '2022-01-02',
                            true,
                            enforcementOrderId: 'order-synthetic',
                            dueMonetaryClaimVerified: true,
                        ),
                    ],
                    eligibleDependants: 0,
                    dependantsEvidenceComplete: true,
                    eligibleSpouse: false,
                    spouseEvidenceComplete: true,
                    pensionEvidence: PensionEvidence::None,
                    hasMultiplePayers: false,
                    protectedAmountOverrideMinorUnits: null,
                    protectedAmountOverrideVerified: false,
                    claimRegisterEvidenceComplete: true,
                    insolvency: InsolvencyInstruction::none(),
                );
            }
        };
        $port = new RepositoryPayrollGarnishmentPort(
            $source,
            new GarnishmentCalculator(CzechPayrollRulesets2026::provider()),
        );

        $calculation = $port->calculate(new EnforcementPersonMonthRequest(
            supplierId: 17,
            employeeId: 29,
            period: '2026-06',
            paymentDate: '2026-07-15',
            incomeItems: [
                new GarnishableIncomeItem('wage', GarnishableIncomeKind::Wage, 4_000_000, 'employer-17'),
                new GarnishableIncomeItem(
                    'travel',
                    GarnishableIncomeKind::TravelReimbursement,
                    120_000,
                    'employer-17',
                ),
            ],
            incomeEvidenceComplete: true,
        ));

        self::assertSame([[17, 29, '2026-06', '2026-07-15']], $source->calls);
        self::assertSame(GarnishmentStatus::Supported, $calculation->result->status);
        self::assertSame(
            4_000_000,
            $calculation->result->garnishableIncomeMinorUnits,
        );
        self::assertSame(863_200, $calculation->result->totalWithheldMinorUnits);
        $snapshot = $calculation->inputSnapshot();
        self::assertSame('2026-07-15', $snapshot['payment_date']);
        $claims = PayrollTimeValue::rows($snapshot['claims'] ?? null, 'claims');
        self::assertCount(1, $claims);
        self::assertSame('claim-synthetic', $claims[0]['id']);
        $evidence = PayrollTimeValue::row($snapshot['evidence'] ?? null, 'evidence');
        self::assertTrue($evidence['claim_register_complete']);
    }

    /**
     * Osoba S aktivní pohledávkou a nedoloženým rejstříkem se automaticky
     * srazit nesmí. Pohledávka je tu podstatná: rozsah evidence se od commitu
     * „exekuční evidence se vyžaduje tam, kde je co dokládat" váže na to, jestli
     * je co srážet — u prázdného rejstříku by tenhle scénář (správně) prošel.
     */
    public function testIncompleteRepositoryEvidenceCannotCreateAutomaticDeduction(): void
    {
        $source = new class implements EnforcementCaseSource {
            public function evidenceFor(
                int $supplierId,
                int $employeeId,
                string $period,
                string $paymentDate,
            ): EnforcementPersonMonthEvidence {
                return new EnforcementPersonMonthEvidence(
                    claims: [
                        new DeductionClaim(
                            'claim-synthetic',
                            DeductionLegalBasis::Statutory,
                            ClaimCategory::NonPriority,
                            10_000_000,
                            '2026-01-15',
                            true,
                            true,
                            '2022-01-02',
                            true,
                            enforcementOrderId: 'order-synthetic',
                            dueMonetaryClaimVerified: true,
                        ),
                    ],
                    eligibleDependants: 0,
                    dependantsEvidenceComplete: false,
                    eligibleSpouse: false,
                    spouseEvidenceComplete: false,
                    pensionEvidence: PensionEvidence::Unknown,
                    hasMultiplePayers: false,
                    protectedAmountOverrideMinorUnits: null,
                    protectedAmountOverrideVerified: false,
                    claimRegisterEvidenceComplete: false,
                    insolvency: InsolvencyInstruction::none(),
                );
            }
        };

        $calculation = (new RepositoryPayrollGarnishmentPort(
            $source,
            new GarnishmentCalculator(CzechPayrollRulesets2026::provider()),
        ))->calculate(new EnforcementPersonMonthRequest(
            1,
            2,
            '2026-06',
            '2026-07-15',
            [new GarnishableIncomeItem(
                'wage',
                GarnishableIncomeKind::Wage,
                4_000_000,
                'employer-1',
            )],
            true,
        ));

        self::assertSame(GarnishmentStatus::ManualReview, $calculation->result->status);
        self::assertSame(0, $calculation->result->totalWithheldMinorUnits);
        self::assertContains(
            'claim_register_evidence_incomplete',
            $calculation->result->issues,
        );
    }
}
