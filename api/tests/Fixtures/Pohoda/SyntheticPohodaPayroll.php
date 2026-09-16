<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

/**
 * Syntetické mzdy z datového souboru POHODA Mzdy / PAMICA (`91_mzdy.xml`, výstup
 * `tools/pohoda-export/Export-PohodaMdb.ps1`). Fiktivní osoby, žádná reálná data.
 *
 * Leden a únor 2026: Jana (pracovní poměr 40 h, měsíční mzda 40 000 Kč, odměna,
 * přesčas, obědy, v lednu srážka 300 Kč, v únoru 8 h dovolené) a Petr (DPP, časová
 * mzda za hodiny).
 *
 * Údaje osob a vztahů pro JMHZ: Jana má adresu, e-mail, podepsané prohlášení, pracoviště
 * s kódem obce, CZ-ISCO, platné OIČ a ID PPV, oznámení pojišťovně o nástupu a odeslanou
 * registraci ČSSZ. Petr má telefon, nepodepsané prohlášení, OIČ s chybnou kontrolní
 * číslicí, vztah skončený 28. 2. 2026 s odeslanou odhláškou ČSSZ a oznámením pojišťovně
 * o skončení; jeho registrace ČSSZ odeslaná není a oznámení o nástupu je v jiném stavu.
 */
final class SyntheticPohodaPayroll
{
    public const ICO = '12345678';
    public const YEAR = 2026;
    /** Hrubá mzda Jany a Petra za měsíc (kontrolní součet). */
    public const GROSS = [43000.0, 5000.0];
    /** OIČ Jany (platná kontrolní číslice) a ID PPV obou vztahů. */
    public const JANA_OIC = '1234567895';
    public const JANA_ID_PPV = '1234567890123';
    public const PETR_ID_PPV = '9876543210';
    public const PETR_END = '2026-02-28';

