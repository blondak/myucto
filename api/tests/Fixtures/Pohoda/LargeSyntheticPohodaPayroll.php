<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

/**
 * Velký syntetický datový soubor mezd (`91_mzdy.xml`) ve tvaru exportu PAMICA: stovky
 * fiktivních osob, mzdy za každý měsíc roku, mzdové složky a k tomu věty hlášení
 * s atributovými daty (`<Data><a …>`), které tvoří většinu objemu reálného exportu.
 * Pro testy výkonu čtení; žádná reálná data.
 */
final class LargeSyntheticPohodaPayroll
{
    public const ICO = '12345678';
    public const YEAR = 2026;

    /** Zapíše složku `<IČO>_<rok>` s `91_mzdy.xml` do `$root` a vrátí cestu k souboru. */
    public static function write(string $root, int $persons, int $months = 12, int $attributes = 60): string
    {
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_' . self::YEAR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/91_mzdy.xml';
        $out = fopen($file, 'wb');
        fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="' . self::ICO . '" year="' . self::YEAR . '" source="PAMICA" state="ok">');
        foreach ([[1, 'M01', 'Základní mzda měsíční'], [2, 'O01', 'Odměna'], [3, 'J03', 'Obědy']] as [$id, $cislo, $nazev]) {
            fwrite($out, "<sMZslozky><ID>{$id}</ID><Cislo>{$cislo}</Cislo><Nazev>{$nazev}</Nazev></sMZslozky>");
        }
        fwrite($out, '<sMzPoj><ID>1</ID><IDS>VZP</IDS><Kod>111</Kod></sMzPoj><sSTR><ID>1</ID><IDS>ADM</IDS><SText>Administrativa</SText></sSTR>');
        $blob = static function (int $seed) use ($attributes): string {
            $data = '<Data v="1">';
            for ($a = 0; $a < $attributes; $a++) {
                $data .= '<a id="' . (10200 + $a) . '" t="0" f="1">' . (($seed * 7 + $a) % 1000) . '</a>';
            }
            return $data . '</Data>';
        };
        for ($p = 1; $p <= $persons; $p++) {
            fwrite($out, "<ZAM><ID>{$p}</ID><OsCislo>" . (1000 + $p) . "</OsCislo><Jmeno>Osoba{$p}</Jmeno><Prijmeni>Testovací</Prijmeni>"
                . '<DatNar>1990-01-01</DatNar><RefPoj>1</RefPoj><Obec>Brno</Obec><Stat>CZ</Stat></ZAM>');
            fwrite($out, "<ZAMpomer><ID>{$p}</ID><RefZAM>{$p}</RefZAM><Poradi>1</Poradi><Cislo>1</Cislo><JeDPP>0</JeDPP>"
                . '<DatNast>2025-01-01</DatNast><TUvazek>40</TUvazek><ResStr>1</ResStr></ZAMpomer>');
        }
        $mz = 0;
        $item = 0;
        for ($m = 1; $m <= $months; $m++) {
            for ($p = 1; $p <= $persons; $p++) {
                $mz++;
                fwrite($out, "<MZ><ID>{$mz}</ID><RefZAM>{$p}</RefZAM><RefPomer>{$p}</RefPomer><Rok>" . self::YEAR . "</Rok><RelMes>{$m}</RelMes>"
                    . '<HodFond>160</HodFond><HodOdpra>160</HodOdpra><TUvazek>40</TUvazek><RefPoj>1</RefPoj>'
                    . '<KcHrubaM>40000</KcHrubaM><KcCistaM>31000</KcCistaM>' . $blob($mz) . '</MZ>');
                foreach ([[1, 38000], [2, 2600], [3, -600]] as [$slozka, $kc]) {
                    $item++;
                    fwrite($out, "<MZslozky><ID>{$item}</ID><RefAg>{$mz}</RefAg><RefSlozka>{$slozka}</RefSlozka><KcMzda>{$kc}</KcMzda></MZslozky>");
                }
                // Věta hlášení s atributovými daty: v preflightu se nečte, ale soubor jí je plný.
                fwrite($out, "<MHitems><ID>{$mz}</ID><RefAg>{$m}</RefAg><RefZAM>{$p}</RefZAM><RefPomer>{$p}</RefPomer>" . $blob($mz + 1) . '</MHitems>');
            }
        }
        fwrite($out, '</mdbExport>');
        fclose($out);
        return $file;
    }
}
