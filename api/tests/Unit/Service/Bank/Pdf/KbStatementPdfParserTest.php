<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\KbStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Parser PDF výpisu KB (Komerční banka). Testuje se přes seamy parseHeaderFromText()/
 * parseTransactionsFromText() na VYMYŠLENÉM textu (žádná reálná zákaznická data dle
 * pravidla feedback_test_data_local_only — účty, jména, VS i částky jsou syntetické).
 * KB má „vertikální" layout — každé pole transakce na vlastním fyzickém řádku, běžný
 * zůstatek se u řádků NEuvádí (jen sloupec Připsáno/Odepsáno = částka se znaménkem).
 */
final class KbStatementPdfParserTest extends TestCase
{
    private function parser(): KbStatementPdfParser
    {
        return new KbStatementPdfParser(new NullLogger());
    }

    /** Obalí syntetické řádky transakcí do plné struktury výpisu (hlavička + rekapitulace). */
    private function statement(string $prev, string $curr, string $credit, string $debit, string $rows, string $tail = ''): string
    {
        return "Datum výpisu: 30.06.2026\n"
            . "Číslo výpisu:\t6\n"
            . "Za období: 01.06. - 30.06.2026\n"
            . "VÝPIS PERIODICKÝ\n"
            . "k účtu:12-3456789/0100\n"
            . "IBAN:CZ0001000000123456789\n"
            . "typ: PROFI ÚČET\n"
            . "měna:CZK\n"
            . "BIC / SWIFT kód: KOMBCZPPXXX\n"
            . "Počáteční zůstatek   {$prev}\n"
            . "Konečný zůstatek   {$curr}\n"
            . "POČÁTEČNÍ ZŮSTATEK   {$prev}\n"
            . "Datum\nzúčtování\nDatum\ntransakce\nPopis transakce\nIdentifikace transakce\n"
            . "Název protiúčtu / Číslo a typ karty\nProtiúčet a kód banky / Obchodní místo\nVS\nKS\nSS\nPřipsáno\nOdepsáno\n"
            . $rows . "\n"
            . "KONEČNÝ ZŮSTATEK   {$curr}\n"
            . "Rekapitulace transakcí na účtu\tPřipsáno\tOdepsáno\n"
            . "Obraty na účtu   {$credit}   -{$debit}\n"
            . "Zůstatek podle data\n"
            . $tail
            . "Vklad na tomto účtu je pojištěn.\n";
    }

    public function testParsesHeader(): void
    {
        $text = $this->statement('100 000,00', '109 999,00', '15 000,00', '5 001,00', '');
        $header = $this->parser()->parseHeaderFromText($text);

        self::assertSame('12-3456789', $header['account_number']);
        self::assertSame('2026-06-30', $header['statement_date']);
        self::assertSame('6', $header['statement_number']);
        self::assertSame('CZK', $header['currency']);
        self::assertSame(100000.0, $header['prev_balance']);
        self::assertSame(109999.0, $header['curr_balance']);
        self::assertSame(15000.0, $header['credit_total']);
        self::assertSame(5001.0, $header['debit_total']);
    }

