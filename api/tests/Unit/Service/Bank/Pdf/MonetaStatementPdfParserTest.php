<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\MonetaStatementPdfParser;
use PHPUnit\Framework\TestCase;

/**
 * PDF „Výpis z běžného účtu" MONETA. Text je VYMYŠLENÝ se zachovanou strukturou
 * textové vrstvy: blok popisků a blok hodnot v hlavičce, tabulátory mezi sloupci,
 * částka pohybu bez znaménka (sloupec debet/kredit se v textu neuchová).
 */
final class MonetaStatementPdfParserTest extends TestCase
{
    private static function statement(string $prev, string $curr, string $debit, string $credit, string $rows, int $count): string
    {
        $nb = "\u{00A0}";
        return "Výpis{$nb}z{$nb}běžného{$nb}účtu\n"
            . "Číslo{$nb}výpisu:\nVýpis{$nb}ze{$nb}dne:\nPředchozí{$nb}výpis{$nb}ze{$nb}dne:\nPeriodicita{$nb}výpisu:\nStrana:\n"
            . "2099/9\n30.09.2099\n31.08.2099\n1/1\nměsíční\n"
            . "TESTOVACI{$nb}FIRMA{$nb}s.r.o.\n"
            . "Informace{$nb}o{$nb}účtu\n"
            . "Obchodní{$nb}místo: MONETA{$nb}Money{$nb}Bank,{$nb}a.{$nb}s.{$nb}-{$nb}ONLINE{$nb}WEB\n"
            . "Bankovní{$nb}spojení:\nČíslo{$nb}účtu{$nb}IBAN:\nSWIFT{$nb}kód{$nb}BIC:\n"
            . "1000000005{$nb}/{$nb}0600\nCZ00{$nb}0600{$nb}0000{$nb}0010{$nb}0000{$nb}0005\nAGBACZPP\n"
            . "Označení{$nb}měny: CZK\n"
            . "{$nb}Počáteční{$nb}zůstatek\t{$prev}Přehled{$nb}transakcí\n"
            . "Datum Bankovní{$nb}spojení\tKód{$nb}transakce\tVS Debetní{$nb}obrat Kreditní{$nb}obrat\n"
            . "zpracování{$nb}/ Popis\tDatum{$nb}zaúčtování{$nb}/\tKS\tČástka Částka\n"
            . "Valuta\todepsání\tSS\n"
            . $rows
            . "Celkový{$nb}počet{$nb}transakcí:{$nb}{$count}\tSoučet{$nb}obratů{$nb}na{$nb}výpisu:\t{$debit} {$credit}\n"
            . "Konečný{$nb}zůstatek {$curr}\n"
            . "www.moneta.cz{$nb}|{$nb}zákaznický{$nb}servis\n";
    }

    private static function row(string $date, string $first, string $code, string $vs, string $amount, string $name, string $ks = ''): string
    {
        return "{$date} {$first}\t{$code}\t{$vs}\t{$amount}\n"
            . "{$name}\t{$date}\t{$ks}\n"
            . "{$date}\n"
            . "AV:\u{00A0}testovaci{$amount}\n";
    }

    public function testParsesCreditOnlyStatement(): void
    {
        $text = self::statement('0,00', "10\u{00A0}000,00", '0,00', "10\u{00A0}000,00",
            self::row('18.09.2099', '123456789/0300', '2609180000000000001', '5550045', "10\u{00A0}000,00", 'TESTOVACI PROTISTRANA', '0308'), 1);
        $parser = new MonetaStatementPdfParser();

        self::assertTrue($parser->supports($text));
        $result = $parser->parse('%PDF-fake', $text);

        $header = $result['header'];
        self::assertSame('1000000005', $header['account_number']);
        self::assertSame('9', $header['statement_number'], 'Pořadí výpisu v roce, jako v GPC téže banky.');
        self::assertSame('2099-09-30', $header['statement_date']);
        self::assertSame(0.0, $header['prev_balance']);
        self::assertSame(10000.0, $header['curr_balance']);
        self::assertSame(10000.0, $header['credit_total']);
        self::assertSame(0.0, $header['debit_total']);
        self::assertSame('CZK', $header['account_currency']);

        $tx = $result['transactions'][0];
        self::assertSame('2099-09-18', $tx['posted_at']);
        self::assertSame(10000.0, $tx['amount']);
        self::assertSame('CZK', $tx['currency']);
        self::assertSame('123456789', $tx['counterparty_account']);
        self::assertSame('0300', $tx['counterparty_bank']);
        self::assertSame('TESTOVACI PROTISTRANA', $tx['counterparty_name']);
        self::assertSame('5550045', $tx['variable_symbol']);
        self::assertSame('308', $tx['constant_symbol']);
        self::assertSame('2609180000000000001', $tx['bank_ref']);
    }

    public function testDirectionOfMixedMovementsFollowsStatementTurnovers(): void
    {
        $rows = self::row('03.09.2099', '123456789/0300', '1', '5550045', "1\u{00A0}000,00", 'ODBERATEL')
            . self::row('05.09.2099', '987654321/0100', '2', '5550046', '250,00', 'DODAVATEL');
        $text = self::statement('100,00', '850,00', '250,00', "1\u{00A0}000,00", $rows, 2);

        $amounts = array_column((new MonetaStatementPdfParser())->parse('%PDF-fake', $text)['transactions'], 'amount');

        self::assertSame([1000.0, -250.0], $amounts);
    }

    /** Dvě stejné částky, jedna kredit a jedna debet: z obratů nejde poznat, která je která. */
    public function testAmbiguousDirectionIsRejected(): void
    {
        $rows = self::row('03.09.2099', '123456789/0300', '1', '1', '500,00', 'A')
            . self::row('05.09.2099', '987654321/0100', '2', '2', '500,00', 'B');
        $text = self::statement('100,00', '100,00', '500,00', '500,00', $rows, 2);

        $this->expectException(\RuntimeException::class);
        (new MonetaStatementPdfParser())->parse('%PDF-fake', $text);
    }

    public function testRejectsWhenTransactionCountDiffers(): void
    {
        $text = self::statement('0,00', '250,00', '0,00', '250,00',
            self::row('03.09.2099', '123456789/0300', '1', '1', '250,00', 'A'), 3);

        $this->expectException(\RuntimeException::class);
        (new MonetaStatementPdfParser())->parse('%PDF-fake', $text);
    }

    public function testRejectsWhenTurnoversDoNotMatchBalances(): void
    {
        $text = self::statement('0,00', '999,00', '0,00', '250,00',
            self::row('03.09.2099', '123456789/0300', '1', '1', '250,00', 'A'), 1);

        $this->expectException(\RuntimeException::class);
        (new MonetaStatementPdfParser())->parse('%PDF-fake', $text);
    }
}
