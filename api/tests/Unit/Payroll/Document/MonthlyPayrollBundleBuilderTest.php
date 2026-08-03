<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\MonthlyPayrollBundleBuilder;
use MyInvoice\Service\Payroll\Document\PayrollBundleEntry;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class MonthlyPayrollBundleBuilderTest extends TestCase
{
    public function testBuildsDeterministicManifestWithoutPersonalDataInNames(): void
    {
        $first = '%PDF-1.4 synthetic-one';
        $second = '%PDF-1.4 synthetic-two';
        $artifact = (new MonthlyPayrollBundleBuilder())->build(
            '2026-07',
            'revision-2',
            str_repeat('a', 64),
            [
                new PayrollBundleEntry(
                    20,
                    PayrollDocumentKind::PayrollSheet,
                    $second,
                    hash('sha256', $second),
                    'application/pdf',
                ),
                new PayrollBundleEntry(
                    10,
                    PayrollDocumentKind::Payslip,
                    $first,
                    hash('sha256', $first),
                    'application/pdf',
                ),
            ],
        );

        self::assertSame('application/zip', $artifact->mimeType);
        self::assertStringStartsWith('mzdovy-balicek-2026-07-', $artifact->suggestedFilename);
        self::assertStringNotContainsString('synthetic', $artifact->suggestedFilename);
        self::assertSame(
            ['document-000001.pdf', 'document-000002.pdf'],
            array_column($artifact->manifest['entries'], 'entry_name'),
        );
        self::assertSame([10, 20], array_column($artifact->manifest['entries'], 'document_id'));

        $tmp = tempnam(sys_get_temp_dir(), 'payroll-bundle-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $artifact->bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        $manifest = $zip->getFromName('manifest.json');
        self::assertIsString($manifest);
        self::assertEquals($artifact->manifest, json_decode($manifest, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($first, $zip->getFromName('document-000001.pdf'));
        $zip->close();
        unlink($tmp);
    }

    public function testManifestHashChangesWithDocumentSetAndRejectsCorruptEntry(): void
    {
        $builder = new MonthlyPayrollBundleBuilder();
        $first = '%PDF-1.4 first';
        $second = '%PDF-1.4 second';
        $entry = new PayrollBundleEntry(
            10,
            PayrollDocumentKind::Payslip,
            $first,
            hash('sha256', $first),
            'application/pdf',
        );
        $one = $builder->build(
            '2026-07',
            'revision-2',
            str_repeat('a', 64),
            [$entry],
        );
        $two = $builder->build(
            '2026-07',
            'revision-2',
            str_repeat('a', 64),
            [
                $entry,
                new PayrollBundleEntry(
                    20,
                    PayrollDocumentKind::PayrollSheet,
                    $second,
                    hash('sha256', $second),
                    'application/pdf',
                ),
            ],
        );

        self::assertNotSame($one->sourceSnapshotHash, $two->sourceSnapshotHash);

        $this->expectException(\InvalidArgumentException::class);
        $builder->build(
            '2026-07',
            'revision-2',
            str_repeat('a', 64),
            [
                new PayrollBundleEntry(
                    30,
                    PayrollDocumentKind::Payslip,
                    $first,
                    str_repeat('f', 64),
                    'application/pdf',
                ),
            ],
        );
    }

    public function testBuildsManifestOnlyBundleBeforeDocumentsAreArchived(): void
    {
        $artifact = (new MonthlyPayrollBundleBuilder())->build(
            '2026-07',
            'revision-2',
            str_repeat('a', 64),
            [],
        );

        self::assertSame([], $artifact->manifest['entries']);
        self::assertSame('application/zip', $artifact->mimeType);

        $tmp = tempnam(sys_get_temp_dir(), 'payroll-empty-bundle-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $artifact->bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        self::assertSame(1, $zip->numFiles);
        self::assertIsString($zip->getFromName('manifest.json'));
        $zip->close();
        unlink($tmp);
    }
}
