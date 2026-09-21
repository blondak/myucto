<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Premier;

/**
 * Syntetická záloha dat PREMIER (databáze Visual FoxPro) pro testy převodu. Fiktivní
 * firma, fiktivní partneři, žádná reálná data.
 *
 * Dva účetní roky - počáteční stavy roku 2026 se dopočtou z deníku roku 2025:
 *
 *   2025  vklad do pokladny 5 000 a splacení ZK 200 000 na banku (211/221 proti 411);
 *         vydaná faktura VF 250001 10 000 + 21 % uhrazená bankou (vazba VAZBY);
 *         přijatá tuzemská PF 250001 1 000 + 21 % uhrazená bankou (vazba opačným směrem);
 *         přijatá služba z EU PF 250002 5 000 (kód 45 = ř. 5 + 43, přenesení) zaúčtovaná
 *         JEN řádkem základu, bez samovyměření na 343 - uhrazená až v roce 2026;
 *         táž služba PF 250003 3 000, kde účetní samovyměření zaúčtovala MD 343 / D 343
 *         (řádek je v deníku PŘED řádkem základu);
 *         dobropis VF 250002 -1 000 + 21 % se zápornými částkami na stejných stranách, vrácený bankou;
 *         přijatá faktura v EUR PF 250004 (položky v EUR, deník v Kč s haléřovým rozdílem);
 *         pokladní výdej PV 1 500 + 21 % (kód 15 → řádek DPH 40);
 *         bankovní poplatek bez dokladu.
 *   2026  úhrada PF 250002, vydaná VF 260001 20 000 + 21 % uhrazená bankou, přijatá
 *         PF 260001 2 000 + 21 % neuhrazená.
 *
 * `oss`: navíc vydaná faktura VF 250003 s kódem 60 bez řádků přiznání, který nese daň
 * 23 % - prodej koncovému zákazníkovi na Slovensko (kandidát OSS).
 */
final class SyntheticPremierBackup
{
    public const ICO = '12345679';
    public const DIC = 'CZ12345679';
    public const NAME = 'Fiktivní účetní s.r.o.';
    public const YEAR1 = 2025;
    public const YEAR2 = 2026;
    public const CUSTOMER_ICO = '87654326';
    public const VENDOR_ICO = '11223341';
    public const EU_VENDOR_DIC = 'DE123456789';
    public const BANK_ACCOUNT = '1000000005';
    public const BANK_CODE = '0100';

    public const RC_MONTH = 4;
    public const OSS_DOCUMENT = '250003';
    public const OSS_COUNTRY = 'SK';
    public const OSS_RATE = 23.0;

    /** Kódy DPH (`KODY_DPH`): sloupce R15* jsou starý tiskopis a platit nesmí. */
    public const CODE_SALE = '36';
    public const CODE_PURCHASE = '15';
    public const CODE_EU_SERVICE = '45';
    public const CODE_OUTSIDE = '60';

