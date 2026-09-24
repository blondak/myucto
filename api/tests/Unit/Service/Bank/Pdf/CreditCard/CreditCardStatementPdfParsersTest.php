<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf\CreditCard;

use MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind;
use MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry;
use MyInvoice\Service\Bank\Pdf\CreditasStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\CreditCard\CsobCreditCardStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\CreditCard\ErsteCreditCardStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\CreditCard\KbCreditCardStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\CreditCard\RaiffeisenbankCreditCardStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\CsobStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\KbStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\RaiffeisenbankStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Parsery výpisů kreditních karet (KB, Raiffeisenbank, ERSTE / Česká spořitelna, ČSOB).
 *
 * Texty jsou VYMYŠLENÉ (účty, jména, obchodníci, částky i koncovky karet jsou syntetické),
 * strukturou ale odpovídají tomu, co z reálných výpisů vrací Smalot\PdfParser::getText():
 * pořadí řádků, slepená data, opakované hlavičky stránek a patičky mezi pohyby.
 */
final class CreditCardStatementPdfParsersTest extends TestCase
{
    // ── KB ──────────────────────────────────────────────────────────────────────

    private const KB = <<<'TXT'
Datum výpisu: 05.03.2031
Číslo výpisu:	3
Strana:	1/3
Zaslání: e-mail a elektronicky
Za období: 06.02. - 05.03.2031
VÝPIS Z ÚČTU KE KREDITNÍ KARTĚ
k účtu:19-1000000005/0100
IBAN:CZ0001000000191000000005
typ: MasterCard
měna:CZK
Praha 1 - Testovací
www.kb.cz
BIC / SWIFT kód: KOMBCZPPXXX
TESTOVACÍ FIRMA S.R.O.
TESTOVACÍ 1
Úvěrový limit pro čerpání Vaší kreditní kartou k datu výpisu je 50 000,00 Kč, aktuální použitelný limit je 47 475,50 Kč. Výše dlužné částky je
2 524,50 Kč.
Přehled čerpání a poplatků
Čerpání
Vyčerpáno k datu minulého výpisu	Čerpáno	Splaceno Vyčerpáno k datu výpisu
            2 000,00            2 489,50            2 000,00            2 489,50
Poplatky (spojené s vedením a správou úvěrového účtu)	35,00
Celkem čerpání a poplatky k datu výpisu            2 524,50
DN310305_000-0000000-0   ID: 0000000001Komerční banka, a.s.
se sídlem: Praha 1, testovací adresa
Přehled transakcí (ovlivňuje limit úvěrového účtu)
Datum
zúčtování
Datum
transakce
Popis transakce
Identifikace transakce
Název protiúčtu / Číslo a typ karty
Protiúčet a kód banky / Obchodní místo
Celkem
Jistina
Úrok
Zaúčtováno
10.02.2031
09.02.2031
Nákup na internetu
zúčt. částka: 1 234,50 CZK
kurz: 1,0000
244-10022031 20000000000001
5529 00** **** 4321 ECMC
FIKTIVNI OBCHOD S.R.O.
PRAHA CZE
-1 234,50
12.02.2031
11.02.2031
Opakovaná platba
orig. částka: 20.00 USD
zúčt. částka: 17,40 EUR
kurz: 25,0000
244-12022031 20000000000002
5529 00** **** 4321 ECMC
SOFTWARE TEST INC
SAN FRANCISCO USA
-435,00
15.02.2031SPLÁTKA ÚVĚRU/ÚROKU
OI00000X001 01
001-15022031 1111 222222 333333
TESTOVACÍ FIRMA S.R.O.
19-2000000003/0100
            2 000,00
            2 000,00
                 0,00
            2 000,00
16.02.2031SPLNĚNÍ PODM ZVÝHODNĚNÍ
ÚROKŮ
001-001-997-000001
               50,00
                 0,00
               50,00
                 0,00
Pokračování na další straně.
VÝPIS Z ÚČTU KE KREDITNÍ KARTĚ
k účtu:19-1000000005/0100
měna:CZK
Datum výpisu: 05.03.2031
Za období: 06.02. - 05.03.2031Komerční banka, a.s.
TESTOVACÍ FIRMA S.R.O.
TESTOVACÍ 1
Datum
zúčtování
Popis transakce
Zaúčtováno
20.02.2031
19.02.2031
Vrácení platby
zúčt. částka: 300,00 CZK
kurz: 1,0000
244-20022031 20000000000003
5529 00** **** 4321 ECMC
FIKTIVNI OBCHOD S.R.O.
PRAHA CZE
300,00
01.03.2031ÚROK Z ÚVĚRU
001-01032031 0000 000000 000001
-120,00
02.03.2031POPLATEK ZA VEDENÍ ÚČTU
001-02032031 0000 000000 000002
-35,00
03.03.2031
03.03.2031
Výběr z bankomatu
zúčt. částka: 1 000,00 CZK
kurz: 1,0000
244-03032031 20000000000004
5529 00** **** 4321 ECMC
ATM TEST
BRNO CZE
-1 000,00
Celkem          -524,50
VÝPIS Z ÚČTU KE KREDITNÍ KARTĚ
Děkujeme za využívání našich služeb. Komerční banka, a.s.
TXT;

