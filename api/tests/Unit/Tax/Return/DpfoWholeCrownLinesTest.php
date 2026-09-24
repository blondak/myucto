<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Řádky DPFO v celých korunách a součtové řádky jako součet UVEDENÝCH řádků.
 *
 * Pokyny k tiskopisu 25 5405: „Částky v následujících oddílech uveďte v celých Kč …
 * Postupné zaokrouhlování ve dvou nebo více stupních je nepřípustné." Tiskopis:
 * ř. 41 = 37 + 38 + 39 + 40, ř. 42 = 36 + kladný ř. 41, ř. 45 = 42 − 44, ř. 54 = 46
 * + … + 53, ř. 55 = 45 − 54; Příloha 1 ř. 104 = 101 − 102, ř. 113 = 104 + 105 − 106;
 * Příloha 2 ř. 203 = 201 − 202, sloupec 3 tabulky § 10 dává úhrn výdajů.
 *
 * Haléře podkladů se v haléřovém součtu přelijí: úhrn § 7–§ 10 v haléřích je
 * 73 001,10 → 73 001, kdežto uvedené řádky 39 999 + 1 000 + 30 000 + 2 000 = 72 999.
 * Příloha 1: 100 000,45 − 60 000,60 = 39 999,85 → 40 000, kdežto 100 000 − 60 001
 * = 39 999.
 */
final class DpfoWholeCrownLinesTest extends TestCase
{
    /** @return array<string,mixed> */
    private function compute(): array
    {
        return (new DpfoReturnCalculator())->compute(
            ['s7_income' => 100000.45, 's7_expenses' => 60000.60, 'expense_mode' => 'actual', 'expense_rate' => 0],
            [
                's6_employment' => ['income' => 200000.45, 'withholding' => 20000.45],
                's8_capital' => ['base' => 1000.45],
                's9_rental' => ['income' => 50000.45, 'expenses' => 20000.00],
                's10_items' => [['income' => 3000.45, 'expenses' => 1000.10, 'text' => 'Příležitostný prodej', 'kind_code' => 'A']],
                'tax_paid_advances' => 1000.45,
            ],
            ['mortgage_interest' => 1000.45, 'pension_contrib' => 2000.45, 'donations' => 5000.45],
            TaxConstants::forYear(2025),
        );
    }

    public function testEveryLineAndFieldIsWholeCrowns(): void
    {
        $r = $this->compute();
        foreach ($r['lines'] as $line) {
            self::assertSame(round($line['value']), $line['value'], 'ř. ' . $line['line'] . ' není v celých korunách');
        }
        foreach ($r['fields'] as $name => $value) {
            self::assertSame(round($value), $value, $name . ' není v celých korunách');
        }
        foreach (['income', 'expenses', 'base', 'before_adjustments'] as $key) {
            self::assertSame(round((float) $r['s7'][$key]), (float) $r['s7'][$key], 's7.' . $key);
        }
    }

    public function testSumLinesAreSumsOfReportedLines(): void
    {
        $r = $this->compute();
        $f = $r['fields'];

        self::assertSame(100000.0, $r['s7']['income']);
        self::assertSame(60001.0, $r['s7']['expenses']);
        self::assertSame(39999.0, $r['s7']['before_adjustments'], 'Příloha 1 ř. 104 = 101 − 102');
        self::assertSame(39999.0, $f['kc_zd7']);
        self::assertSame(1000.0, $f['kc_zakldan8']);
        self::assertSame(30000.0, $f['kc_zd9']);
        self::assertSame(2000.0, $f['kc_zd10']);
        self::assertSame(72999.0, $f['kc_uhrn'], 'ř. 41 = 37 + 38 + 39 + 40');
        self::assertSame($f['kc_zd7'] + $f['kc_zakldan8'] + $f['kc_zd9'] + $f['kc_zd10'], $f['kc_uhrn']);
        self::assertSame(200000.0, $f['kc_zd6p']);
        self::assertSame($f['kc_zd6p'] + max(0.0, $f['kc_uhrn']), $f['kc_zakldan23'], 'ř. 42 = 36 + 41');
        self::assertSame($f['kc_zakldan23'] - ($f['kc_ztrata2'] ?? 0.0), $f['kc_zakldan'], 'ř. 45 = 42 − 44');
        self::assertSame(1000.0, $f['kc_op28_5']);
        self::assertSame(2000.0, $f['kc_op15_12']);
        self::assertSame(5000.0, $f['kc_op15_8']);
        self::assertSame(
            $f['kc_op15_8'] + $f['kc_op28_5'] + $f['kc_op15_12'] + $f['kc_op15_13'] + $f['kc_op15_inpr'] + $f['kc_op15_pece'],
            $f['kc_odcelk'],
            'ř. 54 = 46 + … + 53',
        );
        self::assertSame($f['kc_zakldan'] - $f['kc_odcelk'], $f['kc_zdsniz'], 'ř. 55 = 45 − 54');
        self::assertSame(20000.0, $f['kc_zalzavc']);
        self::assertSame(1000.0, $f['kc_zalpred']);
    }