    /** Zápis tabulek do `$dir` (`.DBF` + `.FPT`). */
    public static function writeDir(string $dir, bool $oss = false): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach (self::tables($oss) as $name => [$fields, $rows]) {
            DbfWriter::write($dir . DIRECTORY_SEPARATOR . $name . '.DBF', $fields, $rows);
        }
        return $dir;
    }

    /**
     * Soubory zálohy jako jméno => obsah (pro sestavení vlastního archivu v testu).
     *
     * @return array<string,string>
     */
    public static function files(string $tmp, bool $oss = false): array
    {
        $dir = $tmp . DIRECTORY_SEPARATOR . 'premier_src_' . bin2hex(random_bytes(4));
        self::writeDir($dir, $oss);
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $f)) {
                $out[$f] = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . $f);
                unlink($dir . DIRECTORY_SEPARATOR . $f);
            }
        }
        rmdir($dir);
        ksort($out);
        return $out;
    }

    /** Záloha iZIP: tabulky malými písmeny ve složce, jak je PREMIER balí. */
    public static function writeZip(string $path, string $tmp, bool $oss = false): string
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach (self::files($tmp, $oss) as $name => $content) {
            $zip->addFromString('DATA/' . strtolower($name), $content);
        }
        $zip->addFromString('DATA/zaloha.txt', 'Záloha dat PREMIER');
        $zip->close();
        return $path;
    }

    /** Záloha iCAB (jedna složka, MSZIP). */
    public static function writeCab(string $path, string $tmp, bool $oss = false): string
    {
        $files = [];
        foreach (self::files($tmp, $oss) as $name => $content) {
            $files['DATA\\' . strtolower($name)] = $content;
        }
        $files['DATA\\zaloha.txt'] = 'Záloha dat PREMIER';
        CabWriter::write($path, $files);
        return $path;
    }

    /** @return array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}> */
    public static function tables(bool $oss = false): array
    {
        return [
            'SET_GLOB' => [
                [['PROMEN', 'C', 20], ['C_SET', 'C', 100], ['N_SET', 'N', 15, 2], ['D_SET', 'D'], ['L_SET', 'L']],
                [
                    ['PROMEN' => 'aico', 'C_SET' => self::ICO],
                    ['PROMEN' => 'adic', 'C_SET' => 'CZ 12345679'],
                    ['PROMEN' => 'adress(1)', 'C_SET' => self::NAME],
                    ['PROMEN' => 'a_mena', 'C_SET' => 'CZK'],
                    ['PROMEN' => 'a_zacatek', 'D_SET' => '2025-01-01'],
                    ['PROMEN' => 'a_koef', 'N_SET' => 100],
                ],
            ],
            'OSNOVA' => [
                [['UCET', 'C', 3], ['ANALYT', 'C', 3], ['TEXT', 'C', 40], ['ROK', 'N', 4], ['NEDANOVY', 'L']],
                self::chart(),
            ],
            'KODY_DPH' => [
                [['KOD_DPH', 'C', 3], ['TEXT', 'C', 40], ['SAZBA', 'N', 1], ['JINA_SAZBA', 'N', 5, 2], ['FA_IN', 'L'], ['FA_OUT', 'L'],
                    ['IS_REVERS', 'L'], ['IS_KRACENY', 'L'], ['R15', 'N', 3], ['R15B', 'N', 3], ['R17', 'N', 3], ['R17B', 'N', 3], ['R17C', 'N', 3],
                    ['TAB_FA', 'C', 5], ['TAB_FANE', 'C', 5]],
                [
                    ['KOD_DPH' => self::CODE_SALE, 'TEXT' => 'Tuzemské plnění 21 %', 'SAZBA' => 2, 'FA_OUT' => true, 'R15' => 1, 'R17' => 1, 'TAB_FA' => 'A.4.', 'TAB_FANE' => 'A.5.'],
                    ['KOD_DPH' => self::CODE_PURCHASE, 'TEXT' => 'Tuzemský odpočet 21 %', 'SAZBA' => 2, 'FA_IN' => true, 'R15' => 40, 'R17' => 40, 'TAB_FA' => 'B.2.', 'TAB_FANE' => 'B.3.'],
                    ['KOD_DPH' => self::CODE_EU_SERVICE, 'TEXT' => 'Přijetí služby z EU 21 %', 'SAZBA' => 2, 'FA_IN' => true, 'IS_REVERS' => true,
                        'R15' => 99, 'R15B' => 98, 'R17' => 5, 'R17B' => 43, 'TAB_FA' => 'A.2.'],
                    ['KOD_DPH' => self::CODE_OUTSIDE, 'TEXT' => 'Nezahrnovat do přiznání', 'SAZBA' => 0, 'FA_OUT' => true],
                ],
            ],
            'PUB_UCTO' => [
                [['INTER', 'N', 10], ['DATUM', 'D'], ['DATUM_DPH', 'D'], ['DOKLAD', 'C', 5], ['CISLO', 'C', 10], ['POPIS', 'C', 50], ['POZNAMKA', 'M'],
                    ['CASTKA', 'N', 15, 2], ['MD', 'C', 6], ['DAL', 'C', 6], ['KOD_DPH', 'C', 3], ['SAZBA_DPH', 'N', 5, 2], ['CASTKA_DPH', 'N', 15, 2],
                    ['IKOD', 'C', 1], ['SB_KOD', 'C', 5], ['SBORNIK', 'N', 10], ['MENA', 'C', 3], ['ZCASTKA', 'N', 15, 2], ['KURS', 'N', 10, 4], ['M_KURS', 'N', 5],
                    ['VARIABL', 'C', 12], ['CISLO_ODB', 'C', 10], ['NAZEV_ODB', 'C', 50], ['ICO_ODB', 'C', 12], ['DIC_ODB', 'C', 14], ['STAT_ODB', 'C', 30],
                    ['ID_PAR', 'C', 10], ['STKOD', 'N', 5]],
                self::journal($oss),
            ],
            'PARTNERY' => [
                [['ID', 'C', 10], ['CISLO', 'C', 10], ['NAZEV', 'C', 50], ['ICO', 'C', 12], ['DIC', 'C', 14], ['ULICE', 'C', 40], ['MESTO', 'C', 30],
                    ['PSC', 'C', 6], ['STAT', 'C', 30], ['KOD_ZEME', 'C', 2], ['E_MAIL', 'C', 50], ['TEL', 'C', 20], ['MOBIL', 'C', 20]],
                [
                    ['ID' => 'P0', 'CISLO' => '0', 'NAZEV' => self::NAME, 'ICO' => self::ICO, 'DIC' => self::DIC, 'ULICE' => 'Účetní 1', 'MESTO' => 'Brno', 'PSC' => '60200', 'KOD_ZEME' => 'CZ'],
                    ['ID' => 'P1', 'CISLO' => '1', 'NAZEV' => 'Odběratel Fiktivní s.r.o.', 'ICO' => self::CUSTOMER_ICO, 'DIC' => 'CZ' . self::CUSTOMER_ICO,
                        'ULICE' => 'Zkušební 10', 'MESTO' => 'Praha', 'PSC' => '11000', 'KOD_ZEME' => 'CZ', 'E_MAIL' => 'odberatel@example.invalid'],
                    ['ID' => 'P2', 'CISLO' => '2', 'NAZEV' => 'Dodavatel Fiktivní s.r.o.', 'ICO' => self::VENDOR_ICO, 'DIC' => 'CZ' . self::VENDOR_ICO,
                        'ULICE' => 'Vzorová 5', 'MESTO' => 'Ostrava', 'PSC' => '70200', 'STAT' => 'Česká republika'],
                    ['ID' => 'P3', 'CISLO' => '3', 'NAZEV' => 'Fiktiv Software GmbH', 'DIC' => self::EU_VENDOR_DIC,
                        'ULICE' => 'Musterstraße 1', 'MESTO' => 'Berlin', 'PSC' => '10115', 'STAT' => 'Německo', 'KOD_ZEME' => 'DE'],
                ],
            ],
            'FA_OUT' => [self::headerFields('VS'), self::issued($oss)],
            'POLOZKY' => [self::itemFields(), self::issuedItems($oss)],
            'FA_IN' => [self::headerFields('VARIABL'), self::purchases()],
            'POLOZ_IN' => [self::itemFields(), self::purchaseItems()],
            'DOKLAD' => [
                [['DOKLAD', 'C', 5], ['TEXT', 'C', 40], ['TOK', 'N', 3]],
                [['DOKLAD' => 'VF', 'TEXT' => 'Vydané faktury', 'TOK' => 3], ['DOKLAD' => 'PF', 'TEXT' => 'Přijaté faktury', 'TOK' => 4],
                    ['DOKLAD' => 'ZVF', 'TEXT' => 'Vydané zálohové listy', 'TOK' => 11]],
            ],
            'DOKL_PU' => [
                [['DOKLAD', 'C', 5], ['TOK', 'N', 3], ['MD', 'C', 3], ['MDA', 'C', 3], ['DAL', 'C', 3], ['DALA', 'C', 3], ['CISLO_U', 'C', 20],
                    ['KOD_U', 'C', 4], ['IBAN', 'C', 34], ['MENA', 'C', 3], ['TEXT', 'C', 40], ['NAZEV_B', 'C', 40]],
                [
                    ['DOKLAD' => 'BV', 'TOK' => 2, 'MD' => '221', 'MDA' => '001', 'CISLO_U' => self::BANK_ACCOUNT, 'KOD_U' => self::BANK_CODE,
                        'MENA' => 'CZK', 'TEXT' => 'Bankovní výpisy', 'NAZEV_B' => 'Fiktivní banka'],
                    ['DOKLAD' => 'PP', 'TOK' => 1, 'MD' => '211', 'MDA' => '001', 'TEXT' => 'Pokladna příjem'],
                    ['DOKLAD' => 'PV', 'TOK' => 1, 'DAL' => '211', 'DALA' => '001', 'TEXT' => 'Pokladna výdej'],
                ],
            ],
            'VAZBY' => [
                [['KOD_ZDR', 'C', 3], ['INT_ZDR', 'N', 10], ['KOD_TER', 'C', 3], ['INT_TER', 'N', 10]],
                [
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 14, 'KOD_TER' => 'VF', 'INT_TER' => 1],
                    ['KOD_ZDR' => 'PF', 'INT_ZDR' => 101, 'KOD_TER' => 'UD', 'INT_TER' => 17],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 21, 'KOD_TER' => 'PF', 'INT_TER' => 103],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 24, 'KOD_TER' => 'VF', 'INT_TER' => 2],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 27, 'KOD_TER' => 'PF', 'INT_TER' => 104],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 40, 'KOD_TER' => 'PF', 'INT_TER' => 102],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 43, 'KOD_TER' => 'VF', 'INT_TER' => 4],
                ],
            ],
            'D_KHDPH1' => [
                [['ID_CISLO', 'C', 10], ['ROK', 'N', 4], ['MESIC', 'N', 2], ['DNE', 'C', 10], ['FORMA', 'C', 1]],
                [
                    // Řádné KH za únor, pak následné - platí poslední podání.
                    ['ID_CISLO' => 'KH1', 'ROK' => 2025, 'MESIC' => 2, 'DNE' => '20.03.2025', 'FORMA' => 'B'],
                    ['ID_CISLO' => 'KH2', 'ROK' => 2025, 'MESIC' => 2, 'DNE' => '02.04.2025', 'FORMA' => 'N'],
                ],
            ],
            'D_KHDPHP1' => [
                [['ID_CISLO', 'C', 10], ['ODDIL', 'C', 3], ['ZAKL_DANE1', 'N', 15, 2], ['DAN1', 'N', 15, 2], ['ZAKL_DANE2', 'N', 15, 2],
                    ['DAN2', 'N', 15, 2], ['ZAKL_DANE3', 'N', 15, 2], ['DAN3', 'N', 15, 2]],
                [
                    ['ID_CISLO' => 'KH1', 'ODDIL' => 'A4', 'ZAKL_DANE1' => 9000, 'DAN1' => 1890],
                    ['ID_CISLO' => 'KH2', 'ODDIL' => 'A4', 'ZAKL_DANE1' => 10000, 'DAN1' => 2100],
                ],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function chart(): array
    {
        $out = [];
        $accounts = [
            ['211', '001', 'Pokladna'], ['221', '001', 'Běžný účet'], ['311', '000', 'Odběratelé'], ['321', '000', 'Dodavatelé'],
            ['343', '021', 'DPH 21 %'], ['343', '090', 'DPH OSS'], ['343', '100', 'DPH samovyměření - odpočet'], ['343', '200', 'DPH samovyměření - daň'],
            ['411', '000', 'Základní kapitál'], ['431', '000', 'Výsledek hospodaření ve schvalovacím řízení'],
            ['501', '100', 'Spotřeba materiálu'], ['518', '100', 'Ostatní služby'], ['568', '000', 'Bankovní poplatky'],
            ['513', '100', 'Reprezentace'], ['602', '100', 'Tržby za služby'], ['604', '100', 'Tržby za zboží'],
        ];
        foreach ([self::YEAR1, self::YEAR2] as $year) {
            foreach ($accounts as [$synthetic, $analytic, $name]) {
                $out[] = ['UCET' => $synthetic, 'ANALYT' => $analytic, 'TEXT' => $name, 'ROK' => $year, 'NEDANOVY' => $synthetic === '513'];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function journal(bool $oss): array
    {
        $customer = ['CISLO_ODB' => '1', 'NAZEV_ODB' => 'Odběratel Fiktivní s.r.o.', 'ICO_ODB' => self::CUSTOMER_ICO, 'DIC_ODB' => 'CZ' . self::CUSTOMER_ICO, 'ID_PAR' => 'P1'];
        $vendor = ['CISLO_ODB' => '2', 'NAZEV_ODB' => 'Dodavatel Fiktivní s.r.o.', 'ICO_ODB' => self::VENDOR_ICO, 'DIC_ODB' => 'CZ' . self::VENDOR_ICO, 'ID_PAR' => 'P2'];
        $euVendor = ['CISLO_ODB' => '3', 'NAZEV_ODB' => 'Fiktiv Software GmbH', 'DIC_ODB' => self::EU_VENDOR_DIC, 'STAT_ODB' => 'Německo', 'ID_PAR' => 'P3'];
        $rows = [
            self::row(10, '2025-01-02', 'PP', '1', 'Vklad do pokladny', 5000, '211001', '411000'),
            self::row(11, '2025-01-02', 'BV', '1', 'Splacení základního kapitálu', 200000, '221001', '411000', ['POZNAMKA' => 'Vklad společníka - převod z účtu č. 1000000005/0100']),
            self::row(12, '2025-02-10', 'VF', '250001', 'Programátorské služby', 10000, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 1) + $customer),
            self::row(13, '2025-02-10', 'VF', '250001', 'DPH', 2100, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 1) + $customer),
            self::row(14, '2025-02-20', 'BV', '2', 'Úhrada VF 250001', 12100, '221001', '311000', ['VARIABL' => '250001'] + $customer),
            self::row(15, '2025-03-05', 'PF', '250001', 'Kancelářské potřeby', 1000, '518100', '321000', self::vat(self::CODE_PURCHASE, 'P', 'PF', 101) + $vendor),
            self::row(16, '2025-03-05', 'PF', '250001', 'DPH', 210, '343021', '321000', self::vat(self::CODE_PURCHASE, 'D', 'PF', 101) + $vendor),
            self::row(17, '2025-03-15', 'BV', '3', 'Úhrada PF 250001', 1210, '321000', '221001', ['VARIABL' => '7001'] + $vendor),
            // Služba z EU: jen základ, samovyměření na 343 účetní nezaúčtovala.
            self::row(18, '2025-04-10', 'PF', '250002', 'Licence software', 5000, '518100', '321000', self::vat(self::CODE_EU_SERVICE, 'P', 'PF', 102) + $euVendor),
            // Táž služba se samovyměřením MD 343 / D 343 - řádek daně je v deníku před základem.
            self::row(19, '2025-05-12', 'PF', '250003', 'Samovyměření DPH', 630, '343100', '343200', self::vat(self::CODE_EU_SERVICE, 'D', 'PF', 103) + $euVendor),
            self::row(20, '2025-05-12', 'PF', '250003', 'Hosting', 3000, '518100', '321000', self::vat(self::CODE_EU_SERVICE, 'P', 'PF', 103) + $euVendor),
            self::row(21, '2025-05-20', 'BV', '4', 'Úhrada PF 250003', 3000, '321000', '221001', $euVendor),
            // Dobropis zápornými částkami na stejné strany.
            self::row(22, '2025-06-01', 'VF', '250002', 'Sleva za služby', -1000, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 2) + $customer),
            self::row(23, '2025-06-01', 'VF', '250002', 'DPH', -210, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 2) + $customer),
            self::row(24, '2025-06-10', 'BV', '5', 'Vrácení dobropisu VF 250002', 1210, '311000', '221001', ['VARIABL' => '250002'] + $customer),
            // Faktura v EUR: deník v Kč, cizí měna na řádcích závazku.
            self::row(25, '2025-07-01', 'PF', '250004', 'Konzultace', 10051.72, '518100', '321000',
                self::vat(self::CODE_PURCHASE, 'P', 'PF', 104) + ['MENA' => 'EUR', 'ZCASTKA' => 400.10, 'KURS' => 25.123, 'M_KURS' => 1] + $vendor),
            self::row(26, '2025-07-01', 'PF', '250004', 'DPH', 2110.86, '343021', '321000',
                self::vat(self::CODE_PURCHASE, 'D', 'PF', 104) + ['MENA' => 'EUR', 'ZCASTKA' => 84.02, 'KURS' => 25.123, 'M_KURS' => 1] + $vendor),
            self::row(27, '2025-07-20', 'BV', '6', 'Úhrada PF 250004', 12162.58, '321000', '221001', $vendor),
            // Pokladní výdej s DPH.
            self::row(28, '2025-08-05', 'PV', '1', 'Nákup materiálu', 500, '501100', '211001', ['KOD_DPH' => self::CODE_PURCHASE, 'SAZBA_DPH' => 21, 'CASTKA_DPH' => 105, 'IKOD' => 'P']),
            self::row(29, '2025-08-05', 'PV', '1', 'DPH', 105, '343021', '211001', ['KOD_DPH' => self::CODE_PURCHASE, 'SAZBA_DPH' => 21, 'IKOD' => 'D']),
            self::row(30, '2025-12-31', 'BV', '7', 'Poplatek za vedení účtu', 50, '568000', '221001'),

            self::row(40, '2026-01-15', 'BV', '1', 'Úhrada PF 250002', 5000, '321000', '221001', $euVendor),
            self::row(41, '2026-02-10', 'VF', '260001', 'Programátorské služby', 20000, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 4) + $customer),
            self::row(42, '2026-02-10', 'VF', '260001', 'DPH', 4200, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 4) + $customer),
            self::row(43, '2026-02-25', 'BV', '2', 'Úhrada VF 260001', 24200, '221001', '311000', ['VARIABL' => '260001'] + $customer),
            self::row(44, '2026-03-03', 'PF', '260001', 'Kancelářské potřeby', 2000, '518100', '321000', self::vat(self::CODE_PURCHASE, 'P', 'PF', 105) + $vendor),
            self::row(45, '2026-03-03', 'PF', '260001', 'DPH', 420, '343021', '321000', self::vat(self::CODE_PURCHASE, 'D', 'PF', 105) + $vendor),
        ];
        if ($oss) {
            $sk = ['NAZEV_ODB' => 'Zákazník Bratislava', 'STAT_ODB' => 'Slovensko'];
            $rows[] = self::row(31, '2025-09-01', 'VF', self::OSS_DOCUMENT, 'Zboží na dálku SK', 1000, '311000', '604100', ['SAZBA_DPH' => 23] + self::vat(self::CODE_OUTSIDE, 'P', 'VF', 3) + $sk);
            $rows[] = self::row(32, '2025-09-01', 'VF', self::OSS_DOCUMENT, 'DPH OSS', 230, '311000', '343090', ['SAZBA_DPH' => 23] + self::vat(self::CODE_OUTSIDE, 'D', 'VF', 3) + $sk);
        }
        // Záznam smazaný v PREMIER (hvězdička) - převod ho nesmí vidět.
        $rows[] = self::row(99, '2025-03-01', 'ID', '9', 'Smazaný zápis', 99999, '518100', '221001') + ['_deleted' => true];
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function vat(string $code, string $kind, string $sbKod, int $sbornik): array
    {
        return ['KOD_DPH' => $code, 'SAZBA_DPH' => $code === self::CODE_OUTSIDE ? 0 : 21, 'IKOD' => $kind, 'SB_KOD' => $sbKod, 'SBORNIK' => $sbornik];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function row(int $inter, string $date, string $series, string $number, string $text, float $amount, string $md, string $dal, array $extra = []): array
    {
        return $extra + [
            'INTER' => $inter, 'DATUM' => $date, 'DATUM_DPH' => $date, 'DOKLAD' => $series, 'CISLO' => $number, 'POPIS' => $text,
            'CASTKA' => $amount, 'MD' => $md, 'DAL' => $dal, 'M_KURS' => 1,
        ];
    }

    /** @return list<array{0:string,1:string,2?:int,3?:int}> */
    private static function headerFields(string $symbol): array
    {
        return [['INTER', 'N', 10], ['DOKLAD', 'C', 5], ['CISLO', 'C', 10], ['DATUM_VYS', 'D'], ['DATUM_USK', 'D'], ['DATUM_SPL', 'D'], ['DATUM_DPH', 'D'],
            ['DATUM_KVY', 'D'], ['MENA', 'C', 3], ['KURS', 'N', 10, 4], ['M_KURS', 'N', 5], ['POPIS', 'C', 50], [$symbol, 'C', 12], ['CISLO_PF', 'C', 20],
            ['K_SYMBOL', 'C', 4], ['UCET_ODB', 'C', 30], ['FORMA', 'C', 15], ['STORNO_FA', 'L'], ['CISLO_ODB', 'C', 10], ['NAZEV_ODB', 'C', 50],
            ['ICO_ODB', 'C', 12], ['DIC_ODB', 'C', 14], ['ULICE_ODB', 'C', 40], ['MESTO_ODB', 'C', 30], ['PSC_ODB', 'C', 6], ['STAT_ODB', 'C', 30], ['ID_PAR', 'C', 10]];
    }

    /** @return list<array{0:string,1:string,2?:int,3?:int}> */
    private static function itemFields(): array
    {
        return [['FAKTURA', 'N', 10], ['POL_SORT', 'N', 5], ['PORDER', 'N', 5], ['TEXT', 'C', 50], ['TEXT_2', 'C', 50], ['MNOZSTVI', 'N', 12, 3],
            ['MJ', 'C', 5], ['CENA', 'N', 15, 2], ['CENA_DPH', 'N', 15, 2], ['SAZBA_DPH', 'N', 5, 2], ['KOD_DPH', 'C', 3]];
    }

    /** @return list<array<string,mixed>> */
    private static function issued(bool $oss): array
    {
        $customer = ['CISLO_ODB' => '1', 'NAZEV_ODB' => 'Odběratel Fiktivní s.r.o.', 'ICO_ODB' => self::CUSTOMER_ICO, 'DIC_ODB' => 'CZ' . self::CUSTOMER_ICO,
            'ULICE_ODB' => 'Zkušební 10', 'MESTO_ODB' => 'Praha', 'PSC_ODB' => '110 00', 'STAT_ODB' => 'Česká republika', 'ID_PAR' => 'P1'];
        $rows = [
            self::header(1, 'VF', '250001', '2025-02-10', 'Programátorské služby', ['VS' => '250001', 'FORMA' => 'převodem'] + $customer),
            self::header(2, 'VF', '250002', '2025-06-01', 'Dobropis - sleva za služby', ['VS' => '250002', 'FORMA' => 'převodem'] + $customer),
            self::header(4, 'VF', '260001', '2026-02-10', 'Programátorské služby', ['VS' => '260001', 'FORMA' => 'převodem'] + $customer),
            // Rozpracovaný prázdný doklad bez položek i zápisu se nepřevádí.
            self::header(5, 'VF', '260099', '2026-05-01', 'Rozpracováno', $customer),
        ];
        if ($oss) {
            $rows[] = self::header(3, 'VF', self::OSS_DOCUMENT, '2025-09-01', 'Zboží na dálku', ['VS' => self::OSS_DOCUMENT, 'NAZEV_ODB' => 'Zákazník Bratislava',
                'ULICE_ODB' => 'Hlavná 1', 'MESTO_ODB' => 'Bratislava', 'PSC_ODB' => '81101', 'STAT_ODB' => 'Slovensko']);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function issuedItems(bool $oss): array
    {
        $rows = [
            self::item(1, 1, 'Vývoj aplikace', 8, 'hod', 8000, 1680, 21, self::CODE_SALE),
            self::item(1, 2, 'Konzultace', 2, 'hod', 2000, 420, 21, self::CODE_SALE),
            self::item(2, 1, 'Sleva za služby', 1, 'ks', -1000, -210, 21, self::CODE_SALE),
            self::item(4, 1, 'Vývoj aplikace', 20, 'hod', 20000, 4200, 21, self::CODE_SALE),
        ];
        if ($oss) {
            $rows[] = self::item(3, 1, 'Zboží', 1, 'ks', 1000, 230, 23, self::CODE_OUTSIDE);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function purchases(): array
    {
        $vendor = ['CISLO_ODB' => '2', 'NAZEV_ODB' => 'Dodavatel Fiktivní s.r.o.', 'ICO_ODB' => self::VENDOR_ICO, 'DIC_ODB' => 'CZ' . self::VENDOR_ICO,
            'ULICE_ODB' => 'Vzorová 5', 'MESTO_ODB' => 'Ostrava', 'PSC_ODB' => '70200', 'ID_PAR' => 'P2', 'UCET_ODB' => '1000000005/0100', 'FORMA' => 'příkazem'];
        $euVendor = ['CISLO_ODB' => '3', 'NAZEV_ODB' => 'Fiktiv Software GmbH', 'DIC_ODB' => self::EU_VENDOR_DIC, 'ULICE_ODB' => 'Musterstraße 1',
            'MESTO_ODB' => 'Berlin', 'PSC_ODB' => '10115', 'STAT_ODB' => 'Německo', 'ID_PAR' => 'P3'];
        return [
            self::header(101, 'PF', '250001', '2025-03-05', 'Kancelářské potřeby', ['VARIABL' => '7001', 'CISLO_PF' => 'D-2025-7001'] + $vendor),
            self::header(102, 'PF', '250002', '2025-04-10', 'Licence software', ['VARIABL' => '', 'CISLO_PF' => 'INV-2025-0410'] + $euVendor),
            self::header(103, 'PF', '250003', '2025-05-12', 'Hosting', ['CISLO_PF' => 'INV-2025-0512'] + $euVendor),
            self::header(104, 'PF', '250004', '2025-07-01', 'Konzultace v EUR', ['VARIABL' => '7004', 'CISLO_PF' => 'D-2025-7004', 'MENA' => 'EUR', 'KURS' => 25.123, 'M_KURS' => 1] + $vendor),
            self::header(105, 'PF', '260001', '2026-03-03', 'Kancelářské potřeby', ['VARIABL' => '8001', 'CISLO_PF' => 'D-2026-8001'] + $vendor),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function purchaseItems(): array
    {
        return [
            self::item(101, 1, 'Papír A4', 10, 'bal', 1000, 210, 21, self::CODE_PURCHASE),
            self::item(102, 1, 'Licence software', 1, 'ks', 5000, 0, 21, self::CODE_EU_SERVICE),
            self::item(103, 1, 'Hosting', 1, 'ks', 3000, 0, 21, self::CODE_EU_SERVICE),
            // EUR: 100,00 + 300,10 EUR × 25,123 = 2 512,30 + 7 539,41; deník 10 051,72 (rozdíl 0,01 na větší položku).
            self::item(104, 1, 'Konzultace - přípravná', 1, 'ks', 100.00, 21.00, 21, self::CODE_PURCHASE),
            self::item(104, 2, 'Konzultace - hlavní', 1, 'ks', 300.10, 63.02, 21, self::CODE_PURCHASE),
            self::item(105, 1, 'Toner', 2, 'ks', 2000, 420, 21, self::CODE_PURCHASE),
        ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function header(int $inter, string $series, string $number, string $date, string $text, array $extra): array
    {
        return $extra + [
            'INTER' => $inter, 'DOKLAD' => $series, 'CISLO' => $number, 'DATUM_VYS' => $date, 'DATUM_USK' => $date,
            'DATUM_SPL' => date('Y-m-d', strtotime($date . ' +14 days')), 'DATUM_DPH' => $date, 'MENA' => 'CZK', 'KURS' => 1, 'M_KURS' => 1, 'POPIS' => $text,
        ];
    }

    /** @return array<string,mixed> */
    private static function item(int $invoice, int $order, string $text, float $qty, string $unit, float $price, float $vat, float $rate, string $code): array
    {
        return ['FAKTURA' => $invoice, 'POL_SORT' => $order, 'PORDER' => $order, 'TEXT' => $text, 'MNOZSTVI' => $qty, 'MJ' => $unit,
            'CENA' => $price, 'CENA_DPH' => $vat, 'SAZBA_DPH' => $rate, 'KOD_DPH' => $code];
    }
}