    public function testKbParsesHeaderBalancesAndAllKinds(): void
    {
        $parser = new KbCreditCardStatementPdfParser();
        self::assertTrue($parser->supports(self::KB));

        $r = $parser->parse('', self::KB);

        $h = $r['header'];
        self::assertSame('19-1000000005', $h['account_number']);
        self::assertSame('0100', $h['bank_code']);
        self::assertSame('credit_card', $h['account_kind']);
        self::assertSame('kb', $h['issuer']);
        self::assertSame('2031-03-05', $h['statement_date']);
        self::assertSame('3', $h['statement_number']);
        self::assertEqualsWithDelta(-2000.00, $h['prev_balance'], 0.001, 'Dluh z výpisu KB (kladný) je záporný zůstatek.');
        self::assertEqualsWithDelta(-2524.50, $h['curr_balance'], 0.001);
        self::assertEqualsWithDelta(50000.00, $h['credit_limit'], 0.001);
        self::assertSame('2031-02-06', $h['period_from']);

        $tx = $r['transactions'];
        self::assertCount(7, $tx, 'Pohyb se zaúčtovanou nulou (zvýhodnění úroků) se nezakládá.');
        self::assertSame(
            ['purchase', 'purchase', 'repayment', 'refund', 'interest', 'fee', 'cash'],
            array_column($tx, 'credit_card_kind'),
        );
        self::assertSame(['2031-02-10', '2031-02-12', '2031-02-15', '2031-02-20', '2031-03-01', '2031-03-02', '2031-03-03'], array_column($tx, 'posted_at'), 'Datum účetního případu = datum zaúčtování.');
        self::assertEqualsWithDelta(-1234.50, $tx[0]['amount'], 0.001);
        self::assertSame('4321', $tx[0]['card_last4']);
        self::assertSame('FIKTIVNI OBCHOD S.R.O.', $tx[0]['counterparty_name']);
        self::assertStringStartsWith('Nákup na internetu | d.tran. 09.02.2031', $tx[0]['description']);
        self::assertStringContainsString('orig. 20.00 USD', $tx[1]['description'], 'Původní měna zůstane v popisu.');
        self::assertEqualsWithDelta(-435.00, $tx[1]['amount'], 0.001, 'Rozhoduje částka v Kč z výpisu.');
        self::assertEqualsWithDelta(2000.00, $tx[2]['amount'], 0.001, 'U splátky jde do zůstatku poslední částka (Zaúčtováno).');
        self::assertSame('19-2000000003', $tx[2]['counterparty_account']);
        self::assertSame('0100', $tx[2]['counterparty_bank']);
        self::assertNull($tx[2]['card_last4'], 'Splátka není karetní operace.');
        self::assertSame('001-15022031 1111 222222 333333', $tx[2]['bank_ref']);
        self::assertNull($tx[4]['card_last4'], 'Úrok nesmí nést koncovku karty (mezičlen platebních karet).');
        self::assertSame('4321', $tx[6]['card_last4']);
    }

    public function testKbRejectsStatementWhoseRowsDoNotAddUp(): void
    {
        $broken = str_replace("-435,00\n", "-434,00\n", self::KB);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nesedí');
        (new KbCreditCardStatementPdfParser())->parse('', $broken);
    }

    // ── Raiffeisenbank ─────────────────────────────────────────────────────────

