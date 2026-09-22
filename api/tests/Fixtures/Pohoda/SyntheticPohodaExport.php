<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

/**
 * Syntetický XML export agendy z POHODY (výstup nástroje `tools/pohoda-export`) pro testy
 * převodu. Fiktivní firma, fiktivní partneři, žádná reálná data.
 *
 * Agenda roku 2026: počáteční stavy (221 100 000 proti 411), vydaná faktura 1 000 + 21 %,
 * přijatá faktura 500 + 21 %, úhrada obou bankou. Deník zapisuje strany tak, jak je
 * zapisuje POHODA: `act:credit` = MD, `act:debit` = Dal.
 */
final class SyntheticPohodaExport
{
    public const ICO = '12345678';
    public const YEAR = 2026;
    public const NAME = 'Syntetická účetní s.r.o.';
    public const CUSTOMER_ICO = '87654321';
    public const VENDOR_ICO = '11223344';
    /** Vlastní účet firmy: platné číslo (modulo 11), které jiná firma v testovací DB nedrží. */
    public const BANK_ACCOUNT = '2604000000';
    public const BANK_CODE = '0800';

    private const NS = 'xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd" '
        . 'xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" xmlns:act="http://www.stormware.cz/schema/version_2/accountancy.xsd" '
        . 'xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" xmlns:bnk="http://www.stormware.cz/schema/version_2/bank.xsd" '
        . 'xmlns:vat="http://www.stormware.cz/schema/version_2/classificationVAT.xsd" xmlns:bka="http://www.stormware.cz/schema/version_2/bankAccount.xsd" '
        . 'xmlns:adb="http://www.stormware.cz/schema/version_2/addressbook.xsd" xmlns:lAdb="http://www.stormware.cz/schema/version_2/list_addBook.xsd" '
        . 'xmlns:acu="http://www.stormware.cz/schema/version_2/accountingunit.xsd"';

    /** Karta majetku v tabulkách POHODY (`90_majetek.xml`) a její odpisy v deníku. */
    public const ASSET_NUMBER = '25IM0001';

    /** Doklad v režimu OSS (`$withOss`): 1 000 Kč + SK 23 %, odběratel bez DIČ na Slovensku. */
    public const OSS_DOCUMENT = '26FV0002';
    public const OSS_COUNTRY = 'SK';
    public const OSS_RATE = 23.0;

    /** Konečná faktura s odpočtem nedaňové zálohy (`$withAdvanceDeduction`). */
    public const ADVANCE_DOCUMENT = '26FV0003';

    /** Přijatá faktura, kterou POHODA zlikviduje až v pozdějším exportu. */
    public const LATER_PAID_DOCUMENT = '26PF0003';
    public const LATER_PAID_VS = '2026009';
    public const LATER_PAID_BANK = 'BAN0010004';
    public const LATER_OPEN = 'open';
    public const LATER_SETTLED = 'settled';

    /** Účet protistrany (dodavatele i odběratele) - ověřený placeholder, projde mod 11. */
    public const PARTNER_ACCOUNT = '1000000005';
    public const PARTNER_BANK = '0100';
    /** Jiný účet protistrany (mod 11), na přijaté faktuře neuvedený. */
    public const OTHER_ACCOUNT = '2000000050';

    /**
     * Rok po roce agendy (`$unbooked`): agenda vede i doklady následujícího roku.
     */
    public const NEXT_YEAR = self::YEAR + 1;
    /** Vydaná faktura, kterou uhradí nezaúčtovaný příjem v následujícím roce (shoda VS + částka). */
    public const UNBOOKED_ISSUED = '26FV0004';
    public const UNBOOKED_ISSUED_VS = '260004';
    /** Dvě přijaté faktury se stejnou částkou i účtem dodavatele - platba bez VS je nejednoznačná. */
    public const AMBIGUOUS_PURCHASES = ['26PF0005', '26PF0006'];
    /** Přijatá faktura, kterou jednoznačně určí účet dodavatele, částka a datum. */
    public const ACCOUNT_PURCHASE = '26PF0007';
    /** Přijatá faktura, kterou určí párovací symbol pohybu (číslo dokladu). */
    public const PARSYM_PURCHASE = '26PF0008';
    /** Přijatá faktura, jejíž VS i částku má platba kartou - karta se automaticky nepáruje. */
    public const CARD_PURCHASE = '26PF0009';
    public const CARD_VS = '2026019';
    /**
     * Vydaná faktura s datem v následujícím roce, zaúčtovaná i uhrazená v POHODĚ. Úhrada nese
     * id bankovního dokladu a opis čísla jiného pohybu - rozhodovat musí id.
     */
    public const NEXT_YEAR_ISSUED = '26FV0005';

