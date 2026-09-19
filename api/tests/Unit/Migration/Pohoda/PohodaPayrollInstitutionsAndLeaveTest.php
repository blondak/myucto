<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeopleWriter;
use PHPUnit\Framework\TestCase;

/**
 * Příjemci odvodů a zůstatek dovolené z exportu PAMICA (`91_mzdy.xml`).
 *
 * Registr institucí PAMICA nese jen zdravotní pojišťovny; účet ČSSZ a finančního úřadu
 * musí převod odvodit z vystavených závazků podle předčíslí účtu u ČNB. Bez podkladu
 * nesmí vzniknout žádný účet, jen srozumitelné upozornění.
 *
 * Data jsou syntetická: předčíslí státních účtů jsou skutečná (jinak by test neověřoval
 * nic), matriky, symboly i osoby vymyšlené.
 */
final class PohodaPayrollInstitutionsAndLeaveTest extends TestCase
{
    private const YEAR = 2026;
    private const OSSZ_ACCOUNT = '21012-1111111111';
    private const ADVANCE_ACCOUNT = '713-1111111111';
    private const WITHHOLDING_ACCOUNT = '7720-1111111111';
    private const OSSZ_VARIABLE_SYMBOL = '8899001122';
    private const TAX_VARIABLE_SYMBOL = '87654321';

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_levy_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testLevyAccountsAreDerivedFromLiabilities(): void
    {
        $file = $this->write([
            ['26OZ00001', self::OSSZ_ACCOUNT, self::OSSZ_VARIABLE_SYMBOL],
            ['26OZ00002', self::OSSZ_ACCOUNT, self::OSSZ_VARIABLE_SYMBOL],
            ['26OZ00003', self::ADVANCE_ACCOUNT, self::TAX_VARIABLE_SYMBOL],
            ['26OZ00004', self::WITHHOLDING_ACCOUNT, self::TAX_VARIABLE_SYMBOL],
        ]);
        $found = self::index(PohodaPayrollPeople::institutions($file));

        // Zdravotní pojišťovna z registru se převodem nemění.
        self::assertSame(
            ['health_insurer', '2050001', '0710', '12345678', null],
            self::shape($found['health_insurer|111']),
        );
        self::assertSame(
            ['social_security', self::OSSZ_ACCOUNT, '0710', self::OSSZ_VARIABLE_SYMBOL, null],
            self::shape($found['social_security|101']),
        );
        self::assertSame(
            ['tax_office', self::ADVANCE_ACCOUNT, '0710', self::TAX_VARIABLE_SYMBOL, null],
            self::shape($found['tax_office|ADVANCE_TAX']),
        );
        self::assertSame(
            ['tax_office', self::WITHHOLDING_ACCOUNT, '0710', self::TAX_VARIABLE_SYMBOL, null],
            self::shape($found['tax_office|WITHHOLDING_TAX']),
        );
        // Název pracoviště se bere z podání, účet z dokladu - protokol musí říct odkud.
        self::assertStringContainsString('Praha 1', (string) $found['social_security|101']['name']);
        self::assertStringContainsString('Doklady', (string) $found['social_security|101']['source']);
    }

    public function testMissingLiabilitiesLeaveInstitutionWithoutAccount(): void
    {
        $found = self::index(PohodaPayrollPeople::institutions($this->write([])));

        foreach (['social_security|101', 'tax_office|ADVANCE_TAX', 'tax_office|WITHHOLDING_TAX'] as $key) {
            // Bez tohohle by chybějící příjemce prošel jako „účet je null" a test by
            // svítil zeleně, i kdyby se o instituci vůbec nedozvěděl.
            self::assertArrayHasKey($key, $found);
            self::assertNull($found[$key]['account'], $key);
            self::assertNull($found[$key]['bank_code'], $key);
            self::assertSame('missing', $found[$key]['issue'], $key);
        }
        // Účet zdravotní pojišťovny na chybějících závazcích nezávisí.
        self::assertSame('2050001', $found['health_insurer|111']['account']);

        $gap = self::gap($found['social_security|101'], '101');
        self::assertStringContainsString('Správa sociálního zabezpečení', $gap);
        self::assertStringContainsString('nenese', $gap);
    }

