<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierDocuments;
use MyInvoice\Service\Migration\Premier\PremierJournal;
use MyInvoice\Service\Migration\Premier\PremierVat;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Deník a faktury načtené pro jeden rok převodu (plné řádky jen roku převodu, ostatní roky
 * v úzkém tvaru, faktury dalších let vynechané) dávají převodu roku totéž jako načtení
 * všech let celých.
 */
final class PremierFocusYearTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $f) {
                if (is_file($this->tmp . DIRECTORY_SEPARATOR . $f)) {
                    unlink($this->tmp . DIRECTORY_SEPARATOR . $f);
                }
            }
            rmdir($this->tmp);
        }
    }

    public function testJournalOfOneYearMatchesFullJournal(): void
    {
        $backup = $this->backup();
        $full = new PremierJournal($backup);
        self::assertSame([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2], $backup->years());

        foreach ($backup->years() as $year) {
            $focus = new PremierJournal($backup, $year);
            self::assertSame($full->openingBalances($year, '431000'), $focus->openingBalances($year, '431000'));
            self::assertSame($full->accountsUsed($year), $focus->accountsUsed($year));
            self::assertSame($full->year($year), $focus->year($year));
            self::assertSame($full->openingRows($year), $focus->openingRows($year));
            self::assertSame($full->closingRowCount($year), $focus->closingRowCount($year));
            self::assertSame($full->firstYear(), $focus->firstYear());
            self::assertSame($full->bankSeries(), $focus->bankSeries());
            self::assertSame($full->trialBalance($year, $full->openingBalances($year, '431000')), $focus->trialBalance($year, $focus->openingBalances($year, '431000')));
            self::assertSame($full->documents($year), $focus->documents($year));

            $other = 0;
            foreach ($full->year($year === SyntheticPremierBackup::YEAR1 ? SyntheticPremierBackup::YEAR2 : SyntheticPremierBackup::YEAR1) as $r) {
                self::assertSame(self::narrow($r), $focus->row($r['inter']), 'Řádek jiného roku má jen úzký tvar se stejnými hodnotami.');
                $other++;
            }
            self::assertGreaterThan(0, $other);
        }
    }

    public function testDocumentsOfOneYearMatchFullBackup(): void
    {
        $backup = $this->backup();
        $vat = PremierVat::fromBackup($backup);
        $full = new PremierJournal($backup);
        $all = PremierDocuments::fromBackup($backup, $full, $vat);
        $shape = static fn (array $docs): array => array_map(static fn (array $d): array => ['rows' => array_map(self::narrow(...), $d['rows'])] + $d, $docs);

        $checked = 0;
        foreach ($backup->years() as $year) {
            $journal = new PremierJournal($backup, $year);
            $documents = PremierDocuments::fromBackup($backup, $journal, $vat, $year);
            self::assertSame($all->invoiceSeries(), $documents->invoiceSeries(), 'Řady faktur jsou ze všech let.');
            self::assertSame($all->paymentLinks(), $documents->paymentLinks());
            foreach ([PremierDocuments::ISSUED, PremierDocuments::PURCHASE] as $direction) {
                $expected = $all->forYear($direction, $year);
                self::assertSame($shape($expected), $shape($documents->forYear($direction, $year)));
                $checked += count($expected);
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function narrow(array $row): array
    {
        return array_combine(PremierJournal::NARROW_KEYS, array_map(static fn (string $k): mixed => $row[$k], PremierJournal::NARROW_KEYS));
    }

    private function backup(): PremierBackup
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_focus_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true]);
        return PremierBackup::open($this->tmp);
    }
}