    /**
     * Zapíše export do `$root` (kořen jako rozbalený ZIP) a vrátí složku agendy.
     *
     * `$withAssets`: navíc majetek z datového souboru POHODY - stroj za 120 000 Kč
     * zařazený 15. 1. 2025, odpisová skupina 1, účetně 24 měsíců po 5 000 Kč od ledna
     * 2025; počáteční stavy 022/082 a odpisy leden až březen 2026 v deníku.
     *
     * `$withOss`: navíc doklad v režimu OSS tak, jak ho vede POHODA - členění `UN` mimo
     * přiznání, sazba státu spotřeby (SK 23 %), odběratel bez IČ a DIČ se slovenskou
     * adresou, měna EUR. V deníku 311/604 a 311/343.090.
     *
     * `$withAdvanceDeduction`: navíc konečná faktura 1 000 + 21 % s odpočtem nedaňové
     * zálohy 1 210 Kč (k úhradě 0). Deník ji má tak, jak ji zaúčtovala POHODA u reálné
     * agendy: odpočet zálohy KLADNĚ 311/602 1 210, takže 311 z dokladu drží 2 420 Kč
     * a rozdíl dokladů proti deníku je už v POHODĚ.
     *
     * `$laterPayment`: navíc přijatá faktura 26PF0003 na 726 Kč a odchozí platba s jejím VS.
     * `open` = stav před likvidací: faktura neuhrazená, platba v bance bez zaúčtování
     * (předkontace „Nevím"), v deníku tedy chybí. `settled` = účetní platbu mezitím zaúčtovala
     * jako úhradu faktury: faktura zlikvidovaná tímto pohybem, v deníku 321/221. Minulá
     * faktura 25FV0099 je v tomtéž exportu doplacená bez pohybu v bance.
     */
    public static function write(string $root, bool $withAssets = false, bool $withOss = false, bool $withAdvanceDeduction = false, ?string $laterPayment = null, bool $unbooked = false): string
    {
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_' . self::YEAR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        self::file($root . '/00_ucetni_jednotky.xml', '<acu:listAccountingUnit version="1.1"><acu:itemAccountingUnit><acu:unitType>doubleEntry</acu:unitType>'
            . '<acu:year>' . self::YEAR . '</acu:year><acu:unitIdentity><typ:address><typ:company>' . self::NAME . '</typ:company><typ:ico>' . self::ICO . '</typ:ico></typ:address></acu:unitIdentity>'
            . '<acu:dataFile>StwPh_' . self::ICO . '_' . self::YEAR . '.mdb</acu:dataFile></acu:itemAccountingUnit></acu:listAccountingUnit>');
        foreach (self::files($withAssets, $withOss, $withAdvanceDeduction, $laterPayment, $unbooked) as $name => $body) {
            self::file($dir . '/' . $name, $body);
        }
        if ($withAssets) {
            $months = '';
            for ($i = 0; $i < 24; $i++) {
                $months .= sprintf('<IModpisM><ID>%d</ID><RefAg>1</RefAg><Mesic>%04d-%02d-01</Mesic><KcOdpis>5000</KcOdpis></IModpisM>', 100 + $i, 2025 + intdiv($i, 12), $i % 12 + 1);
            }
            file_put_contents($dir . '/90_majetek.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<mdbExport version="1" ico="' . self::ICO . '" year="' . self::YEAR . '" source="POHODA" state="ok">'
                . '<IM><ID>1</ID><Cislo>' . self::ASSET_NUMBER . '</Cislo><SText>Testovací stroj</SText><RelTpIM>1</RelTpIM><RelSkOdp>1</RelSkOdp><RelTpOdp>1</RelTpOdp>'
                . '<Datum>2025-01-15</Datum><DatZar>2025-01-15</DatZar><Kc>120000</Kc><KcDanova>120000</KcDanova></IM>'
                . '<IModpis><ID>1</ID><RefAg>1</RefAg><Rok>2025</Rok><RelUzavreno>1</RelUzavreno><KcOdpis>24000</KcOdpis></IModpis>'
                . '<IModpis><ID>2</ID><RefAg>1</RefAg><Rok>2026</Rok><KcOdpis>48000</KcOdpis></IModpis>'
                . '<IModpis><ID>3</ID><RefAg>1</RefAg><Rok>2027</Rok><KcOdpis>48000</KcOdpis></IModpis>'
                . $months
                // Drobný majetek: vrtačka z přijaté faktury (zdroj dohledaný nástrojem) a vyřazené židle bez dokladu.
                . '<IMmist><ID>1</ID><SText>Dílna</SText></IMmist>'
                . '<DM><ID>1</ID><Cislo>DM0001</Cislo><SText>Vrtačka</SText><RelTpDM>1</RelTpDM><RelAgID>2</RelAgID><RefPol>77</RefPol><Datum>2026-01-12</Datum>'
                . '<Pocet>1</Pocet><Kc>500</Kc><KcJedn>500</KcJedn><RefIMmist>1</RefIMmist>'
                . '<SrcAgenda>FA</SrcAgenda><SrcCislo>26PF0001</SrcCislo><SrcDatum>2026-01-12</SrcDatum><SrcText>Materiál</SrcText><SrcKc>500</SrcKc></DM>'
                . '<DM><ID>2</ID><Cislo>DM0002</Cislo><SText>Židle</SText><Datum>2025-06-01</Datum><Pocet>2</Pocet><Kc>3000</Kc><KcJedn>1500</KcJedn>'
                . '<DatLikv>2026-02-01</DatLikv></DM>'
                // Bez odkazu na doklad: naváže se podle data a částky na položku faktury 26PF0002.
                . '<DM><ID>3</ID><Cislo>DM0003</Cislo><SText>Sada nářadí</SText><Datum>2026-02-05</Datum><Pocet>1</Pocet><Kc>1200</Kc><KcJedn>1200</KcJedn></DM>'
                . '</mdbExport>');
        }
        return $dir;
    }

    /**
     * Agenda následujícího roku po agendě `write(..., unbooked: true)`: POHODA do ní převzala
     * doklady, které vedla už agenda minulého roku (vydaná faktura s úhradou a nezaúčtovaný
     * příjem), a přibyl nezaúčtovaný pohyb se stejným číslem jako zaúčtovaný poplatek minulého
     * roku (číselná řada banky se po letech opakuje).
     */
    public static function writeNextYear(string $root): string
    {
        $year = self::NEXT_YEAR;
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_' . $year;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        self::file($root . '/00_ucetni_jednotky.xml', '<acu:listAccountingUnit version="1.1"><acu:itemAccountingUnit><acu:unitType>doubleEntry</acu:unitType>'
            . '<acu:year>' . $year . '</acu:year><acu:unitIdentity><typ:address><typ:company>' . self::NAME . '</typ:company><typ:ico>' . self::ICO . '</typ:ico></typ:address></acu:unitIdentity>'
            . '<acu:dataFile>StwPh_' . self::ICO . '_' . $year . '.mdb</acu:dataFile></acu:itemAccountingUnit></acu:listAccountingUnit>');
        $all = self::files(unbooked: true);
        $journal = self::entry('Vydané faktury', self::NEXT_YEAR_ISSUED, 'Služby', 1000, '311001', '602000', $year . '-01-10')
            . self::entry('Vydané faktury', self::NEXT_YEAR_ISSUED, 'DPH', 210, '311001', '343021', $year . '-01-10')
            . self::entry('Banka', 'BAN0010016', 'Úhrada ' . self::NEXT_YEAR_ISSUED, 1210, '221001', '311001', $year . '-01-20');
        $files = [
            '01_ucetni_denik.xml' => '<lst:listAccountancy version="2.0" dateTimeStamp="' . $year . '-03-01T10:00:00" state="ok"><lst:accountancy version="2.0">' . $journal . '</lst:accountancy></lst:listAccountancy>',
            '12_faktury_issuedInvoice.xml' => '<lst:listInvoice version="2.0" state="ok">'
                . self::invoice('issuedInvoice', self::NEXT_YEAR_ISSUED, '260005', $year . '-01-10', 1000, 210, '', 'BAN0010016', 'BAN0010003') . '</lst:listInvoice>',
            '29_banka.xml' => '<lst:listBank version="2.0" state="ok">'
                . self::bank('BAN0010011', 'receipt', $year . '-01-15', 1815, self::UNBOOKED_ISSUED_VS, 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010016', 'receipt', $year . '-01-20', 1210, '260005', 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010003', 'expense', $year . '-02-01', 80, '', 'Testovací banka', self::OTHER_ACCOUNT) . '</lst:listBank>',
        ];
        foreach (['02_uctova_osnova.xml', '03_predkontace_pu.xml', '06_cleneni_dph.xml', '30_adresar.xml', '31_bankovni_ucty.xml'] as $name) {
            $files[$name] = $all[$name];
        }
        foreach ($files as $name => $body) {
            self::file($dir . '/' . $name, $body);
        }
        return $dir;
    }

    /**
     * Agenda z {@see write()} s kráceným odpočtem (§ 76): členění `PK` (ř. 40, 41) a přijatá
     * faktura 26PF0001 zařazená do něj místo `PD`.
     */
    public static function withReducedDeduction(string $agendaDir): void
    {
        $class = '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>PK</vat:code><vat:name>Tuzemsky odpocet kraceny</vat:name>'
            . '<vat:lineInVATReturn>40, 41</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>B.2., B.3.</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>';
        $classes = $agendaDir . '/06_cleneni_dph.xml';
        file_put_contents($classes, str_replace('</lst:listClassificationVAT>', $class . '</lst:listClassificationVAT>', (string) file_get_contents($classes)));
        $received = $agendaDir . '/20_faktury_receivedInvoice.xml';
        file_put_contents($received, str_replace('<typ:ids>PD</typ:ids>', '<typ:ids>PK</typ:ids>', (string) file_get_contents($received)));
    }

    /**
     * Agenda z {@see write()} s pořízením majetku: členění `PDM` (ř. 40, 41, 47) a přijatá
     * faktura 26PF0001 zařazená do něj místo `PD`.
     */
    public static function withFixedAssetPurchase(string $agendaDir): void
    {
        $class = '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>PDM</vat:code><vat:name>Tuzemsky odpocet porizeni majetku</vat:name>'
            . '<vat:lineInVATReturn>40, 41, 47</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>B.2., B.3.</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>';
        $classes = $agendaDir . '/06_cleneni_dph.xml';
        file_put_contents($classes, str_replace('</lst:listClassificationVAT>', $class . '</lst:listClassificationVAT>', (string) file_get_contents($classes)));
        $received = $agendaDir . '/20_faktury_receivedInvoice.xml';
        file_put_contents($received, str_replace('<typ:ids>PD</typ:ids>', '<typ:ids>PDM</typ:ids>', (string) file_get_contents($received)));
    }

    /** Číslo vydané faktury v tuzemském přenesení daňové povinnosti ({@see withDomesticReverseSale()}). */
    public const REVERSE_SALE = '26FV0009';

    /**
     * Agenda z {@see write()} s vydanou fakturou 26FV0009 na 1 000 Kč bez daně v členění
     * `UDpdp` (ř. 25, tuzemské přenesení daňové povinnosti) a jejím zápisem v deníku.
     */
    public static function withDomesticReverseSale(string $agendaDir): void
    {
        $append = static function (string $file, string $closing, string $xml): void {
            $content = (string) file_get_contents($file);
            file_put_contents($file, str_replace($closing, (string) iconv('UTF-8', 'Windows-1250', $xml) . $closing, $content));
        };
        $append($agendaDir . '/06_cleneni_dph.xml', '</lst:listClassificationVAT>',
            '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>UDpdp</vat:code><vat:name>Přenesení daňové povinnosti</vat:name>'
            . '<vat:lineInVATReturn>25</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>A.1.</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>');
        $append($agendaDir . '/12_faktury_issuedInvoice.xml', '</lst:listInvoice>',
            str_replace('<typ:ids>UD</typ:ids>', '<typ:ids>UDpdp</typ:ids>', self::invoice('issuedInvoice', self::REVERSE_SALE, '260009', '2026-01-18', 1000, 0)));
        $append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Vydané faktury', self::REVERSE_SALE, 'Stavební práce', 1000, '311001', '602000', '2026-01-18'));
    }

    /** ZIP exportu tak, jak ho zabalí nástroj (kořen s přehledem jednotek a složka agendy). */
    public static function writeZip(string $zipPath, string $workDir): void
    {
        self::write($workDir);
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($workDir . '/00_ucetni_jednotky.xml', '00_ucetni_jednotky.xml');
        foreach (glob($workDir . '/' . self::ICO . '_' . self::YEAR . '/*.xml') ?: [] as $file) {
            $zip->addFile($file, self::ICO . '_' . self::YEAR . '/' . basename($file));
        }
        $zip->addFromString('souhrn.txt', 'Export z POHODY');
        $zip->close();
    }

    /** @return array<string,string> soubor => obsah listu */
    private static function files(bool $withAssets = false, bool $withOss = false, bool $withAdvanceDeduction = false, ?string $laterPayment = null, bool $unbooked = false): array
    {
        $journal = self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 100000, '221001', '701000', '2026-01-01')
            . self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 100000, '701000', '411000', '2026-01-01')
            . self::entry('Vydané faktury', '26FV0001', 'Služby', 1000, '311001', '602000', '2026-01-10')
            . self::entry('Vydané faktury', '26FV0001', 'DPH', 210, '311001', '343021', '2026-01-10')
            . self::entry('Přijaté faktury', '26PF0001', 'Materiál', 500, '518000', '321001', '2026-01-12')
            . self::entry('Přijaté faktury', '26PF0001', 'DPH', 105, '343011', '321001', '2026-01-12')
            . self::entry('Banka', 'BAN0010001', 'Úhrada 26FV0001', 1210, '221001', '311001', '2026-01-15')
            . self::entry('Banka', 'BAN0010002', 'Úhrada 26PF0001', 605, '321001', '221001', '2026-01-20')
            . self::entry('Banka', 'BAN0010003', 'Poplatek za vedení účtu', 50, '568000', '221001', '2026-01-31');
        if ($withAssets) {
            $journal .= self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 120000, '022001', '701000', '2026-01-01')
                . self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 60000, '701000', '082001', '2026-01-01')
                . self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 60000, '701000', '411000', '2026-01-01');
            foreach (['2026-01-31', '2026-02-28', '2026-03-31'] as $date) {
                $journal .= self::entry('Dlouhodobý majetek', self::ASSET_NUMBER, 'Odpis - Testovací stroj', 5000, '551000', '082001', $date);
            }
            $journal .= self::entry('Přijaté faktury', '26PF0002', 'Nářadí', 1200, '518000', '321001', '2026-02-05')
                . self::entry('Přijaté faktury', '26PF0002', 'DPH', 252, '343011', '321001', '2026-02-05');
        }
        if ($withOss) {
            $journal .= self::entry('Vydané faktury', '26FV0002', 'Zboží na dálku SK', 1000, '311001', '604000', '2026-02-10')
                . self::entry('Vydané faktury', '26FV0002', 'DPH OSS', 230, '311001', '343090', '2026-02-10');
        }
        $settled = $laterPayment === self::LATER_SETTLED;
        if ($laterPayment !== null) {
            $journal .= self::entry('Přijaté faktury', self::LATER_PAID_DOCUMENT, 'Servis', 600, '518000', '321001', '2026-01-25')
                . self::entry('Přijaté faktury', self::LATER_PAID_DOCUMENT, 'DPH', 126, '343011', '321001', '2026-01-25');
            if ($settled) {
                $journal .= self::entry('Banka', self::LATER_PAID_BANK, 'Úhrada ' . self::LATER_PAID_DOCUMENT, 726, '321001', '221001', '2026-01-28');
            }
        }
        if ($unbooked) {
            $next = self::NEXT_YEAR;
            $journal .= self::entry('Vydané faktury', self::UNBOOKED_ISSUED, 'Služby', 1500, '311001', '602000', '2026-11-20')
                . self::entry('Vydané faktury', self::UNBOOKED_ISSUED, 'DPH', 315, '311001', '343021', '2026-11-20');
            foreach ([[self::AMBIGUOUS_PURCHASES[0], 300, '2026-03-01'], [self::AMBIGUOUS_PURCHASES[1], 300, '2026-03-02'],
                [self::ACCOUNT_PURCHASE, 200, '2026-04-01'], [self::PARSYM_PURCHASE, 400, '2026-05-01'], [self::CARD_PURCHASE, 100, '2026-05-20']] as [$number, $base, $date]) {
                $journal .= self::entry('Přijaté faktury', $number, 'Služby', $base, '518000', '321001', $date)
                    . self::entry('Přijaté faktury', $number, 'DPH', $base * 0.21, '343011', '321001', $date);
            }
            // Doklady následujícího roku, které agenda vede a POHODA je i zaúčtovala.
            $journal .= self::entry('Vydané faktury', self::NEXT_YEAR_ISSUED, 'Služby', 1000, '311001', '602000', $next . '-01-10')
                . self::entry('Vydané faktury', self::NEXT_YEAR_ISSUED, 'DPH', 210, '311001', '343021', $next . '-01-10')
                . self::entry('Banka', 'BAN0010016', 'Úhrada ' . self::NEXT_YEAR_ISSUED, 1210, '221001', '311001', $next . '-01-20');
        }
        if ($withAdvanceDeduction) {
            $journal .= self::entry('Vydané faktury', self::ADVANCE_DOCUMENT, 'Služby', 1000, '311001', '602000', '2026-03-02')
                . self::entry('Vydané faktury', self::ADVANCE_DOCUMENT, 'DPH', 210, '311001', '343021', '2026-03-02')
                . self::entry('Vydané faktury', self::ADVANCE_DOCUMENT, 'Tržby z prodeje služeb', 1210, '311001', '602000', '2026-03-02');
        }

        $accounts = '';
        if ($withOss) {
            foreach (['604000' => 'Tržby za zboží', '343090' => 'DPH OSS'] as $code => $name) {
                $accounts .= '<lst:itemAccount id="' . $code . '" code="' . $code . '" name="' . $name . '"/>';
            }
        }
        if ($withAssets) {
            foreach (['022001' => 'Stroje', '042000' => 'Pořízení majetku', '082001' => 'Oprávky ke strojům', '551000' => 'Odpisy'] as $code => $name) {
                $accounts .= '<lst:itemAccount id="' . $code . '" code="' . $code . '" name="' . $name . '"/>';
            }
        }
        foreach (['221001' => 'Bankovní účet', '311001' => 'Odběratelé', '321001' => 'Dodavatelé', '343011' => 'DPH vstup', '343021' => 'DPH výstup',
            '411000' => 'Základní kapitál', '518000' => 'Ostatní služby', '568000' => 'Ostatní finanční náklady', '602000' => 'Tržby z prodeje služeb',
            '701000' => 'Počáteční účet rozvažný'] as $code => $name) {
            $accounts .= '<lst:itemAccount id="' . $code . '" code="' . $code . '" name="' . $name . '"/>';
        }

        $classes = '';
        foreach ([['UD', 'Tuzemské plnění', '1, 2', 'A.4., A.5.'], ['UDA5', 'Tuzemské plnění pod limit', '1, 2', 'A.5.'], ['PD', 'Tuzemské plnění', '40, 41', 'B.2., B.3.'],
            ['UN', 'Nezahrnovat do přiznání DPH', '---', 'Nezahrnovat'], ['PN', 'Nezahrnovat do přiznání DPH', '---', 'Nezahrnovat'],
            ['PDslRegEU', 'Služba z EU', '43, 44', 'Nezahrnovat'], ['DDslRegEU', 'Přijetí služby z EU', '5, 6', 'A.2.']] as [$code, $name, $lines, $section]) {
            $classes .= '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>' . $code . '</vat:code><vat:name>' . $name . '</vat:name>'
                . '<vat:lineInVATReturn>' . $lines . '</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>' . $section . '</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>';
        }

        $issued = '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType><inv:number><typ:numberRequested>26FV0001</typ:numberRequested></inv:number>'
            . '<inv:symVar>260001</inv:symVar><inv:date>2026-01-10</inv:date><inv:dateTax>2026-01-10</inv:dateTax><inv:dateAccounting>2026-01-10</inv:dateAccounting><inv:dateDue>2026-01-24</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>UD</typ:ids></inv:classificationVAT><inv:text>Fakturujeme Vám služby</inv:text>'
            . self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO)
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType><inv:liquidation><typ:date>2026-01-15</typ:date></inv:liquidation></inv:invoiceHeader>'
            . '<inv:invoiceDetail><inv:invoiceItem><inv:text>Služby</inv:text><inv:quantity>2.0</inv:quantity><inv:rateVAT value="21">high</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>500</typ:unitPrice><typ:price>1000</typ:price><typ:priceVAT>210</typ:priceVAT><typ:priceSum>1210</typ:priceSum></inv:homeCurrency></inv:invoiceItem></inv:invoiceDetail>'
            . self::summary('inv', 1000, 210)
            . '<inv:liquidations><typ:liquidation><typ:id>1</typ:id><typ:date>2026-01-15</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda><typ:sourceDocument><typ:number>BAN0010001</typ:number></typ:sourceDocument><typ:amount>1210</typ:amount></typ:liquidation></inv:liquidations></lst:invoice>';

        // Neuhrazená faktura z minulého roku, kterou POHODA přenesla do agendy: v deníku roku
        // zápis nemá, zůstatek je v počátečních stavech.
        $issued .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType><inv:number><typ:numberRequested>25FV0099</typ:numberRequested></inv:number>'
            . '<inv:symVar>250099</inv:symVar><inv:date>2025-12-20</inv:date><inv:dateTax>2025-12-20</inv:dateTax><inv:dateAccounting>2025-12-20</inv:dateAccounting><inv:dateDue>2026-01-03</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>UN</typ:ids></inv:classificationVAT><inv:text>Služby z minulého roku</inv:text>'
            . self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO)
            . ($settled
                ? '<inv:liquidation><typ:amountHome>0</typ:amountHome><typ:date>2026-01-05</typ:date></inv:liquidation></inv:invoiceHeader>'
                : '<inv:liquidation><typ:amountHome>500</typ:amountHome></inv:liquidation></inv:invoiceHeader>')
            . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>500</typ:priceNone><typ:priceHigh>0</typ:priceHigh><typ:priceHighVAT rate="21">0</typ:priceHighVAT>'
            . '<typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency></inv:invoiceSummary></lst:invoice>';

        // Prodej zboží na dálku koncovému zákazníkovi na Slovensko: vlastní členění mimo
        // české přiznání, sazba státu spotřeby, odběratel bez IČ a DIČ, doklad v EUR.
        // Přesně takhle se OSS v POHODĚ vede.
        if ($withOss) {
            $issued .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . self::OSS_DOCUMENT . '</typ:numberRequested></inv:number>'
                . '<inv:symVar>260002</inv:symVar><inv:date>2026-02-10</inv:date><inv:dateTax>2026-02-10</inv:dateTax><inv:dateAccounting>2026-02-10</inv:dateAccounting><inv:dateDue>2026-02-24</inv:dateDue>'
                . '<inv:classificationVAT><typ:ids>UN</typ:ids></inv:classificationVAT><inv:text>Prodej zboží na dálku - SK</inv:text>'
                . '<inv:partnerIdentity><typ:address><typ:name>Jana</typ:name><typ:surname>Testovacia</typ:surname><typ:city>Bratislava</typ:city>'
                . '<typ:street>Testovacia 1</typ:street><typ:zip>81101</typ:zip><typ:country><typ:ids>SK</typ:ids></typ:country></typ:address></inv:partnerIdentity>'
                . '<inv:liquidation><typ:amountHome>1230</typ:amountHome></inv:liquidation></inv:invoiceHeader>'
                . '<inv:invoiceDetail><inv:invoiceItem><inv:text>Zboží</inv:text><inv:quantity>1.0</inv:quantity><inv:unit>ks</inv:unit><inv:rateVAT value="23">high</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice><typ:price>1000</typ:price><typ:priceVAT>230</typ:priceVAT><typ:priceSum>1230</typ:priceSum></inv:homeCurrency></inv:invoiceItem></inv:invoiceDetail>'
                . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>0</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT rate="12">0</typ:priceLowVAT>'
                . '<typ:priceLowSum>0</typ:priceLowSum><typ:priceHigh>1000</typ:priceHigh><typ:priceHighVAT rate="23">230</typ:priceHighVAT>'
                . '<typ:priceHighSum>1230</typ:priceHighSum><typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency>'
                . '<inv:foreignCurrency><typ:currency><typ:ids>EUR</typ:ids></typ:currency><typ:rate>25</typ:rate><typ:priceSum>49.20</typ:priceSum></inv:foreignCurrency></inv:invoiceSummary></lst:invoice>';
        }

        if ($withAdvanceDeduction) {
            $issued .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . self::ADVANCE_DOCUMENT . '</typ:numberRequested></inv:number>'
                . '<inv:symVar>260003</inv:symVar><inv:date>2026-03-02</inv:date><inv:dateTax>2026-03-02</inv:dateTax><inv:dateAccounting>2026-03-02</inv:dateAccounting><inv:dateDue>2026-03-16</inv:dateDue>'
                . '<inv:classificationVAT><typ:ids>UD</typ:ids></inv:classificationVAT><inv:text>Vyúčtování služeb po záloze</inv:text>'
                . self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO)
                . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType><inv:liquidation><typ:amountHome>0</typ:amountHome><typ:date>2026-03-02</typ:date></inv:liquidation></inv:invoiceHeader>'
                . '<inv:invoiceDetail><inv:invoiceItem><inv:text>Služby</inv:text><inv:quantity>1.0</inv:quantity><inv:rateVAT value="21">high</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice><typ:price>1000</typ:price><typ:priceVAT>210</typ:priceVAT><typ:priceSum>1210</typ:priceSum></inv:homeCurrency></inv:invoiceItem>'
                . '<inv:invoiceAdvancePaymentItem><inv:text>Uhrazená záloha</inv:text><inv:quantity>1.0</inv:quantity><inv:rateVAT value="0">none</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>-1210</typ:unitPrice><typ:price>-1210</typ:price><typ:priceVAT>0</typ:priceVAT><typ:priceSum>-1210</typ:priceSum></inv:homeCurrency>'
                . '<inv:sourceDocument><typ:number>26ZF0001</typ:number></inv:sourceDocument></inv:invoiceAdvancePaymentItem></inv:invoiceDetail>'
                . self::summary('inv', 1000, 210) . '</lst:invoice>';
        }

        $received = '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>26PF0001</typ:numberRequested></inv:number>'
            . '<inv:symVar>2026007</inv:symVar><inv:originalDocument>D-2026-7</inv:originalDocument><inv:date>2026-01-12</inv:date><inv:dateTax>2026-01-12</inv:dateTax>'
            . '<inv:dateAccounting>2026-01-12</inv:dateAccounting><inv:dateDue>2026-01-26</inv:dateDue><inv:dateKHDPH>2026-01-12</inv:dateKHDPH>'
            . '<inv:classificationVAT><typ:ids>PD</typ:ids></inv:classificationVAT><inv:text>Materiál</inv:text>'
            . self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO)
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType><inv:paymentAccount><typ:accountNo>1000000005</typ:accountNo><typ:bankCode>0100</typ:bankCode></inv:paymentAccount>'
            . '<inv:liquidation><typ:date>2026-01-20</typ:date></inv:liquidation></inv:invoiceHeader>'
            . self::summary('inv', 500, 105)
            . '<inv:liquidations><typ:liquidation><typ:id>2</typ:id><typ:date>2026-01-20</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda><typ:sourceDocument><typ:number>BAN0010002</typ:number></typ:sourceDocument><typ:amount>605</typ:amount></typ:liquidation></inv:liquidations></lst:invoice>';
        if ($withAssets) {
            // Neuhrazená faktura za nářadí - na její položku se naváže karta drobného majetku DM0003.
            $received .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>26PF0002</typ:numberRequested></inv:number>'
                . '<inv:symVar>2026008</inv:symVar><inv:originalDocument>D-2026-8</inv:originalDocument><inv:date>2026-02-05</inv:date><inv:dateTax>2026-02-05</inv:dateTax>'
                . '<inv:dateAccounting>2026-02-05</inv:dateAccounting><inv:dateDue>2026-02-19</inv:dateDue><inv:dateKHDPH>2026-02-05</inv:dateKHDPH>'
                . '<inv:classificationVAT><typ:ids>PD</typ:ids></inv:classificationVAT><inv:text>Nářadí</inv:text>'
                . self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO)
                . '<inv:liquidation><typ:amountHome>1452</typ:amountHome></inv:liquidation></inv:invoiceHeader>'
                . self::summary('inv', 1200, 252) . '</lst:invoice>';
        }

        if ($unbooked) {
            $issued .= self::invoice('issuedInvoice', self::UNBOOKED_ISSUED, self::UNBOOKED_ISSUED_VS, '2026-11-20', 1500, 315)
                . self::invoice('issuedInvoice', self::NEXT_YEAR_ISSUED, '260005', self::NEXT_YEAR . '-01-10', 1000, 210, '', 'BAN0010016', 'BAN0010003');
            $received .= self::invoice('receivedInvoice', self::AMBIGUOUS_PURCHASES[0], '2026015', '2026-03-01', 300, 63, self::PARTNER_ACCOUNT)
                . self::invoice('receivedInvoice', self::AMBIGUOUS_PURCHASES[1], '2026016', '2026-03-02', 300, 63, self::PARTNER_ACCOUNT)
                . self::invoice('receivedInvoice', self::ACCOUNT_PURCHASE, '2026017', '2026-04-01', 200, 42, self::PARTNER_ACCOUNT)
                . self::invoice('receivedInvoice', self::PARSYM_PURCHASE, '2026018', '2026-05-01', 400, 84)
                . self::invoice('receivedInvoice', self::CARD_PURCHASE, self::CARD_VS, '2026-05-20', 100, 21);
        }
        if ($laterPayment !== null) {
            $received .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . self::LATER_PAID_DOCUMENT . '</typ:numberRequested></inv:number>'
                . '<inv:symVar>' . self::LATER_PAID_VS . '</inv:symVar><inv:originalDocument>D-2026-9</inv:originalDocument><inv:date>2026-01-25</inv:date><inv:dateTax>2026-01-25</inv:dateTax>'
                . '<inv:dateAccounting>2026-01-25</inv:dateAccounting><inv:dateDue>2026-02-08</inv:dateDue><inv:dateKHDPH>2026-01-25</inv:dateKHDPH>'
                . '<inv:classificationVAT><typ:ids>PD</typ:ids></inv:classificationVAT><inv:text>Servis</inv:text>'
                . self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO)
                . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType>'
                . ($settled
                    ? '<inv:liquidation><typ:amountHome>0</typ:amountHome><typ:date>2026-01-28</typ:date></inv:liquidation></inv:invoiceHeader>'
                    : '<inv:liquidation><typ:amountHome>726</typ:amountHome></inv:liquidation></inv:invoiceHeader>')
                . self::summary('inv', 600, 126)
                . ($settled
                    ? '<inv:liquidations><typ:liquidation><typ:id>3</typ:id><typ:date>2026-01-28</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda><typ:sourceDocument><typ:number>'
                        . self::LATER_PAID_BANK . '</typ:number></typ:sourceDocument><typ:amount>726</typ:amount></typ:liquidation></inv:liquidations>'
                    : '')
                . '</lst:invoice>';
        }

        // POHODA zakládá na začátku roku doklad počátečního stavu účtu: bez čísla, bez výpisu
        // a bez zaúčtování. Není to pohyb, stav už nese deník.
        $bank = '<lst:bank version="2.0"><bnk:bankHeader><bnk:bankType>receipt</bnk:bankType><bnk:account><typ:ids>BAN</typ:ids></bnk:account>'
            . '<bnk:dateStatement>2026-01-01</bnk:dateStatement><bnk:datePayment>2026-01-01</bnk:datePayment>'
            . '<bnk:accounting><typ:ids>Bez</typ:ids><typ:accountingType>withoutAccounting</typ:accountingType></bnk:accounting>'
            . '<bnk:text>Počáteční stav bankovního účtu BAN</bnk:text></bnk:bankHeader>'
            . '<bnk:bankSummary><bnk:homeCurrency><typ:priceNone>100000</typ:priceNone><typ:priceLowSum>0</typ:priceLowSum><typ:priceHighSum>0</typ:priceHighSum>'
            . '<typ:round><typ:priceRound>0</typ:priceRound></typ:round></bnk:homeCurrency></bnk:bankSummary></lst:bank>'
            . self::bank('BAN0010001', 'receipt', '2026-01-15', 1210, '260001', 'Odběratel Test s.r.o.')
            . self::bank('BAN0010002', 'expense', '2026-01-20', 605, '2026007', 'Dodavatel Test s.r.o.')
            // Poplatek bez dokladu: v Pohodě zaúčtovaný, fakturu nemá.
            . self::bank('BAN0010003', 'expense', '2026-01-31', 50, '', 'Testovací banka');
        if ($laterPayment !== null) {
            $bank .= self::bank(self::LATER_PAID_BANK, 'expense', '2026-01-28', 726, self::LATER_PAID_VS, 'Dodavatel Test s.r.o.', self::PARTNER_ACCOUNT);
        }
        if ($unbooked) {
            // Pohyby bez zaúčtování (předkontace „Nevím"): úhrada vydané faktury v následujícím
            // roce podle VS, platba bez VS dvou stejných faktur, platba podle účtu dodavatele,
            // platba s párovacím symbolem a platba kartou bez účtu protistrany.
            $bank .= self::bank('BAN0010011', 'receipt', self::NEXT_YEAR . '-01-15', 1815, self::UNBOOKED_ISSUED_VS, 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010012', 'expense', '2026-03-10', 363, '', 'Dodavatel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010013', 'expense', '2026-04-10', 242, '', 'Dodavatel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010014', 'expense', '2026-05-05', 484, '', 'Dodavatel Test s.r.o.', self::OTHER_ACCOUNT, self::PARSYM_PURCHASE)
                . self::bank('BAN0010015', 'expense', '2026-06-01', 121, self::CARD_VS, 'Obchod kartou')
                . self::bank('BAN0010016', 'receipt', self::NEXT_YEAR . '-01-20', 1210, '260005', 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT);
        }

        $addressbook = '';
        foreach ([[1, 'Odběratel Test s.r.o.', self::CUSTOMER_ICO], [2, 'Dodavatel Test s.r.o.', self::VENDOR_ICO]] as [$id, $name, $ico]) {
            $addressbook .= '<lAdb:addressbook version="2.0"><adb:addressbookHeader><adb:id>' . $id . '</adb:id><adb:identity><typ:address><typ:company>' . $name . '</typ:company>'
                . '<typ:city>Brno</typ:city><typ:street>Testovací 1</typ:street><typ:zip>60200</typ:zip><typ:ico>' . $ico . '</typ:ico><typ:dic>CZ' . $ico . '</typ:dic></typ:address></adb:identity>'
                . '<adb:email>test@example.invalid</adb:email></adb:addressbookHeader></lAdb:addressbook>';
        }

        return [
            '01_ucetni_denik.xml' => '<lst:listAccountancy version="2.0" dateTimeStamp="2026-02-01T10:00:00" state="ok"><lst:accountancy version="2.0">' . $journal . '</lst:accountancy></lst:listAccountancy>',
            '02_uctova_osnova.xml' => '<lst:listAccount version="1.1" state="ok">' . $accounts . '</lst:listAccount>',
            '03_predkontace_pu.xml' => '<lst:listAccounting version="1.1" state="ok"><lst:itemAccounting id="1" code="1Fv" accounting="Tržby za služby" debit="311001" credit="602000" agenda="issuedInvoice"/></lst:listAccounting>',
            '06_cleneni_dph.xml' => '<lst:listClassificationVAT version="2.0" state="ok">' . $classes . '</lst:listClassificationVAT>',
            '12_faktury_issuedInvoice.xml' => '<lst:listInvoice version="2.0" state="ok">' . $issued . '</lst:listInvoice>',
            '20_faktury_receivedInvoice.xml' => '<lst:listInvoice version="2.0" state="ok">' . $received . '</lst:listInvoice>',
            '29_banka.xml' => '<lst:listBank version="2.0" state="ok">' . $bank . '</lst:listBank>',
            '30_adresar.xml' => '<lAdb:listAddressBook version="2.0" state="ok">' . $addressbook . '</lAdb:listAddressBook>',
            '31_bankovni_ucty.xml' => '<lst:listBankAccount version="2.0" state="ok"><lst:bankAccount version="2.0"><bka:bankAccountHeader><bka:ids>BAN</bka:ids>'
                . '<bka:numberAccount>' . self::BANK_ACCOUNT . '</bka:numberAccount><bka:codeBank>' . self::BANK_CODE . '</bka:codeBank><bka:nameBank>Testovací banka</bka:nameBank>'
                . '<bka:analyticAccount><typ:ids>221001</typ:ids></bka:analyticAccount></bka:bankAccountHeader></lst:bankAccount></lst:listBankAccount>',
        ];
    }

    private static function entry(string $source, string $number, string $text, float $amount, string $md, string $d, string $date): string
    {
        return '<act:accountingItem><act:source>' . $source . '</act:source><act:number><typ:numberRequested>' . $number . '</typ:numberRequested></act:number>'
            . '<act:text>' . $text . '</act:text><act:homeCurrency><typ:priceSum>' . $amount . '</typ:priceSum></act:homeCurrency>'
            . '<act:accounting><act:credit>' . $md . '</act:credit><act:debit>' . $d . '</act:debit></act:accounting><act:date>' . $date . '</act:date><act:dateTax>' . $date . '</act:dateTax></act:accountingItem>';
    }

    private static function partner(string $ns, string $name, string $ico, string $dic): string
    {
        return '<' . $ns . ':partnerIdentity><typ:address><typ:company>' . $name . '</typ:company><typ:city>Brno</typ:city><typ:street>Testovací 1</typ:street>'
            . '<typ:zip>60200</typ:zip><typ:ico>' . $ico . '</typ:ico><typ:dic>' . $dic . '</typ:dic></typ:address></' . $ns . ':partnerIdentity>';
    }

    private static function summary(string $ns, float $base, float $vat): string
    {
        return '<' . $ns . ':invoiceSummary><' . $ns . ':homeCurrency><typ:priceNone>0</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT rate="12">0</typ:priceLowVAT>'
            . '<typ:priceLowSum>0</typ:priceLowSum><typ:priceHigh>' . $base . '</typ:priceHigh><typ:priceHighVAT rate="21">' . $vat . '</typ:priceHighVAT>'
            . '<typ:priceHighSum>' . ($base + $vat) . '</typ:priceHighSum><typ:round><typ:priceRound>0</typ:priceRound></typ:round></' . $ns . ':homeCurrency></' . $ns . ':invoiceSummary>';
    }

    /**
     * Faktura bez úhrady, nebo zlikvidovaná bankovním dokladem `$paidBy` (úhrada nese jeho id;
     * `$paidByNumber` = opis čísla v úhradě, který se může lišit).
     */
    private static function invoice(string $type, string $number, string $vs, string $date, float $base, float $vat, string $account = '', string $paidBy = '', ?string $paidByNumber = null): string
    {
        $issued = $type === 'issuedInvoice';
        $due = date('Y-m-d', (int) strtotime($date . ' +14 days'));
        $total = $base + $vat;
        return '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>' . $type . '</inv:invoiceType><inv:number><typ:numberRequested>' . $number . '</typ:numberRequested></inv:number>'
            . '<inv:symVar>' . $vs . '</inv:symVar>' . ($issued ? '' : '<inv:originalDocument>D-' . $number . '</inv:originalDocument>')
            . '<inv:date>' . $date . '</inv:date><inv:dateTax>' . $date . '</inv:dateTax><inv:dateAccounting>' . $date . '</inv:dateAccounting><inv:dateDue>' . $due . '</inv:dateDue>'
            . ($issued ? '' : '<inv:dateKHDPH>' . $date . '</inv:dateKHDPH>')
            . '<inv:classificationVAT><typ:ids>' . ($issued ? 'UD' : 'PD') . '</typ:ids></inv:classificationVAT><inv:text>Služby</inv:text>'
            . ($issued ? self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO) : self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO))
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType>'
            . ($account !== '' ? '<inv:paymentAccount><typ:accountNo>' . $account . '</typ:accountNo><typ:bankCode>' . self::PARTNER_BANK . '</typ:bankCode></inv:paymentAccount>' : '')
            . ($paidBy !== '' ? '<inv:liquidation><typ:amountHome>0</typ:amountHome></inv:liquidation>' : '<inv:liquidation><typ:amountHome>' . $total . '</typ:amountHome></inv:liquidation>')
            . '</inv:invoiceHeader>' . self::summary('inv', $base, $vat)
            . ($paidBy !== '' ? '<inv:liquidations><typ:liquidation><typ:id>' . abs(crc32($number)) . '</typ:id><typ:sourceAgenda>bank</typ:sourceAgenda><typ:sourceDocument>'
                . '<typ:id>' . abs(crc32($paidBy)) . '</typ:id><typ:number>' . ($paidByNumber ?? $paidBy) . '</typ:number></typ:sourceDocument><typ:amount>' . $total . '</typ:amount></typ:liquidation></inv:liquidations>' : '')
            . '</lst:invoice>';
    }

    /** `$account` prázdný = platba kartou (POHODA ji vede bez účtu protistrany). */
    private static function bank(string $number, string $type, string $date, float $amount, string $vs, string $partner, string $account = '', string $symPar = ''): string
    {
        return '<lst:bank version="2.0"><bnk:bankHeader><bnk:id>' . abs(crc32($number)) . '</bnk:id><bnk:bankType>' . $type . '</bnk:bankType><bnk:account><typ:ids>BAN</typ:ids></bnk:account>'
            . '<bnk:number>' . $number . '</bnk:number><bnk:statementNumber><bnk:statementNumber>001</bnk:statementNumber></bnk:statementNumber>'
            . '<bnk:symVar>' . $vs . '</bnk:symVar>' . ($symPar !== '' ? '<bnk:symPar>' . $symPar . '</bnk:symPar>' : '')
            . '<bnk:dateStatement>2026-01-31</bnk:dateStatement><bnk:datePayment>' . $date . '</bnk:datePayment>'
            . '<bnk:text>Platba</bnk:text><bnk:partnerIdentity><typ:address><typ:company>' . $partner . '</typ:company></typ:address></bnk:partnerIdentity>'
            . ($account !== '' ? '<bnk:paymentAccount><typ:accountNo>' . $account . '</typ:accountNo><typ:bankCode>' . self::PARTNER_BANK . '</typ:bankCode></bnk:paymentAccount>' : '')
            . '</bnk:bankHeader>'
            . '<bnk:bankSummary><bnk:homeCurrency><typ:priceNone>' . $amount . '</typ:priceNone><typ:priceLowSum>0</typ:priceLowSum><typ:priceHighSum>0</typ:priceHighSum>'
            . '<typ:round><typ:priceRound>0</typ:priceRound></typ:round></bnk:homeCurrency></bnk:bankSummary></lst:bank>';
    }

    /** Odpověď POHODY v kódování Windows-1250, jak ji export zapisuje. */
    private static function file(string $path, string $list): void
    {
        $xml = '<?xml version="1.0" encoding="Windows-1250"?>' . "\n"
            . '<rsp:responsePack version="2.0" id="001" state="ok" programVersion="SYNTETICKY 1.0" ico="' . self::ICO . '" ' . self::NS . '>'
            . '<rsp:responsePackItem version="2.0" id="001-1" state="ok">' . $list . '</rsp:responsePackItem></rsp:responsePack>';
        file_put_contents($path, (string) iconv('UTF-8', 'Windows-1250', $xml));
    }
}