    public function testTwoAccountsUnderOnePrefixAreNotGuessed(): void
    {
        $found = self::index(PohodaPayrollPeople::institutions($this->write([
            ['26OZ00001', self::OSSZ_ACCOUNT, self::OSSZ_VARIABLE_SYMBOL],
            ['26OZ00002', '21012-2222222222', self::OSSZ_VARIABLE_SYMBOL],
        ])));

        self::assertNull($found['social_security|101']['account']);
        self::assertSame('ambiguous', $found['social_security|101']['issue']);
        self::assertSame(2, $found['social_security|101']['candidates']);
        self::assertStringContainsString('2 různých účtů', self::gap($found['social_security|101'], '101'));
    }

    public function testLeaveBalanceIsConvertedByContractedHoursPerDay(): void
    {
        $records = self::byPersonalNumber(PohodaPayrollPeople::read($this->write([]), self::YEAR));
        self::assertArrayHasKey('1001', $records);
        self::assertArrayHasKey('1002', $records);

        // Šestihodinový úvazek: 120 + 12 - 42 = 90 h, tedy 15 dne. Dělením osmi by
        // z téhož zůstatku vyšlo 11,25 dne.
        $jana = $records['1001']['leave'];
        self::assertSame(90.0, $jana['balance_hours']);
        self::assertSame(15.0, $jana['balance_days']);
        self::assertSame(6.0, $jana['daily_hours']);
        self::assertSame(42.0, $jana['taken_hours']);
        self::assertFalse($jana['from_days']);

        // Karta bez hodinových sloupců: 20 - 4 = 16 dne při úvazku 7,5 h = 120 h.
        $petr = $records['1002']['leave'];
        self::assertTrue($petr['from_days']);
        self::assertSame(120.0, $petr['balance_hours']);
        self::assertSame(16.0, $petr['balance_days']);
        self::assertSame(7.5, $petr['daily_hours']);
    }

    /**
     * Věta, kterou protokol nabídne účetní. Je to privátní pomocník zapisovače, protože
     * se skládá až u zápisu; unit test ho volá odrazem, aby na text nepotřeboval databázi.
     *
     * @param array<string,mixed> $institution
     */
    private static function gap(array $institution, string $code): string
    {
        $method = new \ReflectionMethod(PohodaPayrollPeopleWriter::class, 'institutionGap');

        return (string) $method->invoke(null, $institution, $code);
    }