    /** Zapíše složku mezd `<IČO>_<rok>` s `91_mzdy.xml` do `$root` a vrátí cestu k souboru. */
    public static function write(string $root): string
    {
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_' . self::YEAR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        foreach ([[1, 'M01', 'Základní mzda měsíční'], [2, 'O01', 'Odměna'], [3, 'J03', 'Obědy'], [4, 'P01', 'Příplatek za přesčas'], [5, 'C01', 'Časová mzda']] as [$id, $cislo, $nazev]) {
            $row('sMZslozky', ['ID' => $id, 'Cislo' => $cislo, 'Nazev' => $nazev]);
        }
        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'V01', 'Nazev' => 'Dovolená']);
        $row('sMZneprit', ['ID' => 2, 'Cislo' => 'H08', 'Nazev' => 'Rodičovská dovolená']);
        $row('sMZsrazky', ['ID' => 1, 'Cislo' => 'S07', 'Nazev' => 'Srážka zadaná částkou']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111', 'Ucet' => '2050001', 'KodBanky' => '0710', 'VarSym' => '12345678', 'DataBox' => 'i48ae3q']);
        $row('sSTR', ['ID' => 1, 'IDS' => 'ADM', 'SText' => 'Administrativa']);
        $row('PracMista', ['ID' => 1, 'IDS' => 'UCT', 'SText' => 'Účetní']);
        $row('sMzMist', ['ID' => 1, 'Cislo' => '582786', 'Misto' => 'Brno sídlo', 'Obec' => 'Brno', 'Stat' => 'CZ']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '1001', 'Jmeno' => 'Jana', 'Prijmeni' => 'Testovací', 'Rozena' => 'Pokusná', 'Titul' => 'Ing.',
            'DatNar' => '1990-05-04', 'MistoNar' => 'Brno', 'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1, 'RefMist' => 1,
            'Ulice' => 'Zkušební', 'CP' => '12', 'Obec' => 'Brno', 'PSC' => '60200', 'Stat' => 'CZ', 'Email' => 'jana@example.invalid', 'OIC' => self::JANA_OIC]);
        // Petr má v datech rodné číslo bez platného data narození a pojišťovnu 999 (cizinec
        // bez českého pojištění) - převod je vynechá, osobu ale založí.
        $row('sMzPoj', ['ID' => 2, 'IDS' => 'CIZI', 'Kod' => '999']);
        $row('ZAM', ['ID' => 2, 'OsCislo' => '1002', 'Jmeno' => 'Petr', 'Prijmeni' => 'Zkušební', 'DatNar' => '1985-11-20', 'RodCisl' => '8513990000', 'RefPoj' => 2,
            'StatPris' => 'SK', 'Nerezident' => 0, 'Ulice' => 'Pokusná', 'CP' => '3', 'Obec' => 'Ostrava', 'PSC' => '70200', 'Stat' => 'CZ',
            'Tel' => '+420 600 000 000', 'OIC' => '1234567890']);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'Cislo' => '1', 'JeDPP' => 0, 'DatNast' => '2025-03-01', 'TUvazek' => 40, 'ResStr' => 1, 'RelPracMist' => 1,
            'IDPPV' => self::JANA_ID_PPV, 'ResCisCZISCO' => '43111']);
        $row('ZAMpomer', ['ID' => 2, 'RefZAM' => 2, 'Poradi' => 1, 'Cislo' => '1', 'JeDPP' => 1, 'DatNast' => '2026-01-01', 'DatOdch' => self::PETR_END, 'RelUkonc' => 1,
            'IDPPV' => self::PETR_ID_PPV]);
        foreach ([1, 2] as $m) {
            $jana = 10 + $m;
            $petr = 20 + $m;
            $row('MZ', ['ID' => $jana, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => self::YEAR, 'RelMes' => $m, 'HodFond' => 160, 'HodOdpra' => $m === 2 ? 152 : 160,
                'TUvazek' => 40, 'RefPoj' => 1, 'KcHrubaM' => self::GROSS[0], 'KcCistaM' => 33000, 'Prohlas' => 1, 'JeSocPP' => 1, 'KcSoc' => 3053, 'KcZdr' => 1935,
                // Sjednaná měsíční mzda a čtvrtletní průměr, se kterým PAMICA počítala náhrady.
                'KcZaklM' => $m === 2 ? 42000 : 40000, 'DnyPrac' => 20, 'DnyOdpra' => 20, 'KcPrum' => 250,
                'KcSocZak' => 43000, 'KcZdaM' => 43000, 'KcDanPrS' => 6450, 'KcNzdZak' => 2570, 'KcDanZal' => 3880, 'KcZalDan' => 3880]);
            // Petr (DPP pod limitem) bez účasti na pojištění; v únoru žádá o slevu pracujícího důchodce.
            $row('MZ', ['ID' => $petr, 'RefZAM' => 2, 'RefPomer' => 2, 'Rok' => self::YEAR, 'RelMes' => $m, 'HodFond' => 0, 'HodOdpra' => 25,
                'RefPoj' => 2, 'KcHrubaM' => self::GROSS[1], 'KcCistaM' => 5000, 'Prohlas' => 0, 'JeSocPP' => 0, 'KcSraDanZak' => 5000, 'KcSraDan' => 750,
                'SocPojSlevaZadost' => $m === 2 ? 1 : 0]);
            // Od února má Jana vyšší měsíční mzdu (změnu zpracovala PAMICA).
            $row('MZslozky', ['ID' => 100 + $m, 'RefAg' => $jana, 'RefSlozka' => 1, 'KcMzda' => 40000, 'Hodnota1' => $m === 2 ? 42000 : 40000]);
            $row('MZslozky', ['ID' => 110 + $m, 'RefAg' => $jana, 'RefSlozka' => 2, 'KcMzda' => 2000]);
            $row('MZslozky', ['ID' => 120 + $m, 'RefAg' => $jana, 'RefSlozka' => 4, 'KcMzda' => 1000, 'PocHodin' => 4]);
            $row('MZslozky', ['ID' => 130 + $m, 'RefAg' => $jana, 'RefSlozka' => 3, 'KcMzda' => -600]);
            $row('MZslozky', ['ID' => 140 + $m, 'RefAg' => $petr, 'RefSlozka' => 5, 'KcMzda' => 5000, 'PocHodin' => 25]);
        }
        $row('MZneprit', ['ID' => 1, 'RefAg' => 12, 'RefSlozka' => 1, 'HodPrac' => 8, 'KcNahr' => 2000, 'DatZac' => '2026-02-10', 'DatKon' => '2026-02-10']);
        // Rodičovská dovolená (H08) v hodinách sešitu není, do evidence nepřítomností ale patří.
        $row('MZneprit', ['ID' => 2, 'RefAg' => 22, 'RefSlozka' => 2, 'DatZac' => '2026-02-01', 'DatKon' => '2026-02-28']);
        $row('ZAMucet', ['ID' => 1, 'RefAg' => 1, 'Ucet' => '1000002', 'KodBanky' => '0800', 'Active' => 1]);
        $row('MZsrazky', ['ID' => 1, 'RefAg' => 11, 'RefSlozka' => 1, 'KcSrazeno' => 300]);

        // Janino dítě s daňovým zvýhodněním na 1. dítě (kód 34) a její sleva na poplatníka (36).
        $row('ZAMpDet', ['ID' => 1, 'RefAg' => 1, 'DatOd' => '2025-03-01', 'RelOdpoc' => 34, 'KcOdec' => 15204, 'Poradi' => 1,
            'Jmeno' => 'Tereza', 'Prijmeni' => 'Testovací', 'RodCisl' => '1501010005']);
        $row('ZAMpDet', ['ID' => 2, 'RefAg' => 1, 'DatOd' => '2025-03-01', 'RelOdpoc' => 36, 'KcOdec' => 30840, 'Poradi' => 2]);

        // Oznámení pojišťovně (RelKod 1 nástup, 2 skončení; stav 2 = zpracované).
        // Janino oznámení o nástupu PAMICA nevede jako zpracované; vztah ale vznikl před převodem.
        $row('ZAMzp', ['ID' => 1, 'RefAg' => 1, 'RefPomer' => 1, 'RelKod' => 1, 'RefPoj' => 1, 'RefStav' => 1, 'DatStav' => '2025-03-03', 'Datum' => '2025-03-01']);
        $row('ZAMzp', ['ID' => 2, 'RefAg' => 2, 'RefPomer' => 2, 'RelKod' => 1, 'RefPoj' => 2, 'RefStav' => 1, 'DatStav' => '2026-01-02', 'Datum' => '2026-01-01']);
        $row('ZAMzp', ['ID' => 3, 'RefAg' => 2, 'RefPomer' => 2, 'RelKod' => 2, 'RefPoj' => 2, 'RefStav' => 2, 'DatStav' => '2026-03-02', 'Datum' => self::PETR_END]);
        // Registrace JMHZ: Janin trvající vztah odeslaný, Petrova přihláška neodeslaná.
        $row('RegZAM', ['ID' => 1, 'RelStavDP' => 7, 'DatPod' => '2026-04-10', 'DatPrij' => '2026-04-10', 'ElOdeslano' => 1]);
        $row('RegZAMitems', ['ID' => 1, 'RefAg' => 1, 'RefZAM' => 1, 'RefPomer' => 1, 'RelTyp' => 3, 'OIC' => self::JANA_OIC, 'IDPPV' => self::JANA_ID_PPV]);
        $row('RegZAM', ['ID' => 2, 'RelStavDP' => 1, 'DatPod' => '2026-01-02', 'ElOdeslano' => 0]);
        $row('RegZAMitems', ['ID' => 2, 'RefAg' => 2, 'RefZAM' => 2, 'RefPomer' => 2, 'RelTyp' => 1]);
        // Starší odhláška ČSSZ (ONZ) Petrova vztahu, odeslaná.
        $row('ONZ', ['ID' => 1, 'RelStavDP' => 7, 'DatPod' => '2026-03-05', 'ElOdeslano' => 1]);
        $row('ONZpol', ['ID' => 1, 'RefAg' => 1, 'RefPomer' => 2, 'RelTyp' => 2, 'DatVstup' => '2026-01-01', 'DatOdch' => self::PETR_END]);

        $file = $dir . '/91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="' . self::ICO . '" year="' . self::YEAR . '" source="POHODA" state="ok">' . $x . '</mdbExport>');
        return $file;
    }
}
