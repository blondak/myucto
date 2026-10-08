<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierPayrollSubmissions;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationXmlReader;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Tests\Fixtures\Premier\DbfWriter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Podání REGZEC / PREZEC z `MZ_VREP`, která ČSSZ přijala: věty v pořadí odeslání, každá jako
 * samostatný soubor, bez vět a podání, které příjemce odmítl.
 */
final class PremierPayrollSubmissionsTest extends TestCase
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

    public function testAcceptedSentencesInSendingOrderOneFileEach(): void
    {
        $submissions = PremierPayrollSubmissions::fromBackup($this->backup());

        self::assertSame(['premier-REGZEC25-aaaa0001-1.xml', 'premier-REGZEC25-aaaa0001-3.xml', 'premier-PREZEC26-aaaa0003-1.xml'],
            array_column($submissions->sentences, 'name'), 'Pořadí podle času odeslání, věta 2 (ERROR) a odmítnuté podání chybí.');
        self::assertSame(['files' => 3, 'files_rejected' => 1, 'files_unreadable' => 0, 'sentences_rejected' => 1], $submissions->stats);

        $reader = new RegistrationXmlReader(new PayrollRegistrationSchemaCatalog());
        $names = [];
        foreach ($submissions->sentences as $sentence) {
            $read = $reader->read($sentence['content']);
            self::assertCount(1, $read['records'], 'Soubor nese jedinou větu a projde schématem.');
            $names[] = $read['records'][0]->lastName;
        }
        self::assertSame(['Testovací', 'Žlutoučký', 'Testovací'], $names, 'Diakritika z Windows-1250 zůstane.');
        self::assertSame('2026-07-02', $submissions->sentences[0]['date']);
    }

    public function testAnswerWithoutPerSentenceItemsAcceptsWholeFile(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_subm_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, []);
        DbfWriter::write($this->tmp . DIRECTORY_SEPARATOR . 'MZ_VREP.DBF', self::fields(), [
            ['ID' => 'BBBB0001-0000', 'TYP_ZPRAVY' => 'REGZEC25', 'DAT_ZPRAVY' => '2026-07-02 09:00:00', 'POZNAMKA' => RegistrationXmlFixtures::regzecA1(),
                'POZNAMKA2' => '<answer><accepted>True</accepted></answer>'],
        ]);
        self::assertCount(1, PremierPayrollSubmissions::fromBackup(PremierBackup::open($this->tmp))->sentences);
    }

    public function testUnreadableBodyIsCountedNotThrown(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_subm_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, []);
        DbfWriter::write($this->tmp . DIRECTORY_SEPARATOR . 'MZ_VREP.DBF', self::fields(), [
            ['ID' => 'CCCC0001-0000', 'TYP_ZPRAVY' => 'REGZEC25', 'DAT_ZPRAVY' => '2026-07-02 09:00:00', 'POZNAMKA' => '?<REGZEC><employees>',
                'POZNAMKA2' => '<answer><accepted>True</accepted></answer>'],
        ]);
        $submissions = PremierPayrollSubmissions::fromBackup(PremierBackup::open($this->tmp));
        self::assertSame([[], 1], [$submissions->sentences, $submissions->stats['files_unreadable']]);
    }

    private function backup(): PremierBackup
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_subm_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, []);

        $first = RegistrationXmlFixtures::regzecA1(['last' => 'Testovací']);
        $second = RegistrationXmlFixtures::regzecA1(['last' => 'Odmítnutý', 'bno' => RegistrationXmlFixtures::birthNumber('1991-02-03', 'male', 3)]);
        $third = RegistrationXmlFixtures::regzecA1(['last' => 'Žlutoučký', 'bno' => RegistrationXmlFixtures::birthNumber('1992-03-04', 'male', 4)]);
        $merged = $first;
        foreach ([2 => $second, 3 => $third] as $sqnr => $xml) {
            self::assertSame(1, preg_match('#<employee\b.*?</employee>#s', $xml, $employee));
            $merged = str_replace('</employees>', str_replace('sqnr="1"', 'sqnr="' . $sqnr . '"', $employee[0]) . '</employees>', $merged);
        }
        $item = static fn (int $sqnr, string $result): string => '&lt;Item sqnr="' . $sqnr . '" subtype="REGZEC25" result="' . $result . '" /&gt;';
        $answer = static fn (string $items): string => '<answer><accepted>True</accepted><dataError>&lt;Details&gt;&lt;Item sqnr="" result="OK" /&gt;' . $items . '&lt;/Details&gt;</dataError></answer>';

        DbfWriter::write($this->tmp . DIRECTORY_SEPARATOR . 'MZ_VREP.DBF', self::fields(), [
            ['ID' => 'AAAA0003-0000', 'TYP_ZPRAVY' => 'PREZEC26', 'DAT_ZPRAVY' => '2026-09-10 09:00:00',
                'POZNAMKA' => '?' . RegistrationXmlFixtures::prezecP1(RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1), 'Jana', 'Testovací', '2026-10-01'),
                'POZNAMKA2' => $answer($item(1, 'OK'))],
            ['ID' => 'AAAA0001-0000', 'TYP_ZPRAVY' => 'REGZEC25', 'DAT_ZPRAVY' => '2026-07-02 09:00:00', 'POZNAMKA' => '?' . $merged,
                'POZNAMKA2' => $answer($item(1, 'OK') . $item(2, 'ERROR') . $item(3, 'OK'))],
            ['ID' => 'AAAA0002-0000', 'TYP_ZPRAVY' => 'REGZEC25', 'DAT_ZPRAVY' => '2026-07-03 09:00:00', 'POZNAMKA' => '?' . $first,
                'POZNAMKA2' => '<answer><accepted>False</accepted><errorText>Překryv.</errorText></answer>'],
            ['ID' => 'AAAA0004-0000', 'TYP_ZPRAVY' => 'JMHZ25', 'DAT_ZPRAVY' => '2026-07-04 09:00:00', 'POZNAMKA' => '?<jmhz/>', 'POZNAMKA2' => $answer('')],
        ]);
        return PremierBackup::open($this->tmp);
    }

    /** @return list<array{0:string,1:string,2?:int}> */
    private static function fields(): array
    {
        return [['ID', 'C', 36], ['TYP_ZPRAVY', 'C', 30], ['DAT_ZPRAVY', 'C', 20], ['POZNAMKA', 'M'], ['POZNAMKA2', 'M']];
    }
}
