<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionXmlEvidence;
use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionSummaryContract;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTaxSubmissionXmlEvidenceTest extends TestCase
{
    public function testCompleteRowInspectionChecksXmlEvidenceBeforeReferences(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition(new TenantDataDefinition(
            'table:tax_submissions', TenantDataObjectKind::Table, TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => ['id', 'xml_content', 'xml_size_bytes', 'xml_sha256', 'form_code', 'summary_json'],
                    'generated_columns' => [], 'omit_columns' => [],
                    'embedded_references' => CompanyBackupTaxSubmissionSummaryContract::embeddedReferences(),
                    'references' => [], 'restore_overrides' => [],
                ],
            ],
        ));
        $row = ['id' => 1, ...self::evidence('<x>A</x>'), 'form_code' => 'dphshv',
            'summary_json' => json_encode([
                'period' => '2026-09', 'rows_count' => 0, 'total_amount' => 0,
                'rows' => [], 'submission_deadline' => '2026-10-26',
                'variant' => 'radne', 'shvies_forma' => 'R', 'is_follow_up' => false,
                'd_zjist' => null, 'storno_rows' => 0, 'reference_submission_id' => null,
            ], JSON_THROW_ON_ERROR),
        ];
        $projection->assertCompleteSourceRow($row);
        self::assertSame('<x>A</x>', $row['xml_content']);
        $row['xml_content'] = '<x>B</x>';
        try {
            $projection->assertCompleteSourceRow($row);
            self::fail('Společná kontrola řádku musí odmítnout změněné XML.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('tax_submission_xml_sha256_mismatch', $e->errorCode);
        }
    }

    public function testAcceptsExactByteSnapshotsIncludingEmptyAndMultibyteXml(): void
    {
        foreach (['', "<?xml version=\"1.0\"?>\r\n<Podani>ě🙂</Podani>\n", "<x>\0</x>"] as $xml) {
            $row = self::evidence($xml);
            CompanyBackupTaxSubmissionXmlEvidence::assertRow($row);
            self::assertSame($xml, $row['xml_content']);
            self::assertSame(strlen($xml), $row['xml_size_bytes']);
        }
    }

    /** @return iterable<string,array{array<string,mixed>,string,string}> */
    public static function invalidEvidence(): iterable
    {
        $valid = self::evidence("<x>\r\ně</x>\n");
        yield 'missing content' => [array_diff_key($valid, ['xml_content' => true]), 'tax_submission_xml_content_invalid', 'xml_content'];
        $row = $valid;
        $row['xml_content'] = ['not XML'];
        yield 'non-string content' => [$row, 'tax_submission_xml_content_invalid', 'xml_content'];
        yield 'missing size' => [array_diff_key($valid, ['xml_size_bytes' => true]), 'tax_submission_xml_size_invalid', 'xml_size_bytes'];
        foreach ([-1, '11', 11.0, null, true] as $size) {
            $row = $valid;
            $row['xml_size_bytes'] = $size;
            yield 'invalid size ' . get_debug_type($size) . ':' . (string) $size => [$row, 'tax_submission_xml_size_invalid', 'xml_size_bytes'];
        }
        yield 'missing sha' => [array_diff_key($valid, ['xml_sha256' => true]), 'tax_submission_xml_sha256_invalid', 'xml_sha256'];
        foreach ([null, 123, '', str_repeat('a', 63), str_repeat('G', 64), strtoupper($valid['xml_sha256'])] as $hash) {
            $row = $valid;
            $row['xml_sha256'] = $hash;
            yield 'invalid hash ' . get_debug_type($hash) . ':' . (string) $hash => [$row, 'tax_submission_xml_sha256_invalid', 'xml_sha256'];
        }
        $row = $valid;
        $row['xml_size_bytes']++;
        yield 'different byte length' => [$row, 'tax_submission_xml_size_mismatch', 'xml_size_bytes'];
        $row = $valid;
        $row['xml_content'] = str_replace("\r\n", "\n\n", $row['xml_content']);
        yield 'same length changed bytes' => [$row, 'tax_submission_xml_sha256_mismatch', 'xml_sha256'];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('invalidEvidence')]
    public function testRejectsInvalidOrChangedEvidenceWithoutLeakingXml(
        array $row, string $code, string $column,
    ): void {
        try {
            CompanyBackupTaxSubmissionXmlEvidence::assertRow($row);
            self::fail('Změněný nebo neplatný důkazní snapshot nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame('table:tax_submissions', $e->registryKey);
            self::assertSame($column, $e->column);
            self::assertStringNotContainsString('ě', $e->getMessage());
        }
    }

    /** @return array{xml_content:string,xml_size_bytes:int,xml_sha256:string} */
    private static function evidence(string $xml): array
    {
        return [
            'xml_content' => $xml,
            'xml_size_bytes' => strlen($xml),
            'xml_sha256' => hash('sha256', $xml),
        ];
    }
}
