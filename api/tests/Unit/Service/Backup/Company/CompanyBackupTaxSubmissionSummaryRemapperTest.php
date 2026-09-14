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

    public function testScalarFormsPassThroughByteForByteAndRejectBadKeysBeforeMapper(): void
    {
        $references = self::references();
        foreach (['dphkh1', 'dpfdp7', 'dppdp9', 'osvc25'] as $form) {
            $source = self::scalarSummary($form);
            $calls = 0;
            $mapper = static function () use (&$calls): int {
                $calls++;
                return 999;
            };
            $row = ['form_code' => $form, 'summary_json' => $source];
            $restored = $references->remap($row, $mapper);
            self::assertSame($source, $restored['summary_json'], $form);
            self::assertSame(0, $calls, $form);

            foreach ([
                substr($source, 0, -2) . ', "unknown_id": 7 } ',
                self::duplicateKnownKey($form, $source),
            ] as $invalid) {
                try {
                    $references->remap(['form_code' => $form, 'summary_json' => $invalid], $mapper);
                    self::fail('Neznámý nebo duplicitní klíč nesmí získat fallback.');
                } catch (CompanyBackupDataSourceException $e) {
                    self::assertSame('summary_json', $e->column);
                    self::assertSame(0, $calls, $form);
                }
            }
        }
    }

    public function testUnknownFormStillFailsBeforeMapper(): void
    {
        $calls = 0;
        try {
            self::references()->remap(
                ['form_code' => 'dpfdp5', 'summary_json' => '{}'],
                static function () use (&$calls): int {
                    $calls++;
                    return 7;
                },
            );
            self::fail('Neznámý historický formulář nelze obnovit odhadem.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_tax_submission_summary_unsupported', $e->errorCode);
            self::assertSame(0, $calls);
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

    private static function scalarSummary(string $form): string
    {
        $summary = match ($form) {
            'dphkh1' => [
                'period' => '2026-09', 'a1_count' => 0, 'a2_count' => 0,
                'a4_count' => 1, 'a5_count_aggregated' => 0,
                'b1_count' => 0, 'b2_count' => 0, 'b3_count_aggregated' => 0,
                'submission_deadline' => '2026-10-26', 'variant' => 'radne',
                'khdph_forma' => 'B', 'is_follow_up' => false,
                'd_zjist' => null, 'c_jed_vyzvy' => null,
            ],
            'dpfdp7' => [
                'total_base' => 1000, 'rounded_base' => 1000, 'tax16' => 150,
                'tax_after_credits' => 150, 'child_bonus' => 0, 'child_credit' => 0,
                'spouse_credit' => 0, 'bonus_qualifying_income' => 0,
                'final_tax' => 150, 'balance_due' => 123.45,
                'separate_base' => 0, 'separate_base_tax' => 0,
                's7_profit' => 1000, 'uhrn_710' => 1000,
                'loss_applied' => 0, 'year_tax_loss' => 0,
                'variant' => 'radne', 'warnings' => [],
            ],
            'dppdp9' => [
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
            ],
            'osvc25' => ['insurance' => 'social', 'warnings' => ['Synthetic warning']],
            default => throw new \InvalidArgumentException('Neznámá syntetická fixtura.'),
        };
        return ' ' . json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . ' ';
    }

    private static function duplicateKnownKey(string $form, string $source): string
    {
        $key = match ($form) {
            'dphkh1' => 'period',
            'dpfdp7' => 'total_base',
            'dppdp9' => 'rate',
            'osvc25' => 'insurance',
            default => throw new \InvalidArgumentException('Neznámá syntetická fixtura.'),
        };
        return str_replace('"' . $key . '":', '"' . $key . '":null,"' . $key . '":', $source);
    }
}