    /**
     * @param list<array<string,mixed>> $institutions
     * @return array<string,array<string,mixed>>
     */
    private static function index(array $institutions): array
    {
        $out = [];
        foreach ($institutions as $institution) {
            $out[$institution['type'] . '|' . (string) $institution['code']] = $institution;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $institution
     * @return list<mixed>
     */
    private static function shape(array $institution): array
    {
        return [
            $institution['type'],
            $institution['account'],
            $institution['bank_code'],
            $institution['variable_symbol'],
            $institution['issue'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,array<string,mixed>>
     */
    private static function byPersonalNumber(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $out[(string) $record['personal_number']] = $record;
        }
        return $out;
    }

    /**
     * Syntetický `91_mzdy.xml`: dvě osoby s jednou mzdou, zdravotní pojišťovna v registru,
     * pracoviště OSSZ v podáních, karty dovolené a předané závazky.
     *
     * @param list<array{0:string,1:string,2:string}> $liabilities číslo dokladu, účet, VS
     */
    private function write(array $liabilities): string
    {
        $xml = '';
        $row = static function (string $table, array $columns) use (&$xml): void {
            $xml .= "<{$table}>";
            foreach ($columns as $name => $value) {
                $xml .= "<{$name}>" . htmlspecialchars((string) $value, ENT_XML1) . "</{$name}>";
            }
            $xml .= "</{$table}>";
        };

        $row('sMzPoj', ['ID' => 1, 'IDS' => 'Pojišťovna A', 'Kod' => '111', 'Ucet' => '2050001',
            'KodBanky' => '0710', 'VarSym' => '12345678', 'DataBox' => 'aaaaaaa']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '1001', 'Jmeno' => 'Jana', 'Prijmeni' => 'Testovací',
            'DatNar' => '1990-05-04', 'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1]);
        $row('ZAM', ['ID' => 2, 'OsCislo' => '1002', 'Jmeno' => 'Petr', 'Prijmeni' => 'Zkušební',
            'DatNar' => '1985-11-20', 'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1]);
        // Zkrácené úvazky schválně: osmihodinový den by rozdíl mezi dny a hodinami schoval.
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'DatNast' => '2025-03-01',
            'DUvazek' => 6, 'TUvazek' => 30]);
        $row('ZAMpomer', ['ID' => 2, 'RefZAM' => 2, 'Poradi' => 1, 'DatNast' => '2025-09-01',
            'DUvazek' => 7.5, 'TUvazek' => 37.5]);
        foreach ([[11, 1, 1], [21, 2, 2]] as [$payslip, $person, $relation]) {
            $row('MZ', ['ID' => $payslip, 'RefZAM' => $person, 'RefPomer' => $relation,
                'Rok' => self::YEAR, 'RelMes' => 1, 'KcHrubaM' => 30000, 'KcZaklM' => 30000,
                'DnyPrac' => 20, 'DnyOdpra' => 20]);
        }
        // Karta dovolené v hodinách; PAMICA ji vede na osobě a roce.
        $row('Dovolena', ['ID' => 1, 'RefAg' => 1, 'Rok' => self::YEAR, 'JeNarok' => 1,
            'NarokHod' => 120, 'StaraHod' => 12, 'CerpanoHod' => 42,
            'Narok' => 20, 'Stara' => 2, 'Cerpano' => 7]);
        // Karta jen ve dnech (starší zpracování) - hodiny se dopočítají úvazkem.
        $row('Dovolena', ['ID' => 2, 'RefAg' => 2, 'Rok' => self::YEAR, 'JeNarok' => 1,
            'Narok' => 20, 'Cerpano' => 4]);
        // Kód a název pracoviště OSSZ jsou jen v podáních, číselník úřadů export nenese.
        $row('ONZ', ['ID' => 1, 'RelStavDP' => 7, 'DatPod' => '2026-01-05', 'ElOdeslano' => 1]);
        $row('ONZpol', ['ID' => 1, 'RefAg' => 1, 'RefPomer' => 2, 'RelTyp' => 1, 'OSSZ' => 101]);
        $row('NEMPRIpol', ['ID' => 1, 'RefAg' => 1, 'RefZAMpomer' => 1, 'KodOSSZ' => '101',
            'NazevOSSZ' => 'Praha 1']);
        foreach ($liabilities as [$number, $account, $variableSymbol]) {
            [$prefix, $base] = explode('-', $account);
            $row('Doklady', ['ID' => crc32($number), 'RelTpDokl' => 1, 'Cislo' => $number,
                'Rok' => self::YEAR, 'RelMes' => 1, 'VarSym' => $variableSymbol,
                'SText' => 'Mzdy 2026/01, odvod', 'KcCelkem' => 10000,
                'Ucet' => $prefix . '-' . $base, 'KodBanky' => '0710']);
        }
        // Interní doklad se stejným účtem: příjemce z něj odvodit nelze, musí se přeskočit.
        $row('Doklady', ['ID' => 99, 'RelTpDokl' => 2, 'Cislo' => '26IN00001', 'Rok' => self::YEAR,
            'Ucet' => '21012-3333333333', 'KodBanky' => '0710']);

        $file = $this->tmp . '/91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="' . self::YEAR . '" source="POHODA" state="ok">'
            . $xml . '</mdbExport>');

        return $file;
    }
}
