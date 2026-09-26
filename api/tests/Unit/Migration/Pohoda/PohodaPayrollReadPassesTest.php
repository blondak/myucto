<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Tests\Fixtures\Pohoda\LargeSyntheticPohodaPayroll;
use PHPUnit\Framework\TestCase;

/**
 * Čtení velkého souboru mezd PAMICA (`91_mzdy.xml`) jde jedním průchodem.
 *
 * Reálný export má desítky MB, většinou atributová data hlášení. Každý průchod
 * XMLReaderem stojí sekundy, takže čtení tabulku po tabulce (dvanáct průchodů na
 * převodník, další na hlavičku a přehled) dostalo náhled průvodce přes timeout
 * webserveru. Doba se tu měří v násobcích jednoho holého průchodu tímtéž souborem,
 * aby test nezávisel na rychlosti stroje.
 */
final class PohodaPayrollReadPassesTest extends TestCase
{
    private const PERSONS = 300;

    private static string $tmp = '';
    private static string $file = '';
    private static float $pass = 0.0;

    public static function setUpBeforeClass(): void
    {
        self::$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_passes_' . bin2hex(random_bytes(5));
        mkdir(self::$tmp, 0755, true);
        self::$file = LargeSyntheticPohodaPayroll::write(self::$tmp, self::PERSONS);
        // Jeden holý průchod souborem (nejlepší ze tří kvůli šumu).
        $best = INF;
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            PohodaXml::count(self::$file, 'ZAM');
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }
        self::$pass = $best;
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$file);
        @rmdir(dirname(self::$file));
        @rmdir(self::$tmp);
    }

    public function testPackInfoOfDataFileStopsAtRoot(): void
    {
        $seconds = self::measure(static fn () => PohodaXml::packInfo(self::$file));
        self::assertSame(LargeSyntheticPohodaPayroll::ICO, PohodaXml::packInfo(self::$file)['ico']);
        self::assertSame('ok', PohodaXml::packInfo(self::$file)['state']);
        self::assertLessThan(0.2 * self::$pass, $seconds, 'Hlavička datového souboru je v kořeni, soubor se kvůli ní nemá číst celý.');
    }

    public function testPayrollSummaryIsOnePass(): void
    {
        $summary = null;
        $seconds = self::measure(static function () use (&$summary): void {
            $summary = PohodaExport::payrollSummary(self::$file, LargeSyntheticPohodaPayroll::YEAR);
        });
        self::assertSame(['employees' => self::PERSONS, 'months' => 12, 'payslips' => 12 * self::PERSONS, 'first' => '2026-01', 'last' => '2026-12', 'last_overall' => '2026-12'],
            array_intersect_key((array) $summary, array_flip(['employees', 'months', 'payslips', 'first', 'last', 'last_overall'])));
        self::assertLessThan(2.5 * self::$pass, $seconds, 'Přehled mezd má číst soubor jednou (dřív hlavička, mzdy a počet zaměstnanců zvlášť).');
    }

    public function testConverterReadsAllTablesInOnePass(): void
    {
        $converter = null;
        $seconds = self::measure(static function () use (&$converter): void {
            $converter = PohodaPayrollConverter::read(self::$file);
        });
        self::assertInstanceOf(PohodaPayrollConverter::class, $converter);
        self::assertSame(self::PERSONS, $converter->employees());
        self::assertCount(12, $converter->periods(LargeSyntheticPohodaPayroll::YEAR));
        $month = $converter->month('2026-03');
        self::assertCount(self::PERSONS, $month['rows']);
        self::assertLessThan(4 * self::$pass, $seconds, 'Převodník má číst všechny tabulky jedním průchodem (dřív dvanáct průchodů).');
    }

    public function testScanYieldsTagsInFileOrder(): void
    {
        $tags = [];
        foreach (PohodaXml::scan(self::$file, ['ZAM', 'MZ']) as $tag => $row) {
            $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            self::assertArrayHasKey('ID', $row);
        }
        self::assertSame(['ZAM' => self::PERSONS, 'MZ' => 12 * self::PERSONS], $tags);
    }

    private static function measure(callable $fn): float
    {
        $start = hrtime(true);
        $fn();
        return (hrtime(true) - $start) / 1e9;
    }
}
