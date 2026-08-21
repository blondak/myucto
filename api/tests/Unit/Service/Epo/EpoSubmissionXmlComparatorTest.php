<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoSubmissionXmlComparator;
use PHPUnit\Framework\TestCase;

/**
 * Rozdíl ručně nahraného XML proti archivovanému snapshotu.
 *
 * Neshoda otisku sama o sobě neříká nic použitelného. Teprve rozdíl po položkách
 * rozhodne, jestli se v EPO změnila hodnota (a archiv tedy neodpovídá podanému),
 * nebo jestli účetní přiložila soubor od jiného podání.
 */
final class EpoSubmissionXmlComparatorTest extends TestCase
{
    public function testFindsChangedValue(): void
    {
        $expected = '<?xml version="1.0"?><Pisemnost><DPHDP3><Veta1 obrat="1000" dan="210"/></DPHDP3></Pisemnost>';
        $actual = '<?xml version="1.0"?><Pisemnost><DPHDP3><Veta1 obrat="1500" dan="210"/></DPHDP3></Pisemnost>';

        $diff = (new EpoSubmissionXmlComparator())->compare($expected, $actual);

        self::assertTrue($diff['comparable']);
        self::assertSame('dphdp3', $diff['form_code']);
        self::assertTrue($diff['form_match']);
        self::assertSame(1, $diff['difference_count']);
        self::assertSame(
            ['path' => 'Pisemnost/DPHDP3[1]/Veta1[1]@obrat', 'expected' => '1000', 'actual' => '1500'],
            $diff['differences'][0],
        );
    }

    /** Formátování ani pořadí atributů rozdíl není — porovnává se struktura. */
    public function testIgnoresWhitespaceAndAttributeOrder(): void
    {
        $expected = '<?xml version="1.0"?><Pisemnost><DPHDP3><Veta1 obrat="1000" dan="210"/></DPHDP3></Pisemnost>';
        $actual = "<?xml version=\"1.0\"?>\n<Pisemnost>\n  <DPHDP3>\n    <Veta1 dan=\"210\"  obrat=\"1000\"/>\n"
            . "  </DPHDP3>\n</Pisemnost>\n";

        $diff = (new EpoSubmissionXmlComparator())->compare($expected, $actual);

        self::assertSame(0, $diff['difference_count']);
    }

    public function testDetectsDifferentForm(): void
    {
        $expected = '<?xml version="1.0"?><Pisemnost><DPHDP3><Veta1 obrat="1000"/></DPHDP3></Pisemnost>';
        $actual = '<?xml version="1.0"?><Pisemnost><DPHKH1 verzePis="03.01"/></Pisemnost>';

        $diff = (new EpoSubmissionXmlComparator())->compare($expected, $actual);

        self::assertSame('dphkh1', $diff['form_code']);
        self::assertSame('dphdp3', $diff['expected_form_code']);
        self::assertFalse($diff['form_match']);
    }

    /** Opakující se sourozenci se nesmí navzájem přebít, jinak by rozdíl zmizel. */
    public function testKeepsRepeatedSiblingsApart(): void
    {
        $expected = '<?xml version="1.0"?><Pisemnost><DPHKH1><VetaA4 dic="CZ1"/><VetaA4 dic="CZ2"/></DPHKH1></Pisemnost>';
        $actual = '<?xml version="1.0"?><Pisemnost><DPHKH1><VetaA4 dic="CZ1"/><VetaA4 dic="CZ9"/></DPHKH1></Pisemnost>';

        $diff = (new EpoSubmissionXmlComparator())->compare($expected, $actual);

        self::assertSame(1, $diff['difference_count']);
        self::assertSame('Pisemnost/DPHKH1[1]/VetaA4[2]@dic', $diff['differences'][0]['path']);
    }

    public function testNonXmlIsNotComparable(): void
    {
        $diff = (new EpoSubmissionXmlComparator())->compare('<Pisemnost/>', 'tohle není XML');

        self::assertFalse($diff['comparable']);
        self::assertSame(0, $diff['difference_count']);
    }
}
