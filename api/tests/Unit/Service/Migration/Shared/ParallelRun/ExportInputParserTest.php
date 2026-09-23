<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\Shared\ParallelRun\ExportInputParser;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunException;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunInput;
use PHPUnit\Framework\TestCase;

final class ExportInputParserTest extends TestCase
{
    public function testSaldoFindsHeaderBelowTitleAndReadsCp1250(): void
    {
        $csv = "Saldokonto k 31.10.2026\r\nVzorová firma s.r.o.\r\n\r\n"
            . "Účet;Číslo dokladu;Firma;IČ;Zbývá uhradit\r\n"
            . "311000;FV26001;Odběratel Beta a.s.;11223341;12 100,00\r\n"
            . "321000;FP26007;Dodavatel Alfa s.r.o.;87654326;1 234,50\r\n"
            . ";Celkem;;;13 334,50\r\n";
        $rows = (new ExportInputParser())->saldo((string) iconv('UTF-8', 'CP1250', $csv));

        self::assertCount(2, $rows, 'Součtový řádek bez čísla dokladu se nebere.');
        self::assertSame(['account' => '311', 'document' => 'FV26001', 'partner' => 'Odběratel Beta a.s.', 'ico' => '11223341', 'amount' => 12100.0], $rows[0]);
        self::assertSame('321', $rows[1]['account']);
        self::assertSame(1234.5, $rows[1]['amount']);
    }

    public function testMissingHeaderNamesRequiredColumns(): void
    {
        $this->expectException(ParallelRunException::class);
        $this->expectExceptionMessageMatches('/doklad/u');
        (new ExportInputParser())->saldo("Firma;Částka\r\nAlfa;100\r\n");
    }

    public function testDocumentCountsMapBookNamesAndWarnAboutUnknown(): void
    {
        $warnings = [];
        $counts = (new ExportInputParser())->documentCounts(
            "Kniha;Počet\nFaktury vydané;12\nPřijaté faktury;30\nPokladní doklady;4\nBankovní výpisy;51\nInterní doklady;3\nObjednávky;9\n",
            $warnings,
        );

        self::assertSame(['issued_invoices' => 12, 'purchase_invoices' => 30, 'cash' => 4, 'bank' => 51, 'internal' => 3], $counts);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Objednávky', $warnings[0]);
    }

    public function testBankBalancesNormalizeCurrency(): void
    {
        $rows = (new ExportInputParser())->bankBalances("Účet;Měna;Zůstatek;Zůstatek Kč\n1000000005/0100;Kč;62 150,00;\n2000000003/0300;EUR;1 000,00;25 100,00\n");

        self::assertSame([
            ['account' => '1000000005/0100', 'currency' => 'CZK', 'balance' => 62150.0, 'balance_czk' => null],
            ['account' => '2000000003/0300', 'currency' => 'EUR', 'balance' => 1000.0, 'balance_czk' => 25100.0],
        ], $rows);
    }

    public function testAssetsNeedAtLeastOneValue(): void
    {
        $rows = (new ExportInputParser())->assets("Inventární číslo;Název;Vstupní cena;Oprávky;Zůstatková cena\nM001;Stroj;100 000;40 000;60 000\nM002;Bez hodnot;;;\n");

        self::assertCount(1, $rows);
        self::assertSame(['inventory_number' => 'M001', 'name' => 'Stroj', 'input_price' => 100000.0, 'acc_amount' => 40000.0, 'net_book_value' => 60000.0], $rows[0]);
    }

    public function testCostCentersFromColumnsOrFromAccounts(): void
    {
        $parser = new ExportInputParser();
        self::assertSame(
            ['REZIE' => ['revenue' => 0.0, 'cost' => 11800.0], 'VYROBA' => ['revenue' => 20000.0, 'cost' => 500.0]],
            $parser->costCenters("Středisko;Výnosy;Náklady\nREZIE;0;11 800\nVYROBA;20 000;500\n"),
        );
        self::assertSame(
            ['REZIE' => ['revenue' => 0.0, 'cost' => 11800.0], 'VYROBA' => ['revenue' => 20000.0, 'cost' => 0.0]],
            $parser->costCenters("Středisko;Účet;Obrat\nREZIE;518000;10 300\nREZIE;501100;1 500\nVYROBA;602000;20 000\nVYROBA;311000;999\n"),
            'Účet mimo třídy 5 a 6 se nepočítá.',
        );
    }

    public function testBalanceSheetSidesAndThousands(): void
    {
        $parsed = (new ExportInputParser())->statement("Označení;Strana;Běžné období\nB.II.;A;120\nP.A.I.;;60\nA.IV.;P;-5\nPASIVA;;180\n", true, 1000.0);

        self::assertSame(['A:B.II.' => 120000.0, 'P:A.I.' => 60000.0, 'P:A.IV.' => -5000.0, 'P:PASIVA' => 180000.0], $parsed['rows']);
        self::assertSame(1000.0, $parsed['unit']);
    }

    public function testSnapshotRecordsFingerprintAndRejectsUnknownKind(): void
    {
        $parser = new ExportInputParser();
        $snapshot = $parser->snapshot('pohoda', [ParallelRunInput::TRIAL_BALANCE => "311;0;100;100\n"], [ParallelRunInput::TRIAL_BALANCE => 'predvaha.csv']);

        self::assertSame(['311' => [0.0, 100.0, 100.0]], $snapshot->trialBalance);
        self::assertSame('predvaha.csv', $snapshot->inputs[0]['name']);
        self::assertSame(hash('sha256', "311;0;100;100\n"), $snapshot->inputs[0]['sha256']);
        self::assertNull($snapshot->saldo);

        $this->expectException(ParallelRunException::class);
        $parser->snapshot('pohoda', ['payroll' => 'x']);
    }

    public function testRowCodeNormalization(): void
    {
        self::assertSame('B.II.1.', ExportInputParser::rowCode(' b. ii. 1 '));
        self::assertSame('AKTIVA', ExportInputParser::rowCode('Aktiva.'));
        self::assertSame('', ExportInputParser::rowCode(''));
    }
}
