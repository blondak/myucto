<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceColumnMapper;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use PHPUnit\Framework\TestCase;

/**
 * Mzdy z `91_mzdy.xml` jako měsíční sešity pro import MyÚčta: identita a vztah, hodiny,
 * složky, srážky a profil importu. Vygenerovaný sešit přečtený parserem importu musí dát
 * stejné součty jako mzdy v exportu.
 */
final class PohodaPayrollConverterTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testMonthlyRowsFromPayrollTables(): void
    {
        $converter = PohodaPayrollConverter::read(SyntheticPohodaPayroll::write($this->tmp));
        self::assertSame(SyntheticPohodaPayroll::ICO, $converter->ico);
        self::assertSame(['2026-01', '2026-02'], $converter->periods(2026));
        self::assertSame([], $converter->periods(2025));
        self::assertSame(2, $converter->employees());

        $month = $converter->month('2026-01');
        self::assertSame(2, $month['totals']['rows']);
        $rows = array_column($month['rows'], null, 'Osobní číslo');
        $jana = $rows['1001'];
        self::assertSame('Testovací Jana', $jana['Zaměstnanec']);
        self::assertSame('pracovní poměr', $jana['Druh vztahu']);
        self::assertSame(40.0, $jana['Týdenní úvazek']);
        self::assertSame(40000.0, $jana['Měsíční mzda']);
        self::assertSame('111', $jana['Zdravotní pojišťovna']);
        self::assertSame('4. 5. 1990', $jana['Datum narození']);
        self::assertSame('ADM', $jana['Středisko']);
        self::assertSame('Účetní', $jana['Pracovní místo']);
        self::assertSame('nástup 1. 3. 2025', $jana['Nástup / ukončení']);
        self::assertSame(2000.0, $jana['O01 Odměna (Kč)']);
        self::assertSame(1000.0, $jana['P01 Příplatek za přesčas (Kč)']);
        self::assertSame(4.0, $jana['Přesčas (h)']);
        self::assertSame(600.0, $jana['Obědy - srážka ze mzdy (Kč)']);
        self::assertSame(300.0, $jana['Srážka ze mzdy (Kč)']);
        self::assertArrayNotHasKey('M01 Základní mzda měsíční', $jana, 'Základní mzdu počítá MyÚčto ze sjednané mzdy.');

        $petr = $rows['1002'];
        self::assertSame('DPP', $petr['Druh vztahu']);
        // Neplatné rodné číslo a pojišťovna mimo číselník se do sešitu nedají, jinak by osoba
        // nešla založit; převod je vypíše k doplnění.
        self::assertSame('', $petr['Rodné číslo']);
        self::assertSame('', $petr['Zdravotní pojišťovna']);
        self::assertSame('', $jana['Rodné číslo']);
        self::assertCount(2, $month['omitted']);
        self::assertStringContainsString('1002', $month['omitted'][0]);
        self::assertNull($petr['Týdenní úvazek']);
        self::assertSame(5000.0, $petr['C01 Časová mzda (Kč)']);

        self::assertSame('hourly_wage', $month['columns']['C01 Časová mzda (Kč)']['kind']);
        self::assertSame('bonus', $month['columns']['O01 Odměna (Kč)']['kind']);
        self::assertSame('net_meal_deduction', $month['columns']['Obědy - srážka ze mzdy (Kč)']['meaning']);
        self::assertSame(8.0, array_column($converter->month('2026-02')['rows'], null, 'Osobní číslo')['1001']['Dovolená (h)']);
    }

    /**
     * Fond podle týdenního úvazku vztahu, odpracováno včetně přesčasu a příplatek za noční
     * práci na standardní složce (P07 i pojmenovaná O01 v jednom sloupci).
     */
    public function testFundWorkedHoursAndNightPremium(): void
    {
        $dir = $this->tmp . '/12345678_2026';
        mkdir($dir, 0755, true);
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'P07', 'Nazev' => 'Příplatek za práci v noci']);
        $row('sMZslozky', ['ID' => 2, 'Cislo' => 'O01', 'Nazev' => 'příplatek za noční']);
        $row('sMZslozky', ['ID' => 3, 'Cislo' => 'O01', 'Nazev' => 'odměna']);
        $row('sMZslozky', ['ID' => 4, 'Cislo' => 'P01', 'Nazev' => 'Příplatek za práci přesčas']);
        $row('sMzPoj', ['ID' => 1, 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '2001', 'Jmeno' => 'Eva', 'Prijmeni' => 'Směnová', 'RefPoj' => 1]);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2025-01-01', 'TUvazek' => 37.5]);
        $row('MZ', ['ID' => 1, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => 6, 'HodFond' => 176, 'DnyFond2' => 22, 'TUvazek' => 37.5,
            'HodOdpra' => 150, 'RefPoj' => 1, 'KcHrubaM' => 40000, 'KcCistaM' => 31000]);
        $row('MZslozky', ['ID' => 1, 'RefAg' => 1, 'RefSlozka' => 1, 'KcMzda' => 500, 'PocHodin' => 10]);
        $row('MZslozky', ['ID' => 2, 'RefAg' => 1, 'RefSlozka' => 2, 'KcMzda' => 1000]);
        $row('MZslozky', ['ID' => 3, 'RefAg' => 1, 'RefSlozka' => 3, 'KcMzda' => 2000]);
        $row('MZslozky', ['ID' => 4, 'RefAg' => 1, 'RefSlozka' => 4, 'KcMzda' => 300, 'PocHodin' => 3]);
        file_put_contents($dir . '/91_mzdy.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="POHODA" state="ok">' . $x . '</mdbExport>');

        $month = PohodaPayrollConverter::read($dir . '/91_mzdy.xml')->month('2026-06');
        $eva = $month['rows'][0];
        self::assertSame(165.0, $eva['Fond pracovní doby (h)'], '22 pracovních dnů × 37,5 h / 5.');
        self::assertSame(153.0, $eva['Odpracováno (h)'], 'Odpracováno včetně 3 h přesčasu.');
        self::assertSame(3.0, $eva['Přesčas (h)']);
        self::assertSame(10.0, $eva['Noční práce (h)']);
        self::assertSame(1500.0, $eva['Příplatek za noční práci (Kč)']);
        self::assertSame('PRIPLATEK_NOCNI', $month['columns']['Příplatek za noční práci (Kč)']['code']);
        self::assertSame(2000.0, $eva['O01 odměna (Kč)']);
        $components = array_column(PohodaPayrollConverter::profile([$month])['components'], 'name', 'code');
        self::assertSame('Příplatek za noční práci', $components['PRIPLATEK_NOCNI']);
    }

    /** Sešit přečtený parserem importu MyÚčta s vygenerovaným profilem dává stejné součty. */
    public function testWorkbookRoundTripThroughImportParser(): void
    {
        $converter = PohodaPayrollConverter::read(SyntheticPohodaPayroll::write($this->tmp));
        $months = array_map($converter->month(...), $converter->periods(2026));
        $profile = PohodaPayrollConverter::profile($months);
        self::assertSame(PohodaPayrollConverter::PROFILE_NAME, $profile['name']);
        self::assertEqualsCanonicalizing(['PAM_O01', 'PAM_P01', 'PAM_C01'], array_column($profile['components'], 'code'));

        foreach ($months as $month) {
            $file = PohodaPayrollConverter::workbook($month);
            self::assertSame('xlsx', $file['extension']);
            $sheets = (new AttendanceWorkbookReader())->read($file['name'], 0, 'xlsx', $file['content']);
            $suggester = new AttendanceRuleSuggester();
            $mapper = new AttendanceColumnMapper(new AttendanceSheetAnalyzer($suggester), $suggester);
            $mapped = $mapper->map($sheets, AttendanceRules::validate($profile['rules']));
            $persons = (new AttendancePersonAggregator($mapper))->aggregate($mapped['sheets']);

            $gross = 0;
            $components = 0;
            $meal = 0;
            foreach ($persons as $person) {
                $gross += (int) ($person['reference']['gross_minor'] ?? 0);
                foreach ($person['_components'] as $component) {
                    $components += (int) $component['amount_minor'];
                }
                $meal += (int) ($person['_deductions']['net_meal_deduction']['amount_minor'] ?? 0);
            }
            self::assertCount($month['totals']['rows'], $persons);
            self::assertSame($month['totals']['gross_minor'], $gross, $month['period']);
            self::assertSame($month['totals']['components_minor'], $components, $month['period']);
            self::assertSame($month['totals']['meal_minor'], $meal, $month['period']);
        }
    }
}
