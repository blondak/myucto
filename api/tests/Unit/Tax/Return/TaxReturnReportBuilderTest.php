<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Pdf\TaxReturnReportPdfRenderer;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\TaxReturnLineCatalog;
use MyInvoice\Service\Tax\Return\TaxReturnReportBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pracovní PDF sestava přiznání: builder čte částky jen z XML. Syntetické XML obou
 * typů (DPPO s přílohou účetní závěrky, DPFO s přílohami 1 a 2) bez databáze.
 */
final class TaxReturnReportBuilderTest extends TestCase
{
    public function testPoSummaryLinesAndStatementsComeFromXml(): void
    {
        $report = (new TaxReturnReportBuilder())->build(self::poSource(), self::poLabels(), new \DateTimeImmutable('2026-03-01 10:00'));

        self::assertSame('DPPDP9', $report['form_code']);
        self::assertSame('Alfa Test s.r.o.', $report['header']['name']);
        self::assertSame('12345678', $report['header']['ic']);
        self::assertSame('CZ12345678', $report['header']['dic']);
        self::assertSame('Zkušební 123/4, 10000 Vzorov', $report['header']['address']);
        self::assertSame('01.01.2025', $report['header']['period_from']);
        self::assertStringContainsString('mikro', (string) $report['header']['scope']);
        self::assertSame('01.03.2026 10:00', $report['header']['generated_at']);

        // Doplatek = −kc_v_4 z věty D, ne z výpočtu (výpočet tu schválně nese jiné číslo).
        self::assertSame(['label' => 'Doplatek daně', 'value' => 128600.0, 'tone' => 'due'], $report['summary']['result']);
        $summary = array_column($report['summary']['rows'], 'value', 'ref');
        self::assertSame(700000.0, $summary['ř. 200']);
        self::assertSame(21.0, $summary['ř. 280']);
        self::assertSame(10000.0, $summary['V. oddíl']);
        self::assertSame(138600.0, $report['summary']['advances']['last_known_tax']);
        self::assertSame('quarterly', $report['summary']['advances']['regime']);

        $lines = array_column($report['lines']['rows'], 'value', 'code');
        self::assertSame(50000.0, $lines['40']);
        self::assertSame(138600.0, $lines['330']);
        self::assertSame(0.0, $lines['310'], 'Klíčový řádek chybějící v XML se ukáže jako nula.');
        self::assertArrayNotHasKey('62', $lines, 'Nulový řádek, který XML nenese, v sestavě není.');
        self::assertSame(TaxReturnLineCatalog::PO[40], array_column($report['lines']['rows'], 'label', 'code')['40']);
        self::assertSame(TaxReturnLineCatalog::PO[200], array_column($report['lines']['rows'], 'label', 'code')['200'],
            'Popisek řádku nesmí záviset na tom, zda výpočet řádek nese.');

        [$assets, $liabilities, $income] = $report['statements'];
        self::assertSame(['assets', 'liabilities', 'income_statement'], [$assets['key'], $liabilities['key'], $income['key']]);
        self::assertSame([1, 37, 71], array_column($assets['rows'], 'number'));
        self::assertSame(['', 'C.', 'C.IV.'], array_column($assets['rows'], 'code'));
        self::assertSame([0, 1, 2], array_column($assets['rows'], 'level'));
        self::assertSame([1200, 100, 1100, 900], $assets['rows'][0]['values']);
        self::assertSame('Oběžná aktiva', $assets['rows'][1]['label']);
        self::assertFalse($assets['rows'][2]['strong'], 'Detailní řádek není mezisoučet.');

        $byNumber = array_column($liabilities['rows'], null, 'number');
        self::assertSame('A.', $byNumber[2]['code'], 'Pasiva se tisknou bez interního prefixu P.');
        self::assertSame(['B.+C.', 'Cizí zdroje'], [$byNumber[24]['code'], $byNumber[24]['label']]);

        $vzz = array_column($income['rows'], null, 'number');
        self::assertSame(['I.', 'Úpravy hodnot a rezervy ve finanční oblasti'], [$vzz[42]['code'], $vzz[42]['label']],
            'Ř. 42 je druhé I. ve VZZ (I.n), ne nákladové úroky.');
        self::assertSame(['J.', 'Nákladové úroky a podobné náklady'], [$vzz[43]['code'], $vzz[43]['label']]);
        self::assertSame(['I.', 'Tržby z prodeje výrobků a služeb'], [$vzz[1]['code'], $vzz[1]['label']]);
        self::assertSame('', $vzz[30]['code']);
        self::assertTrue($vzz[30]['strong']);
        self::assertSame([-50, 20], $vzz[42]['values']);

        $notes = implode(' ', $report['notes']);
        self::assertStringContainsString('tabulku H', $notes);
        self::assertStringContainsString('Příloha v účetní závěrce', $notes);

        $titles = array_column($report['tables'], 'title');
        self::assertContains('Daňové ztráty (§ 34)', $titles);
        self::assertContains('Vstupy přiznání zadané uživatelem', $titles);
        $items = $report['tables'][array_search('Ruční položky zvyšující základ daně (§ 23, § 25)', $titles, true)];
        self::assertStringContainsString('paušální výdaj na dopravu', (string) $items['rows'][0]['cells'][0]['value']);
        self::assertSame(['Syntetické varování.'], $report['warnings']);
    }

