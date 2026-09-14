<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionOssSummaryContract as Contract;
use MyInvoice\Service\Oss\OssFilingSnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTaxSubmissionOssSummaryContractTest extends TestCase
{
    public function testCurrentSnapshotProducerShapeWithOrdinaryAndCorrectionDocuments(): void
    {
        $preview = self::preview();
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([]);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $connection = new Connection(new Config([]));
        // Pouze izolovaný PDO stub: skutečné spojení by nemělo vzniknout.
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);

        $summary = self::summary();
        $summary['snapshot'] = (new OssFilingSnapshot($connection))->fromPreview(1, $preview);
        $decoded = json_decode(json_encode($summary, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        Contract::assertSummary($decoded);
        self::assertCount(1, $decoded->snapshot->rows);
        self::assertCount(1, $decoded->snapshot->corrections);
        self::assertCount(2, $decoded->snapshot->documents);
        self::assertSame(11, $decoded->snapshot->documents[0]->invoice_id);
        self::assertSame(21, $decoded->snapshot->documents[0]->item_id);
    }

    public function testKnownSnapshotAllowsNullableProducerFieldsAndFiniteAmounts(): void
    {
        $summary = self::decodedSummary();
        $summary->snapshot->documents[0]->doc_number = null;
        $summary->snapshot->documents[0]->tax_date = null;
        $summary->snapshot->documents[0]->adjusted_period = null;
        $summary->snapshot->documents[0]->status = null;
        $summary->snapshot->documents[0]->updated_at = null;
        $summary->snapshot->rows[0]->supply_type = null;
        $summary->snapshot->rows[0]->rate_type = null;
        $summary->total_corrections = -12.34;
        Contract::assertSummary($summary);
        self::assertSame(-12.34, $summary->total_corrections);
    }

    public function testUnknownHistoricAndNestedShapesFailClosed(): void
    {
        $historic = self::decodedSummary();
        unset($historic->snapshot);
        $this->assertInvalid($historic);
        $version = self::decodedSummary();
        $version->snapshot->schema = 'oss-filing-snapshot.v0';
        $this->assertInvalid($version);
        $unknown = self::decodedSummary();
        $unknown->snapshot->documents[0]->external_id = 7;
        $this->assertInvalid($unknown);
        $unknown = self::decodedSummary();
        $unknown->snapshot->rows[0]->other_id = 7;
        $this->assertInvalid($unknown);
        $unknown = self::decodedSummary();
        $unknown->snapshot->corrections[0]->invoice_id = 7;
        $this->assertInvalid($unknown);
        $invalidId = self::decodedSummary();
        $invalidId->snapshot->documents[0]->invoice_id = 0;
        $this->assertInvalid($invalidId);
        $invalidId = self::decodedSummary();
        $invalidId->snapshot->documents[0]->item_id = '21';
        $this->assertInvalid($invalidId);
        $invalidAmount = self::decodedSummary();
        $invalidAmount->snapshot->rows[0]->base = INF;
        $this->assertInvalid($invalidAmount);
        $unknown = self::decodedSummary();
        $unknown->summary_id = 7;
        $this->assertInvalid($unknown);
    }

    /** @return array<string,mixed> */
    private static function summary(): array
    {
        return [
            'period' => '2026-Q3', 'form_code' => 'ossei1', 'scheme' => 'eu',
            'return_currency' => 'EUR', 'rows_count' => 1, 'corrections_count' => 1,
            'total_base' => 160.0, 'total_vat' => 36.8,
            'total_corrections' => -12.34, 'invoice_count' => 2,
            'submission_deadline' => '2026-10-31', 'warnings' => [],
            'total_payable' => 24.46,
            'snapshot' => [
                'schema' => OssFilingSnapshot::SCHEMA, 'return_currency' => 'EUR',
                'totals' => ['base' => 160.0, 'vat' => 36.8, 'corrections' => -12.34, 'payable' => 24.46],
                'rows' => [[
                    'country' => 'SK', 'supply_type' => 'services', 'rate_type' => 'standard',
                    'rate' => 23.0, 'base' => 160.0, 'vat' => 36.8,
                ]],
                'corrections' => [['period' => '2026-Q2', 'country' => 'SK', 'amount' => -12.34]],
                'documents' => [[
                    'invoice_id' => 11, 'item_id' => 21, 'doc_number' => 'SYN-001',
                    'country' => 'SK', 'tax_date' => '2026-08-01', 'rate' => 23.0,
                    'base' => 160.0, 'vat' => 36.8, 'adjusted_period' => null,
                    'status' => 'issued', 'updated_at' => '2026-09-01 12:00:00',
                ]],
            ],
        ];
    }

    private static function decodedSummary(): \stdClass
    {
        $decoded = json_decode(json_encode(self::summary(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        return $decoded;
    }

    private function assertInvalid(\stdClass $summary): void
    {
        try {
            Contract::assertSummary($summary);
            self::fail('Neznámý obraz OSS podání nesmí projít zálohou.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_tax_submission_summary_invalid', $e->errorCode);
        }
    }

    /** @return array<string,mixed> */
    private static function preview(): array
    {
        return [
            'summary' => [
                'return_currency' => 'EUR', 'total_base' => 160.0, 'total_vat' => 36.8,
                'total_corrections' => -12.34, 'total_payable' => 24.46,
            ],
            'countries' => [[
                'country' => 'SK',
                'rows' => [[
                    'invoice_id' => 11, 'item_id' => 21, 'doc_number' => 'SYN-001',
                    'tax_date' => '2026-08-01', 'supply_type' => 'services',
                    'rate_type' => 'standard', 'vat_rate' => 23.0,
                    'base_return' => 160.0, 'vat_return' => 36.8,
                ]],
            ]],
            'corrections' => [[
                'period' => '2026-Q2', 'state_consumption' => 'SK',
                'correction' => -12.34,
                'rows' => [[
                    'invoice_id' => 12, 'item_id' => 22, 'vat_rate' => 23.0,
                    'base_return' => 0.0, 'vat_return' => -12.34,
                ]],
            ]],
        ];
    }
}