    private const RB = <<<'TXT'
VÝPIS Z KARTOVÉHO ÚČTU
Výpis za období 1. 2. 2031 - 28. 2. 2031
JAN TESTER
TESTOVACÍ 1
Strana 1/3
Raiffeisenbank a.s., Testovací 1, 140 00 Praha 4, zapsaná v obchodním rejstříku.
K0000001 v12.0 • 1 • 28.02.2031 06:00:00
Přehled
Zúčtovací období: 01. 02. 2031 - 28. 02. 2031
Datum příštího výpisu:	28. 03. 2031
Název kreditní karty: RB testovací kreditní karta
Úvěrový limit:	60.000,00 CZK
Referenční číslo karty:	005-3-00000001
Informace pro splátku Vaší karty
Minimální splátka	200,00 CZK
Celková splátka	1.724,00 CZK
Číslo účtu pro splátku	1000000005/5500
Variabilní symbol	0012345678
VÝPIS Z KARTOVÉHO ÚČTU
Výpis za období 1. 2. 2031 - 28. 2. 2031
Strana 2/3
Přehled čerpání za uplynulé zúčtovací období (CZK)
Předchozí stav
(čerpáno)
Připsáno Čerpáno Aktuální stav
(čerpáno)
-3.000,00 +3.250,00 -1.974,00 -1.724,00+ 0,00=	-1.724,00
Připsáno	součet připsaných částek na kartový účet (splátky, vratky, odměny, ad.).
Přehled transakcí
Držitel karty:	JAN TESTER
Číslo a název karty:	516872xxxxxx1111	RB PREMIUM karta
Datum
transakce
Datum
zaúčtování
Popis transakce	Připsáno (CZK)Čerpáno (CZK)
05. 02. 2031	VAŠE PLATBA - DĚKUJEME 	+3.000,00
06. 02. 203107. 02. 2031FIKTIVNI KAVARNA Brno
PLATBA U OBCHODNÍKA GOOGLE PAY
-150,00
08. 02. 203109. 02. 2031TEST SHOP Praha
PLATBA NA INTERNETU
-1.250,00
VÝPIS Z KARTOVÉHO ÚČTU
Výpis za období 1. 2. 2031 - 28. 2. 2031
Strana 3/3
Raiffeisenbank a.s., Testovací 1, 140 00 Praha 4, zapsaná v obchodním rejstříku.
K0000001 v12.0 • 1 • 28.02.2031 06:00:00
Držitel karty:	JAN TESTER
Číslo a název karty:	516872xxxxxx1111	RB PREMIUM karta
Datum
transakce
Datum
zaúčtování
Popis transakce	Připsáno (CZK)Čerpáno (CZK)
10. 02. 2031	INKASO CELK.DLUŽNÉ ČÁSTKY
POPLATEK ZA OSTATNÍ SLUŽBY
-29,00
12. 02. 203113. 02. 2031TEST SHOP Praha
VRÁCENÍ PLATBY
+250,00
15. 02. 2031	ÚROKY Z ČERPÁNÍ
-45,00
Celkem za kartu 516872xxxxxx1111	+3.250,00 -1.474,00
Číslo a název karty:	516872xxxxxx2222	RB karta
20. 02. 203121. 02. 2031OBCHOD DVA Plzen
PLATBA U OBCHODNÍKA
-500,00
Celkem za kartu 516872xxxxxx2222	+0,00 -500,00
Připsáno (CZK)Čerpáno (CZK)
Celkem za kartový účet	+3.250,00 -1.974,00
Informace o úrokových sazbách
TXT;

