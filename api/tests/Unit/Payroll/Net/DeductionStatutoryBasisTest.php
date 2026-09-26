<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\Net\DeductionPriorityResolver;
use MyInvoice\Service\Payroll\Net\PayrollDeductionRequest;
use PHPUnit\Framework\TestCase;

/**
 * CYK-B16d — srážky ze mzdy podle § 147 odst. 1 písm. c) až e) zákoníku práce.
 *
 * Záloha na mzdu k vrácení, nevyúčtovaná záloha a náhrada mzdy, na kterou
 * nevzniklo právo, se srážejí BEZ dohody. Dřív se evidovaly jako dobrovolná
 * dohoda: bez „dne doručení dohody" spadly v pořadí za všechny exekuce
 * a dohody a při shodném dni rozhodovalo ruční pořadí dohody.
 */
final class DeductionStatutoryBasisTest extends TestCase
{
    public function testStatutoryDeductionRequiresTheDayDeductionsStarted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('den zahájení srážek');

        DeductionAgreementTerms::fromRequest($this->body(['legal_basis' => 'zp_147_1_c']));
    }

    public function testDamageCanOnlyBeDeductedByAgreement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('§ 147 odst. 3');

        DeductionAgreementTerms::fromRequest($this->body([
            'legal_basis' => 'zp_147_1_d',
            'deduction_kind' => 'damage',
            'delivered_on' => '2026-03-01',
        ]));
    }

    public function testUnknownLegalBasisIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeductionAgreementTerms::fromRequest($this->body(['legal_basis' => 'court_order']));
    }

    public function testStatutoryBasisIsKept(): void
    {
        $terms = DeductionAgreementTerms::fromRequest($this->body([
            'legal_basis' => 'zp_147_1_e',
            'deduction_kind' => 'other',
            'delivered_on' => '2026-03-01',
        ]));

        self::assertSame('zp_147_1_e', $terms->legalBasis);
        self::assertSame('agreement', DeductionAgreementTerms::fromRequest($this->body())->legalBasis);
    }

    /**
     * Týž den zahájení / doručení: zákonná srážka má přednost před dohodou
     * (výčet § 147 odst. 1 ZP), i když dohoda má nižší ruční pořadí.
     */
    public function testStatutoryDeductionPrecedesAgreementOfTheSameDay(): void
    {
        $results = (new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('agreement:1', 10, 300_000, null, true, '2026-03-01'),
            new PayrollDeductionRequest('agreement:2', 50, 300_000, null, true, '2026-03-01', 'zp_147_1_c'),
        ], 300_000);

        $applied = [];
        foreach ($results as $result) {
            $applied[$result->deductionReference] = $result->appliedMinorUnits;
        }
        self::assertSame(['agreement:2' => 300_000, 'agreement:1' => 0], $applied);
    }

    /** Uvnitř zákonných titulů platí pořadí výčtu: písm. c) před d) před e). */
    public function testStatutoryTitlesFollowTheOrderOfSection147(): void
    {
        $results = (new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('agreement:7', 10, 200_000, null, true, '2026-03-01', 'zp_147_1_e'),
            new PayrollDeductionRequest('agreement:8', 20, 200_000, null, true, '2026-03-01', 'zp_147_1_d'),
        ], 250_000);

        $applied = [];
        foreach ($results as $result) {
            $applied[$result->deductionReference] = $result->appliedMinorUnits;
        }
        self::assertSame(['agreement:8' => 200_000, 'agreement:7' => 50_000], $applied);
    }

    /** Dřívější den pořád vyhrává nad titulem — pořadí je dnem, ne druhem. */
    public function testEarlierAgreementStillPrecedesLaterStatutoryDeduction(): void
    {
        $results = (new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('agreement:1', 10, 300_000, null, true, '2026-01-10'),
            new PayrollDeductionRequest('agreement:2', 10, 300_000, null, true, '2026-03-01', 'zp_147_1_c'),
        ], 300_000);

        self::assertSame(300_000, $results[0]->appliedMinorUnits);
        self::assertSame('agreement:1', $results[0]->deductionReference);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'title' => 'Nevyúčtovaná záloha',
            'deduction_kind' => 'advance',
            'priority_no' => 100,
            'requested_minor' => 300_000,
            'valid_from' => '2026-03-01',
            ...$overrides,
        ];
    }
}
