<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatch;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatchItem;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportFile;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Import\Registration\CsszExportStartResolver;
use PHPUnit\Framework\TestCase;

final class CsszExportStartResolverTest extends TestCase
{
    private const ID_PPV = '2000000000101';

    public function testEarliestInsuranceStartAcrossMonthsWins(): void
    {
        $batch = JmhzBatch::build([
            $this->item(2026, 2, 1, insuranceFrom: '2026-02-01'),
            $this->item(2026, 1, 2, insuranceFrom: '2026-01-01'),
        ], []);

        $start = CsszExportStartResolver::resolve($batch, self::ID_PPV);

        self::assertSame([
            'on' => '2026-01-01',
            'source' => 'insurance_from',
            'period' => '2026-01',
            'earliest_period' => '2026-01',
        ], $start);
        self::assertTrue(CsszExportStartResolver::needsCheck($start));
    }

    public function testStartDateFromIdentificationIsPreferredOnTie(): void
    {
        $batch = JmhzBatch::build([
            $this->item(2026, 3, 1, insuranceFrom: '2026-03-10'),
            $this->item(2026, 4, 2, startDate: '2026-03-10', guidSeed: 9),
        ], []);

        $start = CsszExportStartResolver::resolve($batch, self::ID_PPV);

        self::assertNotNull($start);
        self::assertSame('start_date', $start['source']);
        self::assertSame('2026-04', $start['period']);
        self::assertSame('2026-03', $start['earliest_period']);
        self::assertFalse(CsszExportStartResolver::needsCheck($start));
    }

    public function testMidMonthInsuranceStartNeedsNoCheck(): void
    {
        $batch = JmhzBatch::build([$this->item(2026, 4, 1, insuranceFrom: '2026-04-15')], []);

        $start = CsszExportStartResolver::resolve($batch, self::ID_PPV);

        self::assertNotNull($start);
        self::assertSame('2026-04-15', $start['on']);
        self::assertFalse(CsszExportStartResolver::needsCheck($start));
    }

    public function testOtherEmploymentOrCancelledFormGivesNothing(): void
    {
        $cancelled = new JmhzReportForm(1, $this->guid(5), 'S', null, null, employmentIdentifier: self::ID_PPV);
        $batch = JmhzBatch::build([
            $this->item(2026, 1, 1, insuranceFrom: '2026-01-01', idPpv: '2000000000999'),
            new JmhzBatchItem('b:1', $this->file(2026, 5, [$cancelled]), $cancelled, 'storno.xml', str_repeat('b', 64), 1),
        ], []);

        self::assertNull(CsszExportStartResolver::resolve($batch, self::ID_PPV));
    }

    private function item(
        int $year,
        int $month,
        int $index,
        ?string $insuranceFrom = null,
        ?string $startDate = null,
        string $idPpv = self::ID_PPV,
        int $guidSeed = 1,
    ): JmhzBatchItem {
        $form = new JmhzReportForm(
            1,
            $this->guid($guidSeed * 100 + $index),
            'R',
            true,
            'bezPriznaku',
            personIdentifier: RegistrationXmlFixtures::oic(7),
            employmentIdentifier: $idPpv,
            startDate: $startDate,
            insuranceFrom: $insuranceFrom,
        );

        return new JmhzBatchItem(
            sprintf('%016x:1', $guidSeed * 100 + $index),
            $this->file($year, $month, [$form]),
            $form,
            "hlaseni-{$index}.xml",
            str_repeat((string) ($index % 10), 64),
            $index,
        );
    }

    /** @param list<JmhzReportForm> $forms */
    private function file(int $year, int $month, array $forms): JmhzReportFile
    {
        return new JmhzReportFile(
            submissionGuid: $this->guid($year * 100 + $month),
            submissionType: 'R',
            year: $year,
            month: $month,
            filledAt: sprintf('%04d-%02d-20T08:00:00Z', $year, $month),
            packageOrdinal: 1,
            packageCount: 1,
            vendor: null,
            lenient: false,
            warnings: [],
            forms: $forms,
        );
    }

    private function guid(int $seed): string
    {
        return sprintf('0195E2C4-0000-7000-8000-%012X', $seed);
    }
}
