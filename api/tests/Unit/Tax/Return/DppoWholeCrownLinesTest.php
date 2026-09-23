<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Řádky II. oddílu DPPO v celých korunách a součtové řádky jako součet UVEDENÝCH řádků.
 *
 * Pokyny k tiskopisu 25 5404: „Částky v jednotlivých položkách II. oddílu se uvádějí
 * v české měně a zaokrouhlené na celé koruny." Tiskopis: ř. 70 = 20 + 30 + 40 + 50 + 61
 * + 62, ř. 170 = 100 + 101 + 109 + … + 162, ř. 200 = 10 + 70 − 170, ř. 250 = 220 − 230
 * − 240 − 241 − 242 − 243, ř. 270 = 250 − 251 − 260 zaokrouhlený na tisíce dolů.
 *
 * Podklady mají haléře, které se při sčítání v haléřích přelijí přes korunu: ř. 70
 * v haléřích 1 701,35 → 1 701, kdežto 1 000 + 500 + 200 = 1 700. ř. 200 v haléřích
 * 100 999,60 → 101 000 (a ř. 270 = 101 000), kdežto 99 849 + 1 700 − 550 = 100 999
 * (a ř. 270 = 100 000). EPO takové přiznání odmítne na kontrole součtu.
 */
final class DppoWholeCrownLinesTest extends TestCase
{
    /** @return array<string,mixed> */
    private function compute(): array
    {
        return (new DppoReturnCalculator())->compute(
            [
                'vh' => 99849.45,
                'non_deductible_costs' => 1000.45,
                'depreciation' => ['tax' => 0, 'accounting' => 500.45],
                'disposal_tax_decrease' => 150.40,
                'period' => ['starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'],
            ],
            [
                'manual_increase_items' => [['text' => 'Pokuta', 'amount' => 200.45]],
                'manual_decrease_items' => [
                    ['text' => 'Paušál na dopravu', 'amount' => 100.40, 'kind' => DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL],
                    ['text' => 'Ostatní snížení', 'amount' => 300.40],
                ],
                'tax_paid_advances' => 1000.40,
            ],
            TaxConstants::forYear(2025),
        );
    }

    /** @return array<int,float> */
    private static function lines(array $result): array
    {
        $out = [];
        foreach ($result['lines'] as $line) {
            $out[(int) $line['line']] = (float) $line['value'];
        }
        return $out;
    }

    public function testEveryLineIsWholeCrowns(): void
    {
        foreach ($this->compute()['lines'] as $line) {
            self::assertSame(round($line['value']), $line['value'], 'ř. ' . $line['line'] . ' není v celých korunách');
        }
    }

    public function testSumLinesAreSumsOfReportedLines(): void
    {
        $l = self::lines($this->compute());

        self::assertSame(99849.0, $l[10]);
        self::assertSame(1000.0, $l[40]);
        self::assertSame(500.0, $l[50]);
        self::assertSame(200.0, $l[62]);
        self::assertSame(1700.0, $l[70], 'ř. 70 = 40 + 50 + 62, ne 1 701,35 zaokrouhleno');
        self::assertSame(100.0, $l[112]);
        self::assertSame(150.0, $l[160]);
        self::assertSame(300.0, $l[162]);
        self::assertSame(550.0, $l[170], 'ř. 170 = 112 + 160 + 162, ne 551,20 zaokrouhleno');
        self::assertSame(100999.0, $l[200], 'ř. 200 = 10 + 70 − 170');
        self::assertSame(100999.0, $l[250]);
        self::assertSame(100000.0, $l[270], 'ř. 270 z ř. 250 v celých korunách, ne z haléřového 101 000');
        self::assertSame(21000.0, $l[290]);
        self::assertSame(21000.0, $l[340]);

        $increase = array_sum(array_intersect_key($l, array_flip([20, 30, 40, 50, 61, 62])));
        $decrease = array_sum(array_intersect_key($l, array_flip([100, 101, 109, 110, 111, 112, 120, 130, 140, 150, 160, 161, 162])));
        self::assertSame($increase, $l[70]);
        self::assertSame($decrease, $l[170]);
        self::assertSame($l[10] + $l[70] - $l[170], $l[200]);
    }

