<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\BadDebt;

use PHPUnit\Framework\TestCase;

/**
 * §2.5 (audit PODVOJNE-AUDIT.md) — § 74b ZDPH: řádkové mapování korekce odpočtu dlužníka
 * do DPHDP3 MUSÍ být XSD-validní a se správnými znaménky (potvrzený výklad + XSD anotace):
 *
 *   SNÍŽENÍ  (odst. 1/3): ř. 34 opr_dluz (Veta3) KLADNĚ; ř. 40/41 pln23/odp_tuz23_nar,
 *                          pln5/odp_tuz5_nar (Veta4) ZÁPORNĚ (základ i daň).
 *   OBNOVA   (odst. 2/4): znaménka obrácená.
 *
 * Bez DB — sestaví DPHDP3 kostru přímo přes DOMDocument a validuje proti api/xsd/dphdp3.xsd
 * (libxml schemaValidate). Ověřuje, že opr_dluz sedí na Veta3, pln/odp na Veta4 a že XSD
 * přijme kladné i záporné hodnoty na těchto uzlech.
 */
final class Section74bDphdp3XsdTest extends TestCase
{
    private string $xsdPath = '';

    protected function setUp(): void
    {
        $this->xsdPath = dirname(__DIR__, 5) . '/api/xsd/dphdp3.xsd';
        if (!is_file($this->xsdPath)) {
            $this->markTestSkipped('api/xsd/dphdp3.xsd chybí — nelze validovat.');
        }
    }

    public function testReductionIsXsdValidWithCorrectSigns(): void
    {
        // Snížení odpočtu: ř. 40 (21 %) základ −10 000 / daň −2 100, ř. 41 (12 %) −5 000 / −600,
        // ř. 34 opr_dluz = +(2 100 + 600) = +2 700 (informativní, kladně).
        $dom = $this->buildDphdp3(
            oprDluz: 2700,
            pln23: -10000, odpTuz23: -2100,
            pln5: -5000, odpTuz5: -600,
        );

        $this->assertXsdValid($dom);

        $veta3 = $dom->getElementsByTagName('Veta3')->item(0);
        $veta4 = $dom->getElementsByTagName('Veta4')->item(0);
        self::assertNotNull($veta3);
        self::assertNotNull($veta4);
        // ř. 34 opr_dluz KLADNĚ (snížení dle §74b odst. 1/3).
        self::assertSame('2700', $veta3->getAttribute('opr_dluz'));
        // ř. 40/41 základ i daň ZÁPORNĚ.
        self::assertSame('-10000', $veta4->getAttribute('pln23'));
        self::assertSame('-2100', $veta4->getAttribute('odp_tuz23_nar'));
        self::assertSame('-5000', $veta4->getAttribute('pln5'));
        self::assertSame('-600', $veta4->getAttribute('odp_tuz5_nar'));
    }

    public function testRestorationIsXsdValidWithInvertedSigns(): void
    {
        // Obnova po úhradě: znaménka obrácená — ř. 40/41 kladně, ř. 34 opr_dluz záporně.
        $dom = $this->buildDphdp3(
            oprDluz: -2700,
            pln23: 10000, odpTuz23: 2100,
            pln5: 5000, odpTuz5: 600,
        );

        $this->assertXsdValid($dom);

        $veta3 = $dom->getElementsByTagName('Veta3')->item(0);
        $veta4 = $dom->getElementsByTagName('Veta4')->item(0);
        self::assertNotNull($veta3);
        self::assertNotNull($veta4);
        self::assertSame('-2700', $veta3->getAttribute('opr_dluz'));
        self::assertSame('10000', $veta4->getAttribute('pln23'));
        self::assertSame('2100', $veta4->getAttribute('odp_tuz23_nar'));
    }

    private function buildDphdp3(int $oprDluz, int $pln23, int $odpTuz23, int $pln5, int $odpTuz5): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $dom->appendChild($pisemnost);
        $dphdp3 = $dom->createElement('DPHDP3');
        $dphdp3->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphdp3);

        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('dokument', 'DP3');
        $vetaD->setAttribute('dapdph_forma', 'B');
        $vetaD->setAttribute('rok', '2025');
        $vetaD->setAttribute('mesic', '7');
        $vetaD->setAttribute('typ_platce', 'P');
        $dphdp3->appendChild($vetaD);

        $vetaP = $dom->createElement('VetaP');
        $vetaP->setAttribute('c_ufo', '451');
        $vetaP->setAttribute('dic', '12345678'); // XSD pattern [0-9]{1,10} — bez CZ prefixu
        $vetaP->setAttribute('typ_ds', 'P');
        $dphdp3->appendChild($vetaP);

        // ř. 34 opr_dluz → Veta3 (oddíl C).
        $veta3 = $dom->createElement('Veta3');
        $veta3->setAttribute('opr_dluz', (string) $oprDluz);
        $dphdp3->appendChild($veta3);

        // ř. 40/41 → Veta4 (odpočet); ř. 46 odp_sum_nar = součet daní ř. 40/41.
        $veta4 = $dom->createElement('Veta4');
        $veta4->setAttribute('pln23', (string) $pln23);
        $veta4->setAttribute('odp_tuz23_nar', (string) $odpTuz23);
        $veta4->setAttribute('pln5', (string) $pln5);
        $veta4->setAttribute('odp_tuz5_nar', (string) $odpTuz5);
        $veta4->setAttribute('odp_sum_nar', (string) ($odpTuz23 + $odpTuz5));
        $dphdp3->appendChild($veta4);

        return $dom;
    }

    private function assertXsdValid(\DOMDocument $dom): void
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = @$dom->schemaValidate($this->xsdPath);
        $errors = [];
        foreach (libxml_get_errors() as $err) {
            $errors[] = trim($err->message) . ' (line ' . $err->line . ')';
        }
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        self::assertTrue($valid, "DPHDP3 §74b XML neprošlo XSD:\n  - " . implode("\n  - ", $errors));
    }
}
