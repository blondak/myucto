<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollDeductions;
use PHPUnit\Framework\TestCase;

/**
 * Trvalé srážky z `91_mzdy.xml`: klasifikace na exekuci, insolvenci a dohodu o srážkách
 * podle číselníku `sMZsrazky` a mapování pořadí, výživného a příjemce.
 *
 * Data jsou syntetická, včetně čísla účtu `1000000005 / 0100`, které projde mod-11
 * kontrolou českých účtů.
 */
final class PohodaPayrollDeductionsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_deductions_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testClassifiesEnforcementInsolvencyAndAgreement(): void
    {
        $result = PohodaPayrollDeductions::read($this->write(), 2026);
        $byReference = array_column($result['deductions'], null, 'reference');

        // Exekuce: nepřednostní pohledávka s pořadím, příjemcem a zbývající jistinou.
        $enforcement = $byReference['pamica:zamsrazky:1'];
        self::assertSame('enforcement', $enforcement['target']);
        self::assertSame('catalog_statutory', $enforcement['target_reason']);
        self::assertSame('non_priority', $enforcement['category']);
        self::assertSame(['1001'], $enforcement['personal_numbers']);
        self::assertSame('2025-11-12', $enforcement['priority_date']);
        self::assertSame(1, $enforcement['priority_no']);
        self::assertSame(2, $enforcement['dependants']);
        self::assertFalse($enforcement['deferred']);
        // 38 601,66 celkem, sraženo 1 000 na kartě a 2 × 2 500 ve mzdách.
        self::assertSame(600_000, $enforcement['withheld_minor']);
        self::assertSame(3_260_166, $enforcement['outstanding_minor']);
        self::assertSame(['2026-01', '2026-02'], $enforcement['periods']);
        self::assertSame(['ZAMsrazky:1', 'MZsrazky:1', 'MZsrazky:2'], $enforcement['evidence']);
        self::assertSame([
            'name' => 'Exekutorský úřad Zkušební',
            'reference' => '123 EX 456/25',
            'ico' => '11111119',
            'account' => '1000000005',
            'bank_code' => '0100',
            'variable_symbol' => '1234567890',
            'specific_symbol' => null,
            'constant_symbol' => '0558',
        ], $enforcement['recipient']);

        // Výživné nemá v PAMICA vlastní složku, pozná se podle původní výše výživného.
        $maintenance = $byReference['pamica:zamsrazky:2'];
        self::assertSame('enforcement', $maintenance['target']);
        self::assertSame('current_maintenance', $maintenance['category']);
        self::assertSame(300_000, $maintenance['maintenance_weight_minor']);
        self::assertSame(0, $maintenance['outstanding_minor']);
        self::assertSame('2020-09-24', $maintenance['priority_date']);

        // Insolvenci číselník neodlišuje příznakem, jen názvem druhu srážky.
        $insolvency = $byReference['pamica:zamsrazky:3'];
        self::assertSame('insolvency', $insolvency['target']);
        self::assertSame('other_priority', $insolvency['category'], 'Insolvence se sráží v rozsahu přednostní pohledávky.');
        self::assertSame(['1002'], $insolvency['personal_numbers']);
        self::assertSame('Insolvenční správce Zkušební', $insolvency['recipient']['name']);

        // Dobrovolná srážka: penzijní připojištění jako příspěvek s měsíční částkou.
        $agreement = $byReference['pamica:zamsrazky:4'];
        self::assertSame('voluntary', $agreement['target']);
        self::assertSame('contribution', $agreement['deduction_kind']);
        self::assertNull($agreement['category']);
        self::assertSame(50_000, $agreement['monthly_minor']);
        self::assertFalse($agreement['carried_by_attendance'], 'Srážka bez záznamu ve mzdě v měsíčním sešitu není.');
        self::assertNull($agreement['recipient']['account'], 'Příjemce bez čísla účtu se nevydává za platební cíl.');

        // Trvalá srážka, kterou nese i měsíční sešit, se podruhé nezakládá.
        $fromSheet = $byReference['pamica:zamsrazky:5'];
        self::assertSame('voluntary', $fromSheet['target']);
        self::assertTrue($fromSheet['carried_by_attendance']);

        // Srážky jen ve mzdě, bez trvalé srážky na kartě.
        $orphans = array_values(array_filter(
            $result['deductions'],
            static fn (array $row): bool => str_starts_with((string) $row['reference'], 'pamica:mzsrazky:'),
        ));
        self::assertCount(2, $orphans);
        $deferred = self::single($orphans, 'S05');
        self::assertSame('enforcement', $deferred['target']);
        self::assertSame('catalog_deferred', $deferred['target_reason']);
        self::assertTrue($deferred['deferred']);
        self::assertSame(150_000, $deferred['withheld_minor']);
        self::assertSame(['2026-01'], $deferred['periods']);

        $monthly = self::single($orphans, 'S07');
        self::assertSame('voluntary', $monthly['target']);
        self::assertSame('other', $monthly['deduction_kind']);
        self::assertTrue($monthly['carried_by_attendance'], 'Srážku z měsíčního sešitu zapisuje import docházky.');

        // Podklady pro nezabavitelnou částku se jen počítají, převést se nedají.
        self::assertSame(1, $result['protected_amount_inputs']);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function single(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }
        self::fail("Srážka {$code} ve výsledku není.");
    }

    /** Syntetický `91_mzdy.xml` se srážkami; vrátí cestu k souboru. */
    private function write(): string
    {
        $xml = '';
        $row = static function (string $table, array $cols) use (&$xml): void {
            $xml .= "<{$table}>";
            foreach ($cols as $key => $value) {
                $xml .= "<{$key}>" . htmlspecialchars((string) $value, ENT_XML1) . "</{$key}>";
            }
            $xml .= "</{$table}>";
        };

        $row('sMZsrazky', ['ID' => 1, 'Cislo' => 'S01a', 'Nazev' => 'Zákonná srážka celkovou částkou - exekuce', 'JeZak' => 1]);
        $row('sMZsrazky', ['ID' => 2, 'Cislo' => 'S01b', 'Nazev' => 'Zákonná srážka - insolvence', 'JeZak' => 1]);
        $row('sMZsrazky', ['ID' => 3, 'Cislo' => 'S10', 'Nazev' => 'Penzijní připojištění']);
        $row('sMZsrazky', ['ID' => 4, 'Cislo' => 'S07', 'Nazev' => 'Srážka zadaná částkou']);
        $row('sMZsrazky', ['ID' => 5, 'Cislo' => 'S05', 'Nazev' => 'Deponovaná částka zákonné srážky', 'JeDepon' => 1]);

        $row('ZAM', ['ID' => 1, 'OsCislo' => '1001', 'Jmeno' => 'Jana', 'Prijmeni' => 'Testovací']);
        $row('ZAM', ['ID' => 2, 'OsCislo' => '1002', 'Jmeno' => 'Petr', 'Prijmeni' => 'Zkušební']);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'DatNast' => '2025-03-01']);
        $row('ZAMpomer', ['ID' => 2, 'RefZAM' => 2, 'Poradi' => 1, 'DatNast' => '2025-09-01']);

        $row('MZ', ['ID' => 11, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => 1]);
        $row('MZ', ['ID' => 12, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => 2]);
        $row('MZ', ['ID' => 21, 'RefZAM' => 2, 'RefPomer' => 2, 'Rok' => 2026, 'RelMes' => 1]);
        // Mzda jiného roku: její srážky do převodu nepatří.
        $row('MZ', ['ID' => 31, 'RefZAM' => 2, 'RefPomer' => 2, 'Rok' => 2025, 'RelMes' => 12]);

        $row('ZAMsrazky', ['ID' => 1, 'RefAg' => 1, 'RefSlozka' => 1, 'DatOd' => '2026-01-01', 'RelDrSra' => 4, 'PocOsob' => 2,
            'DatPoradi' => '2025-11-12', 'Poradi' => 1, 'KcCelkem' => 38601.66, 'KcSrazeno' => 1000, 'KcMesic' => 2500,
            'PlFirma' => 'Exekutorský úřad Zkušební', 'PlRozhod' => '123 EX 456/25', 'PlICO' => '11111119',
            'PlUcet' => '1000000005', 'PlKodBanky' => '0100', 'PlVarSym' => '1234567890', 'PlKonstSym' => '558']);
        $row('ZAMsrazky', ['ID' => 2, 'RefAg' => 1, 'RefSlozka' => 1, 'DatOd' => '2026-01-01', 'RelDrSra' => 3,
            'DatPoradi' => '2020-09-24', 'Poradi' => 2, 'KcCelkem' => 0, 'KcVyzivPuv' => 3000]);
        $row('ZAMsrazky', ['ID' => 3, 'RefAg' => 2, 'RefSlozka' => 2, 'DatOd' => '2026-01-01', 'DatPoradi' => '2025-06-01',
            'KcCelkem' => 50000, 'PlFirma' => 'Insolvenční správce Zkušební', 'PlUcet' => '1000000005', 'PlKodBanky' => '0300']);
        $row('ZAMsrazky', ['ID' => 4, 'RefAg' => 1, 'RefSlozka' => 3, 'DatOd' => '2026-01-01', 'Poradi' => 3,
            'KcMesic' => 500, 'PlFirma' => 'Penzijní společnost Zkušební']);
        // Trvalá srážka, kterou nese i měsíční sešit převodu.
        $row('ZAMsrazky', ['ID' => 5, 'RefAg' => 2, 'RefSlozka' => 4, 'DatOd' => '2026-01-01', 'KcMesic' => 300]);

        $row('MZsrazky', ['ID' => 1, 'RefAg' => 11, 'RefZAMsrazky' => 1, 'RefSlozka' => 1, 'KcSrazeno' => 2500]);
        $row('MZsrazky', ['ID' => 2, 'RefAg' => 12, 'RefZAMsrazky' => 1, 'RefSlozka' => 1, 'KcSrazeno' => 2500]);
        $row('MZsrazky', ['ID' => 3, 'RefAg' => 21, 'RefSlozka' => 5, 'KcSrazeno' => 1500]);
        $row('MZsrazky', ['ID' => 4, 'RefAg' => 11, 'RefSlozka' => 4, 'KcSrazeno' => 300]);
        $row('MZsrazky', ['ID' => 5, 'RefAg' => 31, 'RefSlozka' => 4, 'KcSrazeno' => 900]);
        $row('MZsrazky', ['ID' => 6, 'RefAg' => 21, 'RefZAMsrazky' => 5, 'RefSlozka' => 4, 'KcSrazeno' => 300]);

        $row('rpZAMprijemSraz', ['ID' => 1, 'RefZAM' => 1, 'Pouzito' => 1, 'KcCelkem' => 12000]);
        $row('rpZAMprijemSraz', ['ID' => 2, 'RefZAM' => 1, 'Pouzito' => 1, 'KcCelkem' => 3000]);
        $row('rpZAMprijemSraz', ['ID' => 3, 'RefZAM' => 2, 'Pouzito' => 0, 'KcCelkem' => 0]);

        $file = $this->tmp . DIRECTORY_SEPARATOR . '91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="POHODA" state="ok">'
            . $xml . '</mdbExport>');

        return $file;
    }
}