    public function testBalanceDueIsWholeCrowns(): void
    {
        $r = $this->compute();
        self::assertSame(1000.0, $r['advances_paid']);
        self::assertSame(20000.0, $r['balance_due']);
        self::assertSame(21000.0, $r['summary']['total_tax']);
        self::assertSame(100999.0, $r['summary']['base']);
    }

    /**
     * Strop darů „nejvýše 30 %" ze ř. 250 = 100 999 je 30 299,70 Kč. Na celé koruny smí
     * být odečet nejvýš 30 299 — matematické zaokrouhlení na 30 300 by strop překročilo.
     */
    public function testDonationCapRoundsDown(): void
    {
        $r = (new DppoReturnCalculator())->compute(
            ['vh' => 100999],
            ['donations' => 50000],
            TaxConstants::forYear(2025),
        );
        $l = self::lines($r);
        self::assertSame(100999.0, $l[250]);
        self::assertSame(30299.0, $l[260]);
        self::assertSame(70000.0, $l[270]);
    }

    /** Tabulka B přílohy č. 1: ř. 11 = součet uvedených ř. 1–10, ne 301 z 300,80 Kč. */
    public function testDepreciationTableTotalIsSumOfReportedRows(): void
    {
        $calc = $this->compute();
        $calc['depreciation_by_group'] = ['tangible' => [1 => 100.40, 2 => 200.40], 'intangible' => 0.0, 'unclassified' => 0.0];
        $dom = new \DOMDocument();
        $dom->loadXML($this->buildXml($calc));
        $vetaF = $dom->getElementsByTagName('VetaF')->item(0);
        self::assertInstanceOf(\DOMElement::class, $vetaF);
        self::assertSame('100', $vetaF->getAttribute('kc_dppb1'));
        self::assertSame('200', $vetaF->getAttribute('kc_dppb2'));
        self::assertSame('300', $vetaF->getAttribute('kc_dppb6_b8'));
    }

    /** @param array<string,mixed> $calc */
    private function buildXml(array $calc): string
    {
        return (new DppoXmlBuilder())->build([
            'id' => 1,
            'company_name' => 'Ukázková firma s.r.o.',
            'street' => 'Zkušební 123/4',
            'city' => 'Vzorov',
            'zip' => '100 00',
            'country_iso2' => 'CZ',
            'ic' => '12345678',
            'dic' => 'CZ12345678',
            'taxpayer_type' => 'po',
            'financial_office_code' => '451',
            'cz_nace_code' => '62020',
        ], 2025, $calc)['xml'];
    }

    public function testXmlSumAttributesMatchReportedLinesAndPassXsd(): void
    {
        $xml = $this->buildXml($this->compute());

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $vetaO = $dom->getElementsByTagName('VetaO')->item(0);
        self::assertInstanceOf(\DOMElement::class, $vetaO);
        $a = static fn (string $name): int => (int) $vetaO->getAttribute($name);

        self::assertSame(
            $a('kc_ii30_20') + $a('kc_ii40_30') + $a('kc_ii50_40') + $a('kc_ii60_50') + $a('kc_ii71_61') + $a('kc_ii72_62'),
            $a('kc_ii80_70'),
        );
        self::assertSame(
            $a('kc_ii110_100') + $a('kc_ii111_101') + $a('kc_ii_109') + $a('kc_ii120_110') + $a('kc_ii_111') + $a('kc_ii_112')
                + $a('kc_ii130_120') + $a('kc_ii140_130') + $a('kc_ii150_140') + $a('kc_ii170_150') + $a('kc_ii180_160')
                + $a('kc_ii181_161') + $a('kc_ii182_162'),
            $a('kc_ii190_170'),
        );
        self::assertSame($a('kc_ii10_10') + $a('kc_ii80_70') - $a('kc_ii190_170'), $a('kc_ii200_200'));
        self::assertSame($a('kc_ii200_200'), $a('kc_ii_220'));
        self::assertSame($a('kc_ii_220') - $a('kc_ii210_230') - $a('kc_ii_242') - $a('kc_ii_243'), $a('kc_ii230_250'));
        self::assertSame(intdiv($a('kc_ii230_250') - $a('kc_ii240_260'), 1000) * 1000, $a('kc_ii260_270'));

        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dppdp9')) {
            self::markTestSkipped('XSD dppdp9 není k dispozici.');
        }
        $validation = $validator->validate($xml, 'dppdp9');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }
}
