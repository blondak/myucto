<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollDeductions;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollJmhzReports;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPostingMap;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollSickness;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Výkon: čtečky převodu mezd z PAMICA čtou `91_mzdy.xml` (desítky MB) po celých
 * průchodech. Tabulky, které na sobě nezávisí, se berou jedním průchodem
 * ({@see PohodaXml::scan()}), ne jedním průchodem na tabulku.
 */
final class PohodaPayrollReadPassesTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_passes_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    /**
     * @param callable(string,int):mixed $read
     */
    #[DataProvider('readers')]
    public function testReaderUsesFewPasses(callable $read, int $maxPasses, int $passesBefore): void
    {
        $file = SyntheticPohodaPayroll::writeWithReports($this->tmp);
        $before = PohodaXml::passes();
        $read($file, SyntheticPohodaPayroll::YEAR);
        $passes = PohodaXml::passes() - $before;
        self::assertLessThanOrEqual($maxPasses, $passes, "před opravou {$passesBefore} průchodů");
    }

    /** @return iterable<string,array{callable(string,int):mixed,int,int}> */
    public static function readers(): iterable
    {
        yield 'osoby a vztahy' => [static fn (string $file, int $year): mixed => PohodaPayrollPeople::read($file, $year), 2, 19];
        yield 'příjemci odvodů' => [static fn (string $file): mixed => PohodaPayrollPeople::institutions($file), 1, 5];
        yield 'nemocenská' => [static fn (string $file, int $year): mixed => PohodaPayrollSickness::read($file, $year), 2, 8];
        yield 'srážky' => [static fn (string $file, int $year): mixed => PohodaPayrollDeductions::read($file, $year), 2, 7];
        yield 'měsíční hlášení' => [static fn (string $file, int $year): mixed => PohodaPayrollJmhzReports::read($file, $year), 2, 3];
        yield 'registrace' => [static fn (string $file): mixed => PohodaPayrollJmhzReports::registrations($file), 1, 3];
        yield 'zaúčtování' => [static fn (string $file, int $year): mixed => PohodaPayrollPostingMap::read($file, $year), 2, 4];
    }
}
