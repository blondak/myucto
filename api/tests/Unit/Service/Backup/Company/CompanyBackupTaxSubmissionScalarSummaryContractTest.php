<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionScalarSummaryContract as Contract;
use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionSummaryContract;
use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTaxSubmissionScalarSummaryContractTest extends TestCase
{
    public function testKhNormalAndQuickReplyVariants(): void
    {
        foreach ([
            'radne' => ['B', false], 'opravne' => ['O', false],
            'nasledne' => ['N', true], 'nasledne_opravne' => ['E', true],
        ] as $variant => [$forma, $followUp]) {
            $summary = self::kh();
            $summary->variant = $variant;
            $summary->khdph_forma = $forma;
            $summary->is_follow_up = $followUp;
            Contract::assertSummary('dphkh1', $summary);
            self::assertSame($forma, $summary->khdph_forma);
        }
        foreach ([
            'vyzva_nulove' => ['B', 'B', false],
            'vyzva_potvrzeni' => ['N', 'P', true],
        ] as $variant => [$forma, $answer, $followUp]) {
            $summary = self::kh();
            $summary->variant = $variant;
            $summary->khdph_forma = $forma;
            $summary->is_follow_up = $followUp;
            $summary->c_jed_vyzvy = '12345678/12/3456-12345-123456';
            $summary->is_vyzva_odpoved = true;
            $summary->vyzva_odp = $answer;
            Contract::assertSummary('dphkh1', $summary);
            self::assertSame($answer, $summary->vyzva_odp);
        }
    }

    public function testIncomeTaxSummariesAcceptFiniteIntsAndFloatsWithoutRecalculation(): void
    {
        foreach (['dpfdp7' => self::dpfo(), 'dppdp9' => self::dppo()] as $form => $summary) {
            foreach (['radne', 'opravne', 'dodatecne'] as $variant) {
                $summary->variant = $variant;
                Contract::assertSummary($form, $summary);
                self::assertSame(123.45, $summary->balance_due);
            }
        }
    }

    public function testCurrentIncomeTaxCalculatorSummariesMatchBackupContract(): void
    {
        $constants = TaxConstants::forYear(2025);
        $results = [
            'dpfdp7' => (new DpfoReturnCalculator())->compute(
                ['s7_base' => 1000, 'expense_mode' => 'actual', 'expense_rate' => 0],
                [], [], $constants,
            ),
            'dppdp9' => (new DppoReturnCalculator())->compute(
                ['vh' => 1000], [], $constants,
            ),
        ];
        foreach ($results as $form => $result) {
            // Stejný obal jako TaxReturnService::buildXml() nad computed.summary.
            $summary = $result['summary'];
            $summary['variant'] = 'radne';
            $summary['warnings'] = $result['warnings'];
            $json = json_encode($summary, JSON_THROW_ON_ERROR);
            CompanyBackupTaxSubmissionSummaryContract::assertRow([
                'form_code' => $form, 'summary_json' => $json,
            ]);
            self::assertSame(array_keys($summary), array_keys(json_decode($json, true, 512, JSON_THROW_ON_ERROR)));
        }
    }

    public function testOsvcSummaryHasOnlySocialInsuranceAndStringWarnings(): void
    {
        $summary = (object) ['insurance' => 'social', 'warnings' => ['Synthetic warning']];
        Contract::assertSummary('osvc25', $summary);
        self::assertTrue(Contract::supports('osvc25'));
        self::assertFalse(Contract::supports('dpfdp5'));
    }

    public function testUnknownKeysAndMalformedNestedOrVariantValuesFailClosed(): void
    {
        foreach ([
            ['dphkh1', self::kh(), 'unknown_id', 7],
            ['dpfdp7', self::dpfo(), 'invoice_id', 7],
            ['dppdp9', self::dppo(), 'tax_return_id', 7],
            ['osvc25', (object) ['insurance' => 'social', 'warnings' => []], 'other', 1],
        ] as [$form, $summary, $key, $value]) {
            $summary->$key = $value;
            $this->assertInvalid($form, $summary);
        }

        $kh = self::kh();
        unset($kh->a1_count);
        $this->assertInvalid('dphkh1', $kh);
        $kh = self::kh();
        $kh->variant = 'vyzva_nulove';
        $this->assertInvalid('dphkh1', $kh);
        $kh = self::kh();
        $kh->is_follow_up = true;
        $this->assertInvalid('dphkh1', $kh);
        $kh = self::kh();
        $kh->a1_count = '1';
        $this->assertInvalid('dphkh1', $kh);

        $dpfo = self::dpfo();
        $dpfo->balance_due = INF;
        $this->assertInvalid('dpfdp7', $dpfo);
        $dpfo = self::dpfo();
        $dpfo->warnings = [(object) ['message' => 'not a string']];
        $this->assertInvalid('dpfdp7', $dpfo);
        $dppo = self::dppo();
        $dppo->credits = '100';
        $this->assertInvalid('dppdp9', $dppo);
        $this->assertInvalid('osvc25', (object) ['insurance' => 'health', 'warnings' => []]);
        $this->assertInvalid('osvc25', (object) ['insurance' => 'social', 'warnings' => [12]]);

        try {
            Contract::assertSummary('unknown_form', self::kh());
            self::fail('Neznámý formulář nesmí projít.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_tax_submission_summary_unsupported', $e->errorCode);
        }
    }

    private static function kh(): \stdClass
    {
        return (object) [
            'period' => '2026-09',
            'a1_count' => 0, 'a2_count' => 0, 'a4_count' => 1,
            'a5_count_aggregated' => 0, 'b1_count' => 0,
            'b2_count' => 0, 'b3_count_aggregated' => 0,
            'submission_deadline' => '2026-10-26',
            'variant' => 'radne', 'khdph_forma' => 'B',
            'is_follow_up' => false, 'd_zjist' => null, 'c_jed_vyzvy' => null,
        ];
    }

    private static function dpfo(): \stdClass
    {
        return (object) [
            'total_base' => 1000, 'rounded_base' => 1000,
            'tax16' => 150, 'tax_after_credits' => 150,
            'child_bonus' => 0, 'child_credit' => 0,
            'spouse_credit' => 0, 'bonus_qualifying_income' => 0,
            'final_tax' => 150, 'balance_due' => 123.45,
            'separate_base' => 0, 'separate_base_tax' => 0,
            's7_profit' => 1000, 'uhrn_710' => 1000,
            'loss_applied' => 0, 'year_tax_loss' => 0,
            'variant' => 'radne', 'warnings' => ['Synthetic warning'],
        ];
    }

    private static function dppo(): \stdClass
    {
        return (object) [
            'rate' => 0.21, 'vh' => 1000, 'base' => 1000,
            'rounded_base' => 1000, 'tax_gross' => 210,
            'credits' => 0, 'credits_entitlement' => 0,
            'disabled_employee_credit_amount' => 0,
            'disabled_employee_severe_credit_amount' => 0,
            'disabled_employees_avg' => 0,
            'disabled_employees_severe_avg' => 0,
            'total_tax' => 210, 'balance_due' => 123.45,
            'loss_applied' => 0, 'rnd_applied' => 0,
            'education_applied' => 0, 'donation_applied' => 0,
            'variant' => 'radne', 'warnings' => [],
        ];
    }

    private function assertInvalid(string $form, \stdClass $summary): void
    {
        try {
            Contract::assertSummary($form, $summary);
            self::fail('Souhrn s neznámým tvarem nesmí projít.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_tax_submission_summary_invalid', $e->errorCode);
        }
    }
}