    /** Tabulka B Přílohy 1: ř. 101/102 jsou součtem řádků činností v celých korunách. */
    public function testActivityTotalsAreSumsOfRoundedActivityRows(): void
    {
        $r = (new DpfoReturnCalculator())->compute(
            ['activities' => [
                ['income' => 1000.45, 'expense_mode' => 'pausal', 'expense_rate' => 60],
                ['income' => 2000.45, 'expense_mode' => 'pausal', 'expense_rate' => 60],
            ], 'expense_mode' => 'pausal', 'expense_rate' => 60],
            [],
            [],
            TaxConstants::forYear(2025),
        );
        $items = $r['s7']['activities'];
        self::assertSame([1000.0, 2000.0], array_column($items, 'income'));
        self::assertSame([600.0, 1200.0], array_column($items, 'expenses'));
        self::assertSame(3000.0, $r['s7']['income'], 'ne 3 000,90 → 3 001');
        self::assertSame(1800.0, $r['s7']['expenses'], 'ne 1 800,54 → 1 801');
        self::assertSame(1200.0, $r['s7']['base']);
    }

    public function testXmlSumAttributesMatchReportedLinesAndPassXsd(): void
    {
        $xml = (new DpfoXmlBuilder())->build([
            'id' => 1,
            'company_name' => 'Jan Novák',
            'street' => 'Krátká 12/3',
            'city' => 'Praha',
            'zip' => '110 00',
            'country_iso2' => 'CZ',
            'ic' => '87654321',
            'dic' => 'CZ7801011234',
            'taxpayer_type' => 'fo',
            'financial_office_code' => '451',
            'cz_nace_code' => '62020',
        ], 2025, $this->compute())['xml'];

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $el = static function (string $name) use ($dom): \DOMElement {
            $node = $dom->getElementsByTagName($name)->item(0);
            self::assertInstanceOf(\DOMElement::class, $node, $name);
            return $node;
        };
        $n = static fn (\DOMElement $e, string $attr): int => (int) $e->getAttribute($attr);

        $t = $el('VetaT');
        self::assertSame($n($t, 'kc_prij7') - $n($t, 'kc_vyd7'), $n($t, 'kc_hosp_rozd'), 'Příloha 1 ř. 104');
        self::assertSame($n($t, 'kc_hosp_rozd') + $n($t, 'kc_uhzvys') - $n($t, 'kc_uhsniz'), $n($t, 'kc_zd7p'), 'Příloha 1 ř. 113');

        $o = $el('VetaO');
        self::assertSame($n($t, 'kc_zd7p'), $n($o, 'kc_zd7'));
        self::assertSame($n($o, 'kc_zd7') + $n($o, 'kc_zakldan8') + $n($o, 'kc_zd9') + $n($o, 'kc_zd10'), $n($o, 'kc_uhrn'));

        $v = $el('VetaV');
        self::assertSame($n($v, 'kc_prij9') - $n($v, 'kc_vyd9'), $n($v, 'kc_rozdil9'), 'Příloha 2 ř. 203');
        $j = $el('VetaJ');
        self::assertSame($n($j, 'prijmy10') - $n($j, 'vydaje10'), $n($j, 'rozdil10'));
        self::assertSame($n($j, 'vydaje10'), $n($v, 'kc_vyd10'));
        self::assertSame($n($j, 'prijmy10'), $n($v, 'kc_prij10'));

        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dpfdp7')) {
            self::markTestSkipped('XSD dpfdp7 není k dispozici.');
        }
        $validation = $validator->validate($xml, 'dpfdp7');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }
}