    public function testRbParsesCardsDatesRepaymentAndFee(): void
    {
        $parser = new RaiffeisenbankCreditCardStatementPdfParser();
        self::assertTrue($parser->supports(self::RB));

        $r = $parser->parse('', self::RB);

        $h = $r['header'];
        self::assertSame('005-3-00000001', $h['account_number'], 'RB účet netiskne - identifikuje ho referenční číslo karty.');
        self::assertSame('5500', $h['bank_code']);
        self::assertSame('2031-02-28', $h['statement_date']);
        self::assertEqualsWithDelta(-3000.00, $h['prev_balance'], 0.001);
        self::assertEqualsWithDelta(-1724.00, $h['curr_balance'], 0.001);
        self::assertEqualsWithDelta(60000.00, $h['credit_limit'], 0.001);
        self::assertSame('1000000005', $h['repayment_account']);
        self::assertSame('5500', $h['repayment_bank_code']);
        self::assertSame('0012345678', $h['repayment_vs']);

        $tx = $r['transactions'];
        self::assertSame(
            ['repayment', 'purchase', 'purchase', 'fee', 'refund', 'interest', 'purchase'],
            array_column($tx, 'credit_card_kind'),
        );
        self::assertSame('2031-02-07', $tx[1]['posted_at'], 'Karetní platba: druhé datum je datum zaúčtování.');
        self::assertStringContainsString('d.tran. 06.02.2031', $tx[1]['description']);
        self::assertSame('FIKTIVNI KAVARNA Brno', $tx[1]['counterparty_name']);
        self::assertSame('1111', $tx[1]['card_last4']);
        self::assertNull($tx[0]['card_last4'], 'Splátka není karetní operace.');
        self::assertNull($tx[3]['card_last4'], 'Poplatek v sekci karty nesmí nést koncovku.');
        self::assertNull($tx[3]['counterparty_name']);
        self::assertSame('2222', $tx[6]['card_last4'], 'Druhá karta účtu má vlastní koncovku.');
        self::assertEqualsWithDelta(1276.00, array_sum(array_column($tx, 'amount')), 0.001);
    }

    public function testRbRejectsMissingRow(): void
    {
        $broken = str_replace("15. 02. 2031\tÚROKY Z ČERPÁNÍ\n-45,00\n", '', self::RB);

        $this->expectException(\RuntimeException::class);
        (new RaiffeisenbankCreditCardStatementPdfParser())->parse('', $broken);
    }

    // ── ERSTE / Česká spořitelna ───────────────────────────────────────────────

    private const ERSTE = <<<'TXT'
Majitel účtu: Tester Jan
Měna účtu: CZK
ZÁKLADNÍ ÚDAJE ÚČTU
Výše úvěru:	80 000.00
Dlužná částka včetně úroků a cen:	-2 682.60
SOUHRNNÉ VYÚČTOVÁNÍ
Na účet:	1000-1000000005/0800
PŘEHLED POHYBŮ NA ÚČTU	POČÁTEČNÍ ZŮSTATEK:	-1 000.00
Zaúčtováno
Provedeno
Položka	Cizí měna Částka
KARTA ČÍSLO 4000 00xx xxxx 9999
02.03.2031 Platba kartou Brno          Fiktivni obchod
XXXXXXXXXXXX9999 d.tran.01.03.2031
-500.00
03.03.2031 Platba kartou Dublin        Test Service
XXXXXXXXXXXX9999 d.tran.02.03.2031
-10.00 EUR
-252.50
Výpis z kartového účtu
Visa Classic kreditní
Číslo účtu/kód banky: 1000-1000000005/0800
Období: 1.3.2031 - 31.3.2031
Číslo výpisu: 003Česká spořitelna, a.s., Praha 4, testovací adresa
Pokračování na další straně
Strana č.: 1/2
0000
SBKKVP_99|0000|TEST|000/0000/1/2|000-00|X_C|___|0000000000000000
Zaúčtováno
Provedeno
Položka	Cizí měna Částka
05.03.2031 Výběr z bankomatu Praha     ATM Test
XXXXXXXXXXXX9999 d.tran.04.03.2031
-2 000.00
10.03.2031 Úrok z čerpání
-35.10
11.03.2031 Cena za výpis
-15.00
12.03.2031 Platba kartou Brno          Fiktivni obchod
XXXXXXXXXXXX9999 d.tran.10.03.2031
+120.00
16.03.2031 Splátka 000000-1000000005 / 0800	+1 000.00
KONEČNÝ ZŮSTATEK:	-2 682.60
CELKOVÝ PŘEHLED
TXT;