    public function testParsesVerticalMultilineTransaction(): void
    {
        // Vertikální layout: datum zúčtování / datum transakce / popis / identifikace /
        // název protiúčtu / protiúčet / VS / KS / částka (Připsáno, bez znaménka).
        $rows = "01.06.2026\n"
              . "01.06.2026\n"
              . "PŘÍCHOZÍ ÚHRADA\n"
              . "120-20260601 PR00000000001\n"
              . "NOVAK JAN\n"
              . "1111111111/0300\n"
              . "5550045\n"
              . "308\n"
              . "             3 500,00";
        $text = $this->statement('100 000,00', '103 500,00', '3 500,00', '0,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('2026-06-01', $rowsOut[0]['posted_at']);
        self::assertSame(3500.0, $rowsOut[0]['amount']);
        self::assertSame('NOVAK JAN', $rowsOut[0]['counterparty_name']);
        self::assertSame('1111111111', $rowsOut[0]['counterparty_account']);
        self::assertSame('0300', $rowsOut[0]['counterparty_bank']);
        self::assertSame('5550045', $rowsOut[0]['variable_symbol']);
        self::assertSame('308', $rowsOut[0]['constant_symbol']);
    }

    public function testParsesCompactRowWithTypeGluedToDateAndDebitSign(): void
    {
        // Kompaktní layout: popis je nalepený za datem („05.06.2026OKAMŽITÁ ODCHOZÍ …"),
        // protiúčet + VS + částka na jednom řádku, debet nese znaménko „-".
        $rows = "05.06.2026OKAMŽITÁ ODCHOZÍ ÚHRADA\n"
              . "OI00000A00A\n"
              . "362-05062026 1602 000000 000000\n"
              . "Zpráva pro příjemce:\n"
              . "Testovaci zprava\n"
              . "670100-1234567/6210\t5550070            -5 500,00";
        $text = $this->statement('100 000,00', '94 500,00', '0,00', '5 500,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('2026-06-05', $rowsOut[0]['posted_at']);
        self::assertSame(-5500.0, $rowsOut[0]['amount']);
        self::assertSame('670100-1234567', $rowsOut[0]['counterparty_account']);
        self::assertSame('6210', $rowsOut[0]['counterparty_bank']);
        self::assertSame('5550070', $rowsOut[0]['variable_symbol']);
    }

    public function testAmountGluedToVsOnSingleLine(): void
    {
        // VS a částka slepené na jednom řádku za protiúčtem („5550099                12,00").
        $rows = "10.06.2026\n"
              . "10.06.2026\n"
              . "PŘÍCHOZÍ ÚHRADA\n"
              . "120-20260610 PR00000000002\n"
              . "DRUHA FIRMA\n"
              . "2222222222/0800\n"
              . "5550099                12,00";
        $text = $this->statement('100 000,00', '100 012,00', '12,00', '0,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(12.0, $rowsOut[0]['amount']);
        self::assertSame('5550099', $rowsOut[0]['variable_symbol']);
        self::assertSame('2222222222', $rowsOut[0]['counterparty_account']);
    }

    public function testBalanceRecapDatesAfterEndMarkerAreNotParsedAsTransactions(): void
    {
        // „Zůstatek podle data" za koncovým markerem obsahuje řádky s datem a zůstatkem
        // („01.06.2026   103 000,00") — NESMÍ se rozparsovat jako transakce. Koncový
        // marker (KONEČNÝ ZŮSTATEK / Rekapitulace / Zůstatek podle data) tabulku ukončí.
        $rows = "01.06.2026\n"
              . "01.06.2026\n"
              . "PŘÍCHOZÍ ÚHRADA\n"
              . "120-20260601 PR00000000003\n"
              . "NOVAK JAN\n"
              . "1111111111/0300\n"
              . "5550045\n"
              . "             3 000,00";
        $tail = "01.06.2026                 103 000,00\n"
              . "05.06.2026                  99 000,00\n";
        $text = $this->statement('100 000,00', '103 000,00', '3 000,00', '0,00', $rows, $tail);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(3000.0, $rowsOut[0]['amount']);
    }

    public function testEmptyMonthWithZeroTransactionsIsValid(): void
    {
        // Dormantní účet (např. spořicí) — nula transakcí, zůstatky 0,00. Self-check 0==0.
        $text = $this->statement('0,00', '0,00', '0,00', '0,00', '');
        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertSame([], $result['transactions']);
        self::assertSame(0.0, $result['header']['prev_balance']);
    }

    public function testSelfCheckRejectsWhenSumDoesNotMatchBalanceDelta(): void
    {
        // Součet částek (+3 000) nesedí na pohyb zůstatku dle hlavičky (+9 999) → zamítnout.
        $rows = "01.06.2026\n"
              . "01.06.2026\n"
              . "PŘÍCHOZÍ ÚHRADA\n"
              . "120-20260601 PR00000000004\n"
              . "NOVAK JAN\n"
              . "1111111111/0300\n"
              . "5550045\n"
              . "             3 000,00";
        $text = $this->statement('100 000,00', '109 999,00', '3 000,00', '0,00', $rows);

        $this->expectException(\RuntimeException::class);
        $this->parser()->parse('%PDF-fake', $text);
    }

    public function testSupportsDetectsKb(): void
    {
        $parser = $this->parser();
        self::assertTrue($parser->supports("VÝPIS PERIODICKÝ\nk účtu:12-3456789/0100\nBIC / SWIFT kód: KOMBCZPPXXX\n"));
        self::assertTrue($parser->supports("Komerční banka, a.s.\nVÝPIS PERIODICKÝ\n"));
        self::assertFalse($parser->supports("Nějaký jiný bankovní výpis\n"));
        self::assertFalse($parser->supports("VÝPIS Z ÚČTU\nwww.csob.cz\n"));
    }

    /** Denní výpis („VÝPIS DENNÍ PŘI POHYBU") — jeden den, bez pole „Za období". */
    private function dailyStatement(string $prev, string $curr, string $credit, string $debit, string $rows): string
    {
        return "Datum výpisu: 18.09.2026\n"
            . "Číslo výpisu:\t196\n"
            . "Strana:\t1/1\n"
            . "VÝPIS DENNÍ PŘI POHYBU\n"
            . "k účtu:123-4567890123/0100\n"
            . "IBAN:CZ0001000001234567890123\n"
            . "typ: Profi účet Gold\n"
            . "měna:CZK\n"
            . "BIC / SWIFT kód: KOMBCZPPXXX\n"
            . "Počáteční zůstatek   {$prev}\n"
            . "Konečný zůstatek   {$curr}\n"
            . "POČÁTEČNÍ ZŮSTATEK   {$prev}\n"
            . "Datum\nzúčtování\nDatum\ntransakce\nPopis transakce\nIdentifikace transakce\n"
            . "Název protiúčtu / Číslo a typ karty\nProtiúčet a kód banky / Obchodní místo\nVS\nKS\nSS\nPřipsáno\nOdepsáno\n"
            . $rows . "\n"
            . "KONEČNÝ ZŮSTATEK   {$curr}\n"
            . "Rekapitulace transakcí na účtu\tPřipsáno\tOdepsáno\n"
            . "Obraty na účtu   {$credit}   -{$debit}\n"
            . "Vklad na tomto účtu je pojištěn.\n";
    }

    public function testDailyStatementIsRecognisedAsSingleDay(): void
    {
        $text = $this->dailyStatement('1 000,00', '1 000,00', '0,00', '0,00', '');
        $header = $this->parser()->parseHeaderFromText($text);

        self::assertSame('day', $header['period_kind']);
        self::assertSame('123-4567890123', $header['account_number']);
        self::assertSame('2026-09-18', $header['statement_date']);
        self::assertTrue($this->parser()->supports($text));
    }

    public function testPeriodicStatementStaysPeriod(): void
    {
        $text = $this->statement('100 000,00', '100 000,00', '0,00', '0,00', '');
        self::assertSame('period', $this->parser()->parseHeaderFromText($text)['period_kind']);
    }

    public function testCardPaymentIsPostedOnClearingDateNotTransactionDate(): void
    {
        // Karetní platba má DVĚ data: zúčtování (18. 9.) a transakce (17. 9.). Do výpisu
        // pohyb patří dnem zúčtování — jinak vypadne z období denního výpisu.
        $rows = "18.09.2026\n"
              . "17.09.2026\n"
              . "TRANSAKCE PLATEBNÍ KARTOU\n"
              . "Nákup na internetu\n"
              . "zúčt. částka: 2 596,00 CZK\n"
              . "kurz: 1,0000\n"
              . "244-18092026 10865349648716\n"
              . "5168 93** **** 6622 ECMC\n"
              . "PRODEJNA TEST\n"
              . "PRAHA CZE\n"
              . "15500754\n"
              . "1178\n"
              . "101112001\n"
              . "-2 596,00";
        $text = $this->dailyStatement('10 000,00', '7 404,00', '0,00', '2 596,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('2026-09-18', $rowsOut[0]['posted_at']);
        self::assertSame(-2596.0, $rowsOut[0]['amount']);
        self::assertSame('6622', $rowsOut[0]['card_last4']);
        self::assertSame('PRODEJNA TEST', $rowsOut[0]['counterparty_name']);
        // Původní částka a kurz zůstávají v popisu čitelné (dřív se z nich vyřezáním
        // peněžní hodnoty staly trosky „zúčt. částka:  CZK | kurz: 00").
        self::assertStringContainsString('zúčt. částka: 2 596,00 CZK', (string) $rowsOut[0]['description']);
        self::assertStringContainsString('kurz: 1,0000', (string) $rowsOut[0]['description']);
    }

    public function testForeignCurrencyCardPaymentKeepsDebitSign(): void
    {
        // Původní částka v cizí měně je BEZ znaménka a stojí PŘED částkou v měně účtu.
        // Kdyby se brala jako částka pohybu, z výdaje by se stal příjem.
        $rows = "18.09.2026\n"
              . "17.09.2026\n"
              . "TRANSAKCE PLATEBNÍ KARTOU\n"
              . "Opakovaná platba tokenem\n"
              . "zúčt. částka: 22,63 EUR\n"
              . "kurz: 1,0000\n"
              . "244-18092026 10865349857263\n"
              . "5168 93** **** 6622 ECMC\n"
              . "SLUZBA TEST\n"
              . "4029357733 CZE\n"
              . "-549,99";
        $text = $this->dailyStatement('1 000,00', '450,01', '0,00', '549,99', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(-549.99, $rowsOut[0]['amount']);
    }

    public function testMessageForRecipientIsNotUsedAsCounterpartyName(): void
    {
        // Převod bez názvu protistrany: řádek těsně před protiúčtem je TĚLO zprávy pro
        // příjemce, ne jméno. Zpětné hledání dřív jako jméno sebralo text zprávy.
        $rows = "18.09.2026OKAMŽITÁ ODCHOZÍ ÚHRADA\n"
              . "OI0004A3T80\n"
              . "362-18092026 1602 602104 960754\n"
              . "Zpráva pro příjemce:\n"
              . "Platba faktury 5550123\n"
              . "2300057139/2010\t5550123              -181,50";
        $text = $this->dailyStatement('1 000,00', '818,50', '0,00', '181,50', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertNull($rowsOut[0]['counterparty_name']);
        self::assertSame('2300057139', $rowsOut[0]['counterparty_account']);
        self::assertSame('5550123', $rowsOut[0]['variable_symbol']);
        self::assertStringContainsString('Platba faktury 5550123', (string) $rowsOut[0]['description']);
    }

    public function testCounterpartyNameRightAboveAccountIsKept(): void
    {
        $rows = "18.09.2026PŘÍCHOZÍ ÚHRADA\n"
              . "2026091840903624072\n"
              . "361-18092026 1086 086144 585374\n"
              . "Zpráva pro příjemce:\n"
              . "5550045 TESTOVACI FIRMA S.R.O.\n"
              . "Druha Firma, s.r.o.\n"
              . "1111111111/0300\n"
              . "5550045\n"
              . "308\n"
              . "             7 033,00";
        $text = $this->dailyStatement('1 000,00', '8 033,00', '7 033,00', '0,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('Druha Firma, s.r.o.', $rowsOut[0]['counterparty_name']);
        self::assertSame('5550045', $rowsOut[0]['variable_symbol']);
        self::assertSame('308', $rowsOut[0]['constant_symbol']);
    }

    public function testForeignPaymentKeepsIbanAndNoInventedSymbols(): void
    {
        // Zahraniční platba: protiúčet je IBAN, čísla v pravém bloku jsou vlastní
        // reference banky — jako VS/KS/SS se NESMÍ použít (falešný VS páruje cizí fakturu).
        $rows = "18.09.2026UTT Europe\n"
              . "OI0004A3UFG 11\n"
              . "001-18092026 1602 602021 294431\n"
              . "SK1211000000002922893625\n"
              . "TATRSKBXXXX\n"
              . "EndToEnd Reference:\n"
              . "6020000000\n"
              . "3857316421\n"
              . "2672471\n"
              . "-256,08";
        $text = $this->dailyStatement('1 000,00', '743,92', '0,00', '256,08', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('SK1211000000002922893625', $rowsOut[0]['counterparty_account']);
        self::assertNull($rowsOut[0]['counterparty_bank']);
        self::assertNull($rowsOut[0]['variable_symbol']);
        self::assertNull($rowsOut[0]['constant_symbol']);
        self::assertNull($rowsOut[0]['specific_symbol']);
    }

    public function testDailyStatementRejectsMovementFromAnotherDay(): void
    {
        // Denní výpis se skládá do měsíce podle data zúčtování. Pohyb z jiného dne
        // znamená, že se datum vytěžilo špatně — takový výpis se NESMÍ uložit.
        $rows = "17.09.2026PŘÍCHOZÍ ÚHRADA\n"
              . "Druha Firma, s.r.o.\n"
              . "1111111111/0300\n"
              . "5550045\n"
              . "             1 000,00";
        $text = $this->dailyStatement('1 000,00', '2 000,00', '1 000,00', '0,00', $rows);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/denní výpis/u');
        $this->parser()->parse('%PDF-fake', $text);
    }

    public function testParseKeepsAccountCurrencyInHeader(): void
    {
        $result = $this->parser()->parse('%PDF-fake', $this->dailyStatement('0,00', '0,00', '0,00', '0,00', ''));
        self::assertSame('CZK', $result['header']['account_currency']);
        self::assertSame('day', $result['header']['period_kind']);
    }
}
