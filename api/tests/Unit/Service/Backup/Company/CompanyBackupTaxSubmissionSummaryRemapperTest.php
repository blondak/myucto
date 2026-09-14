<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceRemapDirective;
use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionSummaryContract;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTaxSubmissionSummaryRemapperTest extends TestCase
{
    public function testRemapsOnlyApprovedIdTokenAndPreservesAllOtherBytes(): void
    {
        $raw = self::summary(41);
        $row = ['form_code' => 'dphshv', 'summary_json' => $raw];
        $references = self::references();
        $visited = 0;
        $visitedRow = $references->remap($row,
            static function (CompanyBackupEmbeddedReference $reference, int|string $value) use (&$visited): int {
                $visited++;
                self::assertSame(41, $value);
                return (int) $value;
            });
        self::assertSame($raw, $visitedRow['summary_json']);
        self::assertSame(1, $visited);

        $mapped = $references->remap($row,
            static function (CompanyBackupEmbeddedReference $reference, int|string $value): int {
                self::assertSame('summary_json:reference_submission_id->tax_submissions:id', $reference->signature());
                return (int) $value + 100;
            });
        self::assertSame(str_replace('"reference_submission_id" : 41',
            '"reference_submission_id" : 141', $raw), $mapped['summary_json']);
        self::assertSame($raw, $row['summary_json']);
    }

    public function testDeferBecomesNullButExistingNullNeverInvokesMapper(): void
    {
        $references = self::references();
        $mapped = $references->remap(['form_code' => 'dphshv', 'summary_json' => self::summary(41)],
            static fn (): CompanyBackupReferenceRemapDirective => CompanyBackupReferenceRemapDirective::Defer);
        self::assertSame(str_replace('"reference_submission_id" : 41',
            '"reference_submission_id" : null', self::summary(41)), $mapped['summary_json']);

        $calls = 0;
        $null = $references->remap(['form_code' => 'dphshv', 'summary_json' => self::summary(null)],
            static function () use (&$calls): int {
                $calls++;
                return 99;
            });
        self::assertSame(self::summary(null), $null['summary_json']);
        self::assertSame(0, $calls);
    }

    public function testUnknownShapeAndDuplicateKeysFailBeforeMapper(): void
    {
        $references = self::references();
        foreach ([
            str_replace('"reference_submission_id" : 41',
                '"unknown_id":9, "reference_submission_id" : 41', self::summary(41)),
            str_replace('"reference_submission_id" : 41',
                '"reference_submission_id":9, "reference_submission_id" : 41', self::summary(41)),
        ] as $raw) {
            $calls = 0;
            try {
                $references->remap(['form_code' => 'dphshv', 'summary_json' => $raw],
                    static function () use (&$calls): int {
                        $calls++;
                        return 141;
                    });
                self::fail('Neznámý nebo duplicitní JSON nesmí projít.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame(0, $calls);
                self::assertSame('summary_json', $e->column);
            }
        }
    }

    public function testOnlyExactSummaryReferenceMetadataIsAccepted(): void
    {
        $valid = CompanyBackupTaxSubmissionSummaryContract::embeddedReferences();
        $extra = $valid[0];
        $extra['path'] = ['rows', '*', 'amount'];
        foreach ([[], [$extra], [...$valid, $extra]] as $metadata) {
            try {
                CompanyBackupEmbeddedReferenceSet::fromArray($metadata,
                    'table:tax_submissions', ['id', 'summary_json']);
                self::fail('Metadata nesmí získat právo přepisovat ekonomické hodnoty.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('summary_json', $e->column);
            }
        }
    }

    private static function references(): CompanyBackupEmbeddedReferenceSet
    {
        return CompanyBackupEmbeddedReferenceSet::fromArray(
            CompanyBackupTaxSubmissionSummaryContract::embeddedReferences(),
            'table:tax_submissions', ['id', 'summary_json'],
        );
    }

    private static function summary(?int $reference): string
    {
        $ref = $reference === null ? 'null' : (string) $reference;
        return ' { "period" : "2026-09", "rows_count":1, "total_amount":1.2345e2, '
            . '"rows":[{"country_iso2":"DE","k_stat":"DE","vat_id":"SYNTHETIC",'
            . '"sh_type":"3","amount":123.4500,"count":1,"counterparty_name":"Synthetic"}], '
            . '"submission_deadline":"2026-10-26","variant":"radne","shvies_forma":"R",'
            . '"is_follow_up":false,"d_zjist":null,"storno_rows":0,'
            . '"reference_submission_id" : ' . $ref . ' } ';
    }
}