    public function testPoAmendmentReadsDifferenceFromVetaD(): void
    {
        $source = self::poSource();
        $source['xml'] = str_replace('dapdpp_forma="B"', 'dapdpp_forma="D" d_zjist="15.04.2026" kc_dppiv1="138600" kc_dppiv2="100000" kc_dppiv3="38600"', $source['xml']);
        $report = (new TaxReturnReportBuilder())->build($source, self::poLabels());

        self::assertSame('dodatečné přiznání', $report['header']['variant_label']);
        self::assertSame([138600.0, 100000.0, 38600.0, '2026-04-15'], array_column($report['amendment'], 'value'));
    }

    public function testFoReadsAppendicesAndNeverPrintsBirthNumbers(): void
    {
        $report = (new TaxReturnReportBuilder())->build(self::foSource());

        self::assertSame('Přiznání k dani z příjmů fyzických osob', $report['title']);
        self::assertSame('Jan Testový', $report['header']['name']);
        self::assertSame('87654321', $report['header']['ic'], 'IČO z evidence firmy, rod_c z věty P se netiskne.');
        self::assertSame(['label' => 'Zbývá doplatit', 'value' => 39160.0, 'tone' => 'due'], $report['summary']['result']);

        $lines = array_column($report['lines']['rows'], 'value', 'code');
        self::assertSame(105750.0, $lines['57']);
        self::assertSame(705000.0, $lines['42']);
        self::assertSame(39160.0, $lines['91']);
        self::assertArrayNotHasKey('38', $lines, 'Nulový nepovinný řádek se nevypisuje.');

        $tables = array_column($report['tables'], null, 'title');
        self::assertArrayHasKey('Příloha č. 1: příjmy ze samostatné činnosti', $tables);
        self::assertStringContainsString('60 %', (string) $tables['Příloha č. 1: příjmy ze samostatné činnosti']['rows'][1]['cells'][1]['value']);
        self::assertCount(2, $tables['Příloha č. 1: činnosti']['rows']);
        self::assertSame('Syntetická úprava', $tables['Příloha č. 1, oddíl E: úpravy dílčího základu podle § 23']['rows'][0]['cells'][1]['value']);
        self::assertSame(10000.0, $tables['Příloha č. 1: majetek a dluhy (§ 7b)']['rows'][0]['cells'][2]['value']);
        self::assertArrayHasKey('Příloha č. 2: druhy ostatních příjmů (§ 10)', $tables);
        self::assertSame('12', $tables['Vyživované děti (§ 35c)']['rows'][0]['cells'][1]['value']);
        self::assertSame(12, $tables['Manžel / manželka (§ 35ba odst. 1 písm. b)']['rows'][0]['cells'][1]['value']);
        self::assertStringContainsString('Přehledy pojistného OSVČ', implode(' ', $report['notes']));

        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('1501011234', (string) $encoded, 'Rodné číslo dítěte do sestavy nepatří.');
    }

