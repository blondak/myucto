<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\KbAccountStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\KbStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Novější layout PDF výpisu KB („Výpis z účtu", šablona VYPIS1_NDB). Text je
 * VYMYŠLENÝ se zachovanou strukturou textové vrstvy (tabulátory mezi sloupci,
 * „-" u prázdného symbolu, typ transakce zalomený do víc řádků).
 */
final class KbAccountStatementPdfParserTest extends TestCase
{
    private const CREDIT = "10. 9. 2099 TESTOVACI PROTISTRANA s.r.o \t123456789/0300   \t1 000,00 Kč \n"
        . "Datum provedení Kód transakce Typ transakce Variabilní symbol Specifický symbol Konstantní symbol \n"
        . "10. 9. 2099 TEST000000000001 Příchozí úhrada 5550045\t-\t0308\n"
        . "Zpráva pro příjemce: testovaci platba\n";

    private const FEE = "15. 9. 2099 Komerční banka    \t-13,33 Kč \n"
        . "Datum provedení Kód transakce Typ transakce Variabilní symbol Specifický symbol Konstantní symbol \n"
        . "14. 9. 2099 TEST000000000002 Poplatek za extra \n"
        . "službu \n"
        . "Test \n"
        . "-\t-\t-\n";

    private static function statement(string $prev, string $curr, string $rows, int $count): string
    {
        return "Datum výpisu \n1. 10. 2099 \n1/1 \n"
            . "Komerční banka, a. s., se sídlem: Testovací 1, PSČ 100 00 \n"
            . "Zapsaná v obchodním rejstříku vedeném testovacím soudem \n"
            . "Id: \n   Code: VYPIS1_NDB _v 2.8   1.10.2099 10:00:00 \n"
            . "Výpis z účtu 1. 9. 2099 – 30. 9. 2099 \n"
            . "TESTOVACI FIRMA s.r.o.\n"
            . "Informace o účtu \n"
            . "Číslo účtu 1000000005/0100 \tIBAN \tCZ0001000000001000000005 \n"
            . "Hlavní měna účtu Kč \tTyp účtu Testovací účet \n"
            . "Zůstatky \tPočáteční zůstatek \tKonečný zůstatek \n"
            . "Česká koruna \t{$prev} Kč \t{$curr} Kč \n"
            . "Transakce\n"
            . $rows
            . "Celkový počet transakcí\t{$count}\n"
            . "Vklad na tomto účtu podléhá ochraně. www.kb.cz/pojistenivkladu.\n";
    }

    public function testParsesHeaderAndTransactions(): void
    {
        $text = self::statement('100,00', '1 086,67', self::CREDIT . self::FEE, 2);
        $parser = new KbAccountStatementPdfParser();

        self::assertTrue($parser->supports($text));
        $result = $parser->parse('%PDF-fake', $text);

        $header = $result['header'];
        self::assertSame('1000000005', $header['account_number']);
        self::assertSame('2099-09-30', $header['statement_date']);
        self::assertSame('', $header['statement_number']);
        self::assertSame(100.0, $header['prev_balance']);
        self::assertSame(1086.67, $header['curr_balance']);
        self::assertSame(1000.0, $header['credit_total']);
        self::assertSame(13.33, $header['debit_total']);
        self::assertSame('CZK', $header['account_currency']);
        self::assertSame('period', $header['period_kind']);

        [$credit, $fee] = $result['transactions'];
        self::assertSame('2099-09-10', $credit['posted_at']);
        self::assertSame(1000.0, $credit['amount']);
        self::assertSame('CZK', $credit['currency']);
        self::assertSame('123456789', $credit['counterparty_account']);
        self::assertSame('0300', $credit['counterparty_bank']);
        self::assertSame('TESTOVACI PROTISTRANA s.r.o', $credit['counterparty_name']);
        self::assertSame('5550045', $credit['variable_symbol']);
        self::assertNull($credit['specific_symbol']);
        self::assertSame('308', $credit['constant_symbol']);
        self::assertSame('TEST000000000001', $credit['bank_ref']);
        self::assertSame('Příchozí úhrada | Zpráva pro příjemce: testovaci platba', $credit['description']);

        // Poplatek patří do výpisu dnem zaúčtování (15. 9.), ne dnem provedení (14. 9.).
        self::assertSame('2099-09-15', $fee['posted_at']);
        self::assertSame(-13.33, $fee['amount']);
        self::assertNull($fee['counterparty_account']);
        self::assertSame('Komerční banka', $fee['counterparty_name']);
        self::assertNull($fee['variable_symbol']);
        self::assertSame('Poplatek za extra službu Test', $fee['description']);
    }

    public function testEmptyStatementIsValid(): void
    {
        $result = (new KbAccountStatementPdfParser())->parse('%PDF-fake', self::statement('100,00', '100,00', '', 0));
        self::assertSame([], $result['transactions']);
    }

    public function testRejectsWhenMovementsDoNotMatchBalances(): void
    {
        $this->expectException(\RuntimeException::class);
        (new KbAccountStatementPdfParser())->parse('%PDF-fake', self::statement('100,00', '2 000,00', self::CREDIT, 1));
    }

    public function testRejectsWhenTransactionCountDiffers(): void
    {
        $this->expectException(\RuntimeException::class);
        (new KbAccountStatementPdfParser())->parse('%PDF-fake', self::statement('100,00', '1 100,00', self::CREDIT, 2));
    }

    public function testRejectsMovementInOtherCurrency(): void
    {
        $rows = str_replace('1 000,00 Kč', '1 000,00 EUR', self::CREDIT);
        $this->expectException(\RuntimeException::class);
        (new KbAccountStatementPdfParser())->parse('%PDF-fake', self::statement('100,00', '1 100,00', $rows, 1));
    }

    /**
     * Starší parser KB nový layout „přijme" (patička Komerční banky + www.kb.cz) a pak
     * selže na chybějícím „k účtu:", proto musí nový parser stát v registru před ním.
     * Opačně nový parser starší layout nebere.
     */
    public function testRecognisesOnlyNewLayout(): void
    {
        $new = self::statement('100,00', '100,00', '', 0);
        $old = "VÝPIS PERIODICKÝ\nk účtu:12-3456789/0100\nBIC / SWIFT kód: KOMBCZPPXXX\nKomerční banka, a.s.\n";

        self::assertTrue((new KbStatementPdfParser(new NullLogger()))->supports($new));
        self::assertTrue((new KbAccountStatementPdfParser())->supports($new));
        self::assertFalse((new KbAccountStatementPdfParser())->supports($old));
    }
}
