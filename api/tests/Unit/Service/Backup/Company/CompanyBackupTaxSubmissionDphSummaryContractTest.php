<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionDphSummaryContract;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTaxSubmissionDphSummaryContractTest extends TestCase
{
    public function testAcceptsCurrentMonthlyQuarterlyAmendmentAndAnnualShapes(): void
    {
        self::expectNotToPerformAssertions();
        $regular = self::summary();
        CompanyBackupTaxSubmissionDphSummaryContract::assertSummary($regular);

        $quarterly = self::summary();
        $quarterly->period_type = 'quarterly';
        $quarterly->quarter = 4;
        $quarterly->lines = (object) [
            '40k' => self::line(), '51b' => self::line(),
            'unknown!' => self::line(), '' => self::line(),
        ];
        $quarterly->vat_settlement = (object) [
            'final_percent' => 95, 'numerator' => 1000,
            'denominator' => 1050, 'vypor_odp' => 1.5,
        ];
        CompanyBackupTaxSubmissionDphSummaryContract::assertSummary($quarterly);

        $amendment = self::summary();
        $amendment->variant = 'dodatecne';
        $amendment->dapdph_forma = 'D';
        $amendment->is_amendment = true;
        $amendment->d_zjist = '2026-02-04';
        $amendment->reference_submission_id = 42;
        $amendment->last_known_tax = 200;
        $amendment->tax_difference = -12.5;
        $amendment->document_refs = [self::document('sale'),
            self::document('purchase'), self::document('cash')];
        CompanyBackupTaxSubmissionDphSummaryContract::assertSummary($amendment);

        // Numerický klíč "0" může PHP json_encode zapsat jako seznam.
        $listLines = self::summary();
        $listLines->lines = [self::line()];
        CompanyBackupTaxSubmissionDphSummaryContract::assertSummary($listLines);
    }

    public function testRejectsUnknownDocumentKey(): void
    {
        $summary = self::summary();
        $summary->document_refs[0]->unknown_id = 99;
        self::assertInvalid($summary);
    }

    public function testRejectsWrongTopLevelAndNestedShapes(): void
    {
        $base = self::summary();
        $cases = [];

        $case = clone $base;
        $case->unknown_id = 1;
        $cases[] = $case;
        $case = clone $base;
        unset($case->period_type);
        $cases[] = $case;
        $case = clone $base;
        $case->reference_submission_id = 0;
        $cases[] = $case;
        $case = clone $base;
        $case->reference_submission_id = '12';
        $cases[] = $case;
        $case = clone $base;
        $case->document_refs = (object) ['0' => self::document('sale')];
        $cases[] = $case;
        $case = clone $base;
        $case->document_refs = [self::document('other')];
        $cases[] = $case;
        $case = clone $base;
        $case->document_refs = [self::document('sale')];
        $case->document_refs[0]->invoice_id = -1;
        $cases[] = $case;
        $case = clone $base;
        $case->lines = (object) ['1' => (object) [...get_object_vars(self::line()), 'extra' => 1]];
        $cases[] = $case;
        $case = clone $base;
        $case->lines = (object) [str_repeat('x', 11) => self::line()];
        $cases[] = $case;
        $case = clone $base;
        $case->lines = (object) ["x\n" => self::line()];
        $cases[] = $case;
        $case = clone $base;
        $case->vat_settlement = (object) [
            'final_percent' => 95, 'numerator' => 1000,
            'denominator' => 1050, 'vypor_odp' => 1.5, 'unknown' => 1,
        ];
        $cases[] = $case;
        $case = clone $base;
        $case->tax_due = '12.50';
        $cases[] = $case;

        foreach ($cases as $case) {
            self::assertInvalid($case);
        }
    }

    private static function assertInvalid(\stdClass $summary): void
    {
        try {
            CompanyBackupTaxSubmissionDphSummaryContract::assertSummary($summary);
            self::fail('Neznámý nebo neplatný tvar DPH souhrnu nesmí projít.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_tax_submission_summary_invalid', $e->errorCode);
            self::assertSame('table:tax_submissions', $e->registryKey);
            self::assertSame('summary_json', $e->column);
        }
    }

    private static function summary(): \stdClass
    {
        return (object) [
            'period' => '2026-01', 'period_type' => 'monthly', 'typ_platce' => 'P',
            'quarter' => null, 'lines' => (object) ['1' => self::line()],
            'total_vat_output' => 21.0, 'total_vat_input' => 10.0,
            'tax_due' => 11.0, 'is_excess_deduction' => false,
            'submission_deadline' => '2026-02-25',
            'variant' => 'radne', 'dapdph_forma' => 'B', 'is_amendment' => false,
            'd_zjist' => null, 'last_known_tax' => null, 'tax_difference' => null,
            'reference_submission_id' => null, 'supplier_vat_period' => 'monthly',
            'document_refs' => [self::document('sale')],
            'vat_reduced_deduction' => 0.0, 'vat_coefficient_percent' => null,
            'vat_reduced_applied' => 0.0, 'vat_settlement' => null,
        ];
    }

    private static function line(): \stdClass
    {
        return (object) ['base' => 100.0, 'vat' => 21.0, 'count' => 1, 'label' => 'Syntetický řádek'];
    }

    private static function document(string $source): \stdClass
    {
        return (object) [
            'source' => $source, 'invoice_id' => 7,
            'document_kind' => $source === 'cash' ? 'cash' : 'invoice',
            'status' => 'issued', 'tax_date' => '2026-01-15',
            'updated_at' => null, 'total' => 121.0,
        ];
    }
}