    public function testCatalogCoversEveryLineTheXmlCanCarry(): void
    {
        foreach (array_keys(DppoXmlBuilder::LINE_ATTR) as $line) {
            self::assertArrayHasKey($line, TaxReturnLineCatalog::PO, "Řádek DPPDP9 {$line} nemá v číselníku popisek.");
        }
        foreach ([220, 280, 330] as $line) {
            self::assertArrayHasKey($line, TaxReturnLineCatalog::PO);
        }
    }

    public function testNoRowHasGenericLabelAndSummaryUsesTableLabels(): void
    {
        $builder = new TaxReturnReportBuilder();
        foreach ([$builder->build(self::poSource(), self::poLabels()), $builder->build(self::foSource())] as $report) {
            $tableLabels = array_column($report['lines']['rows'], 'label', 'code');
            $all = array_merge(
                array_column($report['lines']['rows'], 'label'),
                array_column($report['summary']['rows'], 'label'),
            );
            foreach ($report['statements'] as $statement) {
                $all = array_merge($all, array_column($statement['rows'], 'label'));
            }
            foreach ($all as $label) {
                self::assertDoesNotMatchRegularExpression('/^Řádek \d+$/u', (string) $label);
                self::assertNotSame('', trim((string) $label));
            }
            foreach ($report['summary']['rows'] as $row) {
                if (preg_match('/^ř\. (\d+)$/u', (string) $row['ref'], $m) === 1 && isset($tableLabels[$m[1]])) {
                    self::assertSame($tableLabels[$m[1]], $row['label'], "Souhrn a tabulka pojmenovávají ř. {$m[1]} jinak.");
                }
            }
        }
    }

    public function testRendererFormatsNumbersReadably(): void
    {
        self::assertSame("\u{2212}1\u{00A0}235", TaxReturnReportPdfRenderer::formatValue(-1234.5, 'money'));
        self::assertSame("138\u{00A0}600", TaxReturnReportPdfRenderer::formatValue(138600.0, 'money'));
        self::assertSame("1\u{00A0}234,50", TaxReturnReportPdfRenderer::formatValue(1234.5, 'money2'));
        self::assertSame("21\u{00A0}%", TaxReturnReportPdfRenderer::formatValue(21.0, 'percent'));
        self::assertSame("\u{2013}", TaxReturnReportPdfRenderer::formatValue(null, 'money'));
        self::assertSame('15.04.2026', TaxReturnReportPdfRenderer::formatValue('2026-04-15', 'date'));
    }

    /** @return array<string,mixed> */
    public static function poSource(): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost nazevSW="MyUcto" verzeSW="1"><DPPDP9 verzePis="09.01">'
            . '<VetaD dokument="DP9" k_uladis="DPP" dapdpp_forma="B" zdobd_od="01.01.2025" zdobd_do="31.12.2025" kc_v_1="10000" kc_v_4="-128600" kat_uj="M" uv_rozsah_rozv="M" uv_rozsah_vzz="P"/>'
            . '<VetaP zkrobchjm="Alfa Test s.r.o." rod_c="12345678" dic="12345678" ulice="Zkušební" c_pop="123" c_orient="4" naz_obce="Vzorov" psc="10000"/>'
            . '<VetaO kc_ii10_10="650000" kc_ii50_40="50000" kc_ii80_70="50000" kc_ii200_200="700000" kc_ii_220="700000" kc_ii230_250="700000" kc_ii240_260="40000" kc_ii260_270="660000" kc_ii270_280="21" kc_ii280_290="138600" kc_ii320_330="138600" kc_ii_340="138600" kc_ii_360="138600"/>'
            . '<VetaM kc_dpp_f1="0" kc_dpp_f2="0" kc_dpp_f4="0"/>'
            . '<VetaUA c_radku="1" kc_brutto="1200" kc_korekce="100" kc_netto="1100" kc_netto_min="900"/>'
            . '<VetaUA c_radku="37" kc_brutto="700" kc_korekce="0" kc_netto="700" kc_netto_min="500"/>'
            . '<VetaUA c_radku="71" kc_brutto="600" kc_korekce="0" kc_netto="600" kc_netto_min="400"/>'
            . '<VetaUB c_radku="1" kc_min="0" kc_sled="1000"/>'
            . '<VetaUB c_radku="30" kc_min="10" kc_sled="700"/>'
            . '<VetaUB c_radku="42" kc_min="20" kc_sled="-50"/>'
            . '<VetaUB c_radku="43" kc_min="5" kc_sled="30"/>'
            . '<VetaUD kc_sled="1100" c_radku="1" kc_min="900"/>'
            . '<VetaUD kc_sled="800" c_radku="2" kc_min="600"/>'
            . '<VetaUD kc_sled="300" c_radku="24" kc_min="300"/>'
            . '<Prilohy><PredepsanaPriloha kod="PP_OPISPUV" cislo="1" nazev="priloha.pdf">AAAA</PredepsanaPriloha></Prilohy>'
            . '</DPPDP9></Pisemnost>';

