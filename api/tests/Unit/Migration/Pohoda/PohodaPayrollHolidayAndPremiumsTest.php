<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use PHPUnit\Framework\TestCase;

/**
 * Měsíc s placeným svátkem a zákonnými příplatky v sešitu převodu z PAMICA.
 *
 * PAMICA počítá svátek v jinak pracovní den u měsíční mzdy do odpracovaných hodin,
 * v měsíčním hlášení ho ale vede mezi neodpracovanými hodinami s náhradou. Sešit ho
 * proto nese zvlášť, jinak by MyÚčto hlásilo o svátek víc odpracovaných hodin a fond
 * s ním by neodpovídal kalendáři. Mzda za hodiny má za svátek náhradu (`V02`) a ta se
 * od odpracovaných hodin neodečítá.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollHolidayAndPremiumsTest extends TestCase
{
    private string $tmp = '';
    private string $file = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_holiday_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/12345678_2026', 0755, true);
        $this->file = $this->tmp . '/12345678_2026/91_mzdy.xml';
        file_put_contents($this->file, self::payrollXml());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testMonthlyWageHolidayIsSplitFromWorkedHours(): void
    {
        $row = $this->row('6001');

        self::assertSame(8.0, $row['Svátek (h)'] ?? null);
        self::assertSame(168.0, $row['Odpracováno (h)'], 'Odpracováno bez svátku.');
        self::assertSame('holiday_hours', $this->month()['columns']['Svátek (h)']['meaning']);
    }

    public function testHourlyWageKeepsHolidayCompensationHoursAndWorkedHours(): void
    {
        $row = $this->row('6002');

        self::assertSame(8.0, $row['Svátek (h)'] ?? null, 'Hodiny náhrady za svátek z V02.');
        self::assertSame(160.0, $row['Odpracováno (h)']);
    }

    public function testHolidayOutsideTheEmploymentIsNotSplit(): void
    {
        $row = $this->row('6003');

        self::assertArrayNotHasKey('Svátek (h)', $row, 'Nástup po svátku: PAMICA svátek vztahu nezapočítala.');
        self::assertSame(96.0, $row['Odpracováno (h)']);
    }

    public function testStatutoryPremiumsAndSeveranceGoToTheirOwnComponents(): void
    {
        $codes = [];
        foreach ($this->month()['columns'] as $header => $column) {
            if ($column['meaning'] === 'component') {
                $codes[$header] = $column['code'];
            }
        }

        self::assertSame('PRIPLATEK_PRESCAS', $codes['Příplatek za práci přesčas (Kč)'] ?? null);
        self::assertSame('PRIPLATEK_VIKEND', $codes['Příplatek za práci v sobotu a v neděli (Kč)'] ?? null);
        self::assertSame('PRIPLATEK_SVATEK', $codes['Příplatek za práci ve svátek (Kč)'] ?? null);
        self::assertSame('ODSTUPNE', $codes['Odstupné (Kč)'] ?? null);
        self::assertSame(30000.0, $this->row('6001')['Odstupné (Kč)'] ?? null);
    }

    /** @return array<string,mixed> */
    private function month(): array
    {
        return PohodaPayrollConverter::read($this->file)->month('2026-07');
    }

    /** @return array<string,mixed> */
    private function row(string $personalNumber): array
    {
        foreach ($this->month()['rows'] as $row) {
            if (str_starts_with((string) $row['Osobní číslo'], $personalNumber)) {
                return $row;
            }
        }
        self::fail("Řádek {$personalNumber} v sešitu chybí.");
    }

    /**
     * Červenec 2026: svátek v pondělí 6. 7. Tři fiktivní vztahy: měsíční mzda (svátek
     * v odpracovaných hodinách), hodinová mzda (náhrada za svátek `V02`) a nástup 10. 7.
     */
    private static function payrollXml(): string
    {
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'V02', 'Nazev' => 'Náhrada svátku']);
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'M01', 'Nazev' => 'Základní mzda']);
        $row('sMZslozky', ['ID' => 2, 'Cislo' => 'C01', 'Nazev' => 'Časová mzda']);
        $row('sMZslozky', ['ID' => 3, 'Cislo' => 'P01', 'Nazev' => 'Příplatek za práci přesčas']);
        $row('sMZslozky', ['ID' => 4, 'Cislo' => 'P04', 'Nazev' => 'Příplatek za práci v sobotu a neděli']);
        $row('sMZslozky', ['ID' => 5, 'Cislo' => 'P03', 'Nazev' => 'Příplatek za práci ve svátek']);
        $row('sMZslozky', ['ID' => 6, 'Cislo' => 'D06', 'Nazev' => 'Odstupné (s výpočtem)']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $plan = [];
        for ($day = 1; $day <= 31; $day++) {
            $weekday = (int) date('N', (int) mktime(0, 0, 0, 7, $day, 2026));
            $plan['Hodin' . $day] = $weekday <= 5 ? 8 : 0;
        }
        foreach ([1 => ['6001', '2020-01-01'], 2 => ['6002', '2020-01-01'], 3 => ['6003', '2026-07-10']] as $id => [$number, $start]) {
            $row('ZAM', ['ID' => $id, 'OsCislo' => $number, 'Jmeno' => 'Test', 'Prijmeni' => "Svátek{$id}", 'DatNar' => '1990-01-0' . $id,
                'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1]);
            $row('ZAMpomer', ['ID' => $id, 'RefZAM' => $id, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => $start, 'TUvazek' => 40]);
        }
        // Měsíční mzda: 176 h odpracováno včetně svátku, fond 184 h (23 dnů se svátkem).
        $row('MZ', ['ID' => 10, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => 7, 'HodFond' => 184, 'DnyFond2' => 23,
            'TUvazek' => 40, 'HodOdpra' => 176, 'DnyStSv' => 1, 'RefPoj' => 1, 'KcHrubaM' => 70000, 'KcCistaM' => 50000,
            'KcZaklM' => 40000, 'DnyPrac' => 22, 'DnyOdpra' => 22]);
        $row('MZdoch', ['ID' => 10, 'RefAg' => 10] + $plan);
        $row('MZslozky', ['ID' => 10, 'RefAg' => 10, 'RefSlozka' => 1, 'KcMzda' => 40000, 'Hodnota1' => 40000, 'PocHodin' => 176]);
        $row('MZslozky', ['ID' => 11, 'RefAg' => 10, 'RefSlozka' => 3, 'KcMzda' => 500, 'PocHodin' => 0]);
        $row('MZslozky', ['ID' => 12, 'RefAg' => 10, 'RefSlozka' => 4, 'KcMzda' => 300, 'PocHodin' => 0]);
        $row('MZslozky', ['ID' => 13, 'RefAg' => 10, 'RefSlozka' => 5, 'KcMzda' => 200, 'PocHodin' => 0]);
        $row('MZslozky', ['ID' => 14, 'RefAg' => 10, 'RefSlozka' => 6, 'KcMzda' => 30000, 'PocHodin' => 0]);
        // Hodinová mzda: svátek jako náhrada V02, odpracováno 160 h bez svátku.
        $row('MZ', ['ID' => 20, 'RefZAM' => 2, 'RefPomer' => 2, 'Rok' => 2026, 'RelMes' => 7, 'HodFond' => 184, 'DnyFond2' => 23,
            'TUvazek' => 40, 'HodOdpra' => 160, 'DnyStSv' => 1, 'RefPoj' => 1, 'KcHrubaM' => 33000, 'KcCistaM' => 25000,
            'KcZaklM' => 32000, 'DnyPrac' => 22, 'DnyOdpra' => 20]);
        $row('MZdoch', ['ID' => 20, 'RefAg' => 20] + $plan);
        $row('MZslozky', ['ID' => 20, 'RefAg' => 20, 'RefSlozka' => 2, 'KcMzda' => 32000, 'PocHodin' => 160]);
        $row('MZneprit', ['ID' => 20, 'RefAg' => 20, 'RefSlozka' => 1, 'HodPrac' => 8, 'KcNahr' => 1600,
            'DatZac' => '2026-07-05', 'DatKon' => '2026-07-06']);
        // Nástup po svátku: rozvrh MZdoch je celý měsíc, PAMICA svátek vztahu nezapočítala.
        $row('MZ', ['ID' => 30, 'RefZAM' => 3, 'RefPomer' => 3, 'Rok' => 2026, 'RelMes' => 7, 'HodFond' => 184, 'DnyFond2' => 23,
            'TUvazek' => 40, 'HodOdpra' => 96, 'DnyStSv' => 0, 'RefPoj' => 1, 'KcHrubaM' => 20000, 'KcCistaM' => 16000,
            'KcZaklM' => 20000, 'DnyPrac' => 12, 'DnyOdpra' => 12]);
        $row('MZdoch', ['ID' => 30, 'RefAg' => 30] + $plan);
        $row('MZslozky', ['ID' => 30, 'RefAg' => 30, 'RefSlozka' => 1, 'KcMzda' => 20000, 'Hodnota1' => 40000, 'PocHodin' => 96]);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="PAMICA" state="ok">' . $x . '</mdbExport>';
    }
}