    public function testErsteParsesForeignCurrencyCashAndRepayment(): void
    {
        $parser = new ErsteCreditCardStatementPdfParser();
        self::assertTrue($parser->supports(self::ERSTE));

        $r = $parser->parse('', self::ERSTE);

        $h = $r['header'];
        self::assertSame('1000-1000000005', $h['account_number']);
        self::assertSame('0800', $h['bank_code']);
        self::assertSame('2031-03-31', $h['statement_date']);
        self::assertSame('3', $h['statement_number']);
        self::assertEqualsWithDelta(-1000.00, $h['prev_balance'], 0.001);
        self::assertEqualsWithDelta(-2682.60, $h['curr_balance'], 0.001);
        self::assertEqualsWithDelta(80000.00, $h['credit_limit'], 0.001);

        $tx = $r['transactions'];
        self::assertSame(
            ['purchase', 'purchase', 'cash', 'interest', 'fee', 'refund', 'repayment'],
            array_column($tx, 'credit_card_kind'),
        );
        self::assertSame('Fiktivni obchod', $tx[0]['counterparty_name']);
        self::assertSame('9999', $tx[0]['card_last4']);
        self::assertStringStartsWith('Platba kartou | d.tran. 01.03.2031', $tx[0]['description']);
        self::assertEqualsWithDelta(-252.50, $tx[1]['amount'], 0.001);
        self::assertStringContainsString('-10.00 EUR', $tx[1]['description']);
        self::assertEqualsWithDelta(-35.10, $tx[3]['amount'], 0.001);
        self::assertNull($tx[3]['card_last4']);
        self::assertEqualsWithDelta(1000.00, $tx[6]['amount'], 0.001);
        self::assertSame('000000-1000000005', $tx[6]['counterparty_account'], 'Splátka nese protiúčet - pozná se jako vlastní převod.');
        self::assertSame('0800', $tx[6]['counterparty_bank']);
    }

    // ── ČSOB ───────────────────────────────────────────────────────────────────

    private const CSOB = <<<'TXT'
VÝPIS Z ÚČTU Československá obchodní banka, a. s., Radlická 333/150, 150 57 Praha 5; www.csob.cz, Infolinka 800 300 300 Období: 1. 3. 2031 - 31. 3. 2031
Účet: 1000000005/0300
Název účtu:TESTOVACÍ FIRMA
Strana: 1/1 X
Rok/č. výpisu:2031/3
BIC: CEKOCZPP
Typ účtu: Karta
Měna: CZK
Souhrnné informace
Počet kreditních položek: 	1
Počet debetních položek: 	3
Počáteční zůstatek:	-1 000,00
Konečný zůstatek:	-1 480,00
Celkové příjmy:	1 000,00
Celkové výdaje:	1 480,00
Přehled pohybů na účtu od 1. 3. 2031 do 31. 3. 2031
Datum
Valuta
Označení platby
Protiúčet nebo poznámka
Název protiúčtu
VS	KS SS
Identifikace Částka Zůstatek
03.03.Čerpání úvěru platební kartou 	1001 -1 200,00 -2 200,00
100000001 12342000000001
Místo: FIKTIVNI OBCHOD
Částka: 1200 CZK 01.03.2031
Brno
10.03.Čerpání úvěru platební kartou 	1002 -250,00 -2 450,00
100000002 12342000000001
Místo: TEST SERVICE
Částka: 10 EUR 08.03.2031
Dublin
16.03.Splátka úvěru 	TESTOVACÍ FIRMA	1003 1 000,00 -1 450,00
2000000003/0300	0968
31.03.Úrok z úvěru 	1004 -30,00 -1 480,00
Informace k plánované splátce
Úroky za běžné období:	12,00 CZK
Celkový limit:	50 000,00 CZK
Prosíme Vás o včasné překontrolování uvedených údajů.
TXT;

    public function testCsobReusesCurrentAccountParserAndAddsCardDetails(): void
    {
        $parser = new CsobCreditCardStatementPdfParser(new CsobStatementPdfParser(new NullLogger()));
        self::assertTrue($parser->supports(self::CSOB));

        $r = $parser->parse('', self::CSOB);

        $h = $r['header'];
        self::assertSame('1000000005', $h['account_number']);
        self::assertSame('0300', $h['bank_code']);
        self::assertSame('credit_card', $h['account_kind']);
        self::assertSame('2031-03-31', $h['statement_date']);
        self::assertEqualsWithDelta(-1480.00, $h['curr_balance'], 0.001);
        self::assertEqualsWithDelta(50000.00, $h['credit_limit'], 0.001);

        $tx = $r['transactions'];
        self::assertSame(['purchase', 'purchase', 'repayment', 'interest'], array_column($tx, 'credit_card_kind'));
        self::assertSame('FIKTIVNI OBCHOD', $tx[0]['counterparty_name']);
        self::assertStringStartsWith('Čerpání úvěru platební kartou | d.tran. 01.03.2031', $tx[0]['description']);
        self::assertStringContainsString('orig. 10 EUR', $tx[1]['description']);
        self::assertSame('2000000003', $tx[2]['counterparty_account']);
        self::assertStringNotContainsString('Úroky za běžné období', $tx[3]['description'], 'Souhrn splátky nepatří do posledního pohybu.');
        self::assertNull($tx[0]['card_last4'], 'ČSOB koncovku karty v kreditním výpisu netiskne.');
    }

