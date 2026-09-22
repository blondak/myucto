<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierPayroll;
use MyInvoice\Service\Migration\Premier\PremierPayrollDeductions;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Trvalé srážky ze syntetické zálohy PREMIER: druh podle kódu složky, provedení v `DNY`
 * přes vztah a `SRA_INT`, příjmy vedené jako trvalé složky a skončené srážky.
 */
final class PremierPayrollDeductionsTest extends TestCase
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

    public function testStandingDeductionsClassifiedByComponentCode(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_ded_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true, 'payroll_detail' => true]);
        $backup = PremierBackup::open($this->tmp);
        $relations = PremierPayroll::fromBackup($backup)->relations;

        $result = PremierPayrollDeductions::read($backup, $relations, '2026-01', 2026);
        self::assertSame(1, $result['ended'], 'Spoření skončené v 6/2025 se nezakládá.');
        self::assertSame([
            ['premier:mz_sraz:5:2', 'enforcement', 'non_priority', null, 5000000, 300000, 4700000, 100000, '2025-10-01', '2025-09-15'],
            ['premier:mz_sraz:5:4', 'voluntary', null, 'contribution', 0, 0, 0, 15000, '2025-01-01', null],
        ], array_map(static fn (array $r): array => [$r['reference'], $r['target'], $r['category'], $r['deduction_kind'], $r['total_minor'],
            $r['withheld_minor'], $r['outstanding_minor'], $r['monthly_minor'], $r['valid_from'], $r['priority_date']], $result['deductions']),
            'Osobní ohodnocení (303) srážkou není.');
        self::assertSame(['name' => 'Exekutorský úřad Fiktivní', 'account' => '2000145399', 'bank_code' => '0100', 'constant_symbol' => '0558'],
            array_intersect_key($result['deductions'][0]['recipient'], array_flip(['name', 'account', 'bank_code', 'constant_symbol'])));

        self::assertSame([], PremierPayrollDeductions::read($backup, [], '2026-01', 2026)['deductions'], 'Srážky vztahů, které převod nezakládá, se nečtou.');

        $ended = array_map(static fn (array $r): array => $r['key'] === '5' ? ['end' => '2025-12-31'] + $r : $r, $relations);
        $afterEnd = PremierPayrollDeductions::read($backup, $ended, '2026-01', 2026);
        self::assertSame([[], 3], [$afterEnd['deductions'], $afterEnd['ended']], 'Po skončení vztahu se nesráží, i když karta srážky konec nemá.');
    }
}
