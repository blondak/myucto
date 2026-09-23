<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\MoneyS3\MoneyReportParser;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\Shared\ParallelRun\MoneyS3BackupReader;
use MyInvoice\Service\Migration\Shared\ParallelRun\MoneyS3Source;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunException;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunInput;
use MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\TestCase;

/**
 * Záloha syntetické agendy přečtená bez zápisu: předvaha k 31. 12. musí sedět na ručně
 * spočtenou předvahu Money, předvaha k jinému měsíci nesmí brát pozdější zápisy.
 */
final class MoneyS3BackupReaderTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prbackup_' . bin2hex(random_bytes(5));
        SyntheticAgenda::writeDir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testYearEndTrialBalanceMatchesHandComputedMoneyReport(): void
    {
        $read = (new MoneyS3BackupReader())->read(Ms3Backup::open($this->dir), 2024, 12);
        $expected = (new MoneyReportParser())->parse(SyntheticAgenda::trialBalanceCsv2024())['accounts'];

        self::assertTrue($read['year_found']);
        self::assertSame([], TrialBalanceReconciliation::compare($read['trial_balance'], $expected));
    }

    public function testMonthStopsAtItsLastDayAndCountsBooks(): void
    {
        $read = (new MoneyS3BackupReader())->read(Ms3Backup::open($this->dir), 2024, 2);

        // Únor: přijatá faktura 12 100 a její úhrada; tržba z března ještě není.
        self::assertSame([0.0, 0.0, 0.0], $read['trial_balance']['321'] ?? [0.0, 0.0, 0.0]);
        self::assertSame([50000.0, -12100.0, 37900.0], $read['trial_balance']['221']);
        self::assertArrayNotHasKey('602', $read['trial_balance']);
        self::assertSame(1, $read['document_counts']['purchase_invoices']);
        self::assertSame(0, $read['document_counts']['issued_invoices']);
        self::assertSame(1, $read['document_counts']['bank']);
        self::assertSame(0, $read['document_counts']['cash']);
    }

    public function testFileReportTakesPrecedenceOverBackup(): void
    {
        $snapshot = (new MoneyS3Source())->snapshot(2024, 12, [ParallelRunInput::TRIAL_BALANCE => "311;1;2;3\n"], [], ['backup_dir' => $this->dir]);

        self::assertSame(['311' => [1.0, 2.0, 3.0]], $snapshot->trialBalance);
        self::assertNotNull($snapshot->documentCounts, 'Počty dokladů se doplní ze zálohy.');
        self::assertSame(ParallelRunInput::BACKUP, $snapshot->inputs[1]['kind']);
    }

    public function testMissingYear(): void
    {
        $this->expectException(ParallelRunException::class);
        (new MoneyS3Source())->snapshot(2019, 12, [], [], ['backup_dir' => $this->dir]);
    }
}
