<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionSummaryContract as Contract;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTaxSubmissionSummaryContractTest extends TestCase
{
    public function testAcceptsCurrentRegularAndFollowUpSummariesWithoutRewritingBusinessValues(): void
    {
        $regular = self::summary();
        $json = json_encode($regular, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        Contract::assertRow(['form_code' => 'dphshv', 'summary_json' => $json]);
        self::assertSame(123.45, json_decode($json, true, 512, JSON_THROW_ON_ERROR)['total_amount']);

        $followUp = self::summary();
        $followUp['period'] = '2026-Q3';
        $followUp['variant'] = 'nasledne';
        $followUp['shvies_forma'] = 'N';
        $followUp['is_follow_up'] = true;
        $followUp['d_zjist'] = '2026-09-13';
        $followUp['reference_submission_id'] = 41;
        $followUp['storno_rows'] = 1;
        $followUp['rows'] = [];
        $followUp['rows_count'] = 0;
        Contract::assertRow([
            'form_code' => 'dphshv',
            'summary_json' => json_encode($followUp, JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(41, $followUp['reference_submission_id']);
    }

    public function testRejectsUnknownFormAndUnrecognizedCurrentOrHistoricShapes(): void
    {
        foreach (['dphdp3', 'dphkh1', 'ossei1', 'dpfdp7', 'dppdp9', 'osvc25', 'dpfdp5'] as $form) {
            $this->assertInvalid(['form_code' => $form, 'summary_json' => '{}'],
                'data_tax_submission_summary_unsupported');
        }
        $this->assertInvalid(['form_code' => 'dphshv', 'summary_json' => null]);
        $this->assertInvalid(['form_code' => 'dphshv', 'summary_json' => '[]']);
        $this->assertInvalid(['form_code' => 'dphshv', 'summary_json' => '{not-json}']);
        $this->assertInvalid(['form_code' => 'dphshv', 'summary_json' => '{}']);

        $unknown = self::summary();
        $unknown['other_id'] = 9;
        $this->assertInvalidSummary($unknown);
        unset($unknown['other_id']);
        unset($unknown['reference_submission_id']);
        $this->assertInvalidSummary($unknown);

        $unknown = self::summary();
        $unknown['rows'][0]['invoice_id'] = 9;
        $this->assertInvalidSummary($unknown);
        unset($unknown['rows'][0]['invoice_id']);
        $unknown['rows'][0]['amount'] = '123.45';
        $this->assertInvalidSummary($unknown);
        $overflow = str_replace('123.45', '1e400',
            json_encode(self::summary(), JSON_THROW_ON_ERROR));
        $this->assertInvalid(['form_code' => 'dphshv', 'summary_json' => $overflow]);
    }

    public function testChecksNullableReferenceAndProducerVariantPairing(): void
    {
        $summary = self::summary();
        $summary['reference_submission_id'] = 0;
        $this->assertInvalidSummary($summary);
        $summary['reference_submission_id'] = '41';
        $this->assertInvalidSummary($summary);
        $summary['reference_submission_id'] = null;
        $summary['shvies_forma'] = 'N';
        $this->assertInvalidSummary($summary);
        $summary['shvies_forma'] = 'R';
        $summary['is_follow_up'] = true;
        $this->assertInvalidSummary($summary);
    }

    public function testDeclaresSingleNullableSelfReferenceForExistingMetadataParser(): void
    {
        $references = Contract::embeddedReferences();
        self::assertCount(1, $references);
        $reference = CompanyBackupEmbeddedReference::fromArray($references[0], 'table:tax_submissions');
        self::assertSame('summary_json:reference_submission_id->tax_submissions:id', $reference->signature());
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $reference->mapping);
        self::assertTrue($reference->nullable);
    }

    /** @return array<string,mixed> */
    private static function summary(): array
    {
        return [
            'period' => '2026-09',
            'rows_count' => 1,
            'total_amount' => 123.45,
            'rows' => [[
                'country_iso2' => 'DE',
                'k_stat' => 'DE',
                'vat_id' => 'SYNTHETIC',
                'sh_type' => '3',
                'amount' => 123.45,
                'count' => 1,
                'counterparty_name' => 'Synthetic Company',
            ]],
            'submission_deadline' => '2026-10-26',
            'variant' => 'radne',
            'shvies_forma' => 'R',
            'is_follow_up' => false,
            'd_zjist' => null,
            'storno_rows' => 0,
            'reference_submission_id' => null,
        ];
    }

    /** @param array<string,mixed> $summary */
    private function assertInvalidSummary(array $summary): void
    {
        $json = json_encode($summary, JSON_THROW_ON_ERROR);
        $this->assertInvalid(['form_code' => 'dphshv', 'summary_json' => $json]);
    }

    /** @param array<string,mixed> $row */
    private function assertInvalid(array $row, string $expected = 'data_tax_submission_summary_invalid'): void
    {
        try {
            Contract::assertRow($row);
            self::fail('Neznámý souhrn se nesmí přenést beze změny ID.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame($expected, $e->errorCode);
        }
    }
}