    // ── rozpoznání a pořadí v registru ─────────────────────────────────────────

    public function testCreditCardParsersDoNotClaimCurrentAccountStatements(): void
    {
        $kbCurrent = "VÝPIS PERIODICKÝ\nKomerční banka, a.s.\nwww.kb.cz\n";
        $rbCurrent = "Výpis z účtu\nRaiffeisenbank a.s.\nwww.rb.cz\nRZBCCZPP\n";
        $csobCurrent = "VÝPIS Z ÚČTU www.csob.cz Období: 1. 6. 2031 - 30. 6. 2031\nÚčet: 1000000005/0300\n";
        $ersteCurrent = "Výpis z účtu\nČeská spořitelna, a.s.\n";

        self::assertFalse((new KbCreditCardStatementPdfParser())->supports($kbCurrent));
        self::assertFalse((new RaiffeisenbankCreditCardStatementPdfParser())->supports($rbCurrent));
        self::assertFalse((new CsobCreditCardStatementPdfParser(new CsobStatementPdfParser(new NullLogger())))->supports($csobCurrent));
        self::assertFalse((new ErsteCreditCardStatementPdfParser())->supports($ersteCurrent));
    }

    /**
     * RED bez parserů kreditních karet: parser běžného účtu ČSOB kreditní výpis přijal
     * (stejný layout) a pohyby by šly na banku 221 jako běžný účet.
     */
    public function testRegistryOrderPrefersCreditCardParsers(): void
    {
        $logger = new NullLogger();
        $registry = new BankStatementPdfParserRegistry([
            new KbCreditCardStatementPdfParser(),
            new RaiffeisenbankCreditCardStatementPdfParser(),
            new CsobCreditCardStatementPdfParser(new CsobStatementPdfParser($logger)),
            new ErsteCreditCardStatementPdfParser(),
            new CreditasStatementPdfParser($logger),
            new CsobStatementPdfParser($logger),
            new KbStatementPdfParser($logger),
            new RaiffeisenbankStatementPdfParser($logger),
        ]);

        self::assertSame('csob_credit_card', $registry->parserFor(self::CSOB)?->key());
        self::assertSame('kb_credit_card', $registry->parserFor(self::KB)?->key());
        self::assertSame('raiffeisenbank_credit_card', $registry->parserFor(self::RB)?->key());
        self::assertSame('erste_credit_card', $registry->parserFor(self::ERSTE)?->key());
    }

    // ── druh pohybu ────────────────────────────────────────────────────────────

    public function testKindUsesOnlyTransactionTypeNotMerchant(): void
    {
        self::assertSame(CreditCardTransactionKind::PURCHASE, CreditCardTransactionKind::classify('Nákup na internetu | POPLATKY.CZ', -100.0));
        self::assertSame(CreditCardTransactionKind::PURCHASE, CreditCardTransactionKind::classify('Platba kartou | Bankomat servis s.r.o.', -100.0));
        self::assertSame(CreditCardTransactionKind::REPAYMENT, CreditCardTransactionKind::classify('SPLÁTKA ÚVĚRU/ÚROKU', 500.0), 'Kladná splátka s „úrokem" v názvu je splátka.');
        self::assertSame(CreditCardTransactionKind::INTEREST, CreditCardTransactionKind::classify('Úrok z úvěru', -10.0));
        self::assertSame(CreditCardTransactionKind::FEE, CreditCardTransactionKind::classify('Cena za výpis', -15.0));
        self::assertSame(CreditCardTransactionKind::CASH, CreditCardTransactionKind::classify('Výběr z bankomatu', -1000.0));
        self::assertSame(CreditCardTransactionKind::REWARD, CreditCardTransactionKind::classify('Odměna za platby kartou', 50.0));
        self::assertSame(CreditCardTransactionKind::REWARD, CreditCardTransactionKind::classify('Moneyback 440507XXXXXX1111', 20.0), 'ERSTE: odměna jako „Moneyback", ne vratka.');
        self::assertSame(CreditCardTransactionKind::REFUND, CreditCardTransactionKind::classify('Platba kartou', 120.0));
    }
}