        return [
            'type' => 'po',
            'year' => 2025,
            'variant' => 'radne',
            'variant_seq' => 1,
            'status' => 'draft',
            'finalized_at' => null,
            'form_code' => 'dppdp9',
            'xml' => $xml,
            'warnings' => ['Syntetické varování.', ''],
            'computation' => ['result' => [
                'lines' => [
                    ['line' => 10, 'code' => '10', 'label' => 'Výsledek hospodaření před zdaněním', 'value' => 650000.0, 'source' => 'deník'],
                    ['line' => 40, 'code' => '40', 'label' => 'Výdaje neuznávané za náklady (§25)', 'value' => 50000.0, 'source' => 'nedaňové účty'],
                    ['line' => 62, 'code' => '62', 'label' => 'Ostatní částky zvyšující základ (§23)', 'value' => 0.0, 'source' => ''],
                ],
                'balance_due' => 999999.0,
                'next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 34700.0, 'note' => '4 čtvrtletní zálohy.'],
            ], 'podklady' => [], 'warnings' => []],
            'inputs' => [
                'tax_paid_advances' => 10000.0,
                'donations' => 40000.0,
                'manual_increase_items' => [['text' => 'Doprava paušálem', 'amount' => 5000.0, 'kind' => 'flat_rate_travel']],
            ],
            'tax_losses' => ['losses' => [
                ['origin_year' => 2022, 'amount' => 20000.0, 'applied' => 5000.0, 'remaining' => 15000.0, 'expires_year' => 2027],
            ], 'available_total' => 15000.0],
            'supplier' => ['name' => 'Alfa Test s.r.o.', 'ic' => '12345678', 'dic' => 'CZ12345678'],
            'snapshot' => null,
        ];
    }

    /** @return array<string,array<string,array{label:string,level:int,row_type:string}>> */
    public static function poLabels(): array
    {
        return [
            'balance_sheet' => [
                'AKTIVA' => ['label' => 'AKTIVA CELKEM', 'level' => 0, 'row_type' => 'subtotal'],
                'C.' => ['label' => 'Oběžná aktiva', 'level' => 1, 'row_type' => 'subtotal'],
                'C.IV.' => ['label' => 'Peněžní prostředky', 'level' => 2, 'row_type' => 'detail'],
                'PASIVA' => ['label' => 'PASIVA CELKEM', 'level' => 0, 'row_type' => 'subtotal'],
                'P.A.' => ['label' => 'Vlastní kapitál', 'level' => 1, 'row_type' => 'subtotal'],
            ],
            'income_statement' => [
                'I.' => ['label' => 'Tržby z prodeje výrobků a služeb', 'level' => 1, 'row_type' => 'detail'],
                'PVH' => ['label' => 'Provozní výsledek hospodaření (±)', 'level' => 1, 'row_type' => 'computed'],
                'I.n' => ['label' => 'Úpravy hodnot a rezervy ve finanční oblasti', 'level' => 1, 'row_type' => 'detail'],
                'J.' => ['label' => 'Nákladové úroky a podobné náklady', 'level' => 1, 'row_type' => 'subtotal'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function foSource(): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost nazevSW="MyUcto" verzeSW="1"><DPFDP7 verzePis="07.01">'
            . '<VetaD dokument="DP7" k_uladis="DPF" rok="2025" dap_typ="B" zdobd_od="1.1.2025" zdobd_do="31.12.2025" uhrn_slevy35ba="30840" da_slevy35ba="74910" da_slevy35c="74910" kc_zalpred="35750" kc_zbyvpred="39160" manz_jmeno="Eva" manz_prijmeni="Testová" m_manz="12" kc_op15_1c="24840"/>'
            . '<VetaP jmeno="Jan" prijmeni="Testový" rod_c="7801011234" dic="7801011234" ulice="Krátká" c_pop="12" c_orient="3" naz_obce="Praha" psc="11000"/>'
            . '<VetaO kc_prij6="600000" kc_zd6="600000" kc_zd7="100000" kc_zakldan8="0" kc_zd9="0" kc_zd10="5000" kc_uhrn="105000" kc_zakldan="705000" kc_zakldan23="705000"/>'
            . '<VetaS kc_odcelk="0" kc_zdsniz="705000" kc_zdzaokr="705000" da_dan16="105750"/>'
            . '<VetaA vyzdite_jmeno="Petr" vyzdite_prijmeni="Testový" vyzdite_r_cislo="1501011234" vyzdite_pocmes="12" vyzdite_ztpp="0" vyzdite_pocmes2="0" vyzdite_ztpp2="0" vyzdite_pocmes3="0" vyzdite_ztpp3="0"/>'
            . '<VetaB priloha1="1" priloha2="1"/>'
            . '<VetaT kc_prij7="400000" kc_vyd7="240000" kc_hosp_rozd="160000" kc_zd7p="100000" kc_uhzvys="1000" kc_uhsniz="0" vyd7proc="A" pr_sazba="60" c_nace="620200" m_podnik="12" pr_prij7="300000" pr_vyd7="180000"/>'
            . '<Vetac prijmy7="100000" vydaje7="60000" sazba_dal="60" c_nace_dal="749000"/>'
            . '<VetaU kc_dpfmz02="0" kc_z_dpfmz02="10000" kc_dpfmz18="50000"/>'
            . '<VetaC kc_uprzvys_235="1000" uprzvys_235="Syntetická úprava"/>'
            . '<VetaV kc_prij10="8000" kc_vyd10="3000" kc_zd10p="5000" uhrn_prijmy10="8000" uhrn_vydaje10="3000" uhrn_rozdil10="5000"/>'
            . '<VetaJ prijmy10="8000" vydaje10="3000" rozdil10="5000" druh_prij10="Příležitostný příjem" kod_dr_prij10="A"/>'
            . '</DPFDP7></Pisemnost>';

        return [
            'type' => 'fo',
            'year' => 2025,
            'variant' => 'radne',
            'variant_seq' => 1,
            'status' => 'final',
            'finalized_at' => '2026-03-20 09:15:00',
            'form_code' => 'dpfdp7',
            'xml' => $xml,
            'warnings' => [],
            'computation' => ['result' => [
                'lines' => [
                    ['line' => '31', 'code' => '31', 'label' => 'Příjmy ze závislé činnosti (§6)', 'value' => 600000.0, 'source' => ''],
                    ['line' => '38', 'code' => '38', 'label' => 'Dílčí základ §8 (kapitálový majetek)', 'value' => 0.0, 'source' => ''],
                    ['line' => '42', 'code' => '42', 'label' => 'Základ daně', 'value' => 705000.0, 'source' => ''],
                    ['line' => '57', 'code' => '57', 'label' => 'Daň (15 % / 23 % §16)', 'value' => 105750.0, 'source' => ''],
                    ['line' => '91', 'code' => '91', 'label' => 'Doplatek (+) / přeplatek (−)', 'value' => 39160.0, 'source' => ''],
                ],
                'tax' => 74910.0,
                'next_advances' => ['regime' => 'semiannual', 'amount' => 30000.0, 'count' => 2, 'filing_deadline' => '2026-04-01'],
                'summary' => ['separate_base' => 0.0],
            ], 'podklady' => [], 'warnings' => []],
            'inputs' => ['s6_employment' => ['income' => 600000.0, 'withholding' => 0.0]],
            'tax_losses' => ['losses' => [], 'available_total' => 0.0],
            'supplier' => ['name' => 'Jan Testový', 'ic' => '87654321', 'dic' => 'CZ7801011234'],
            'snapshot' => ['revision_no' => 1],
        ];
    }
}
