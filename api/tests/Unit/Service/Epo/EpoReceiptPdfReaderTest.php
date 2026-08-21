<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use Mpdf\Mpdf;
use MyInvoice\Service\Epo\EpoReceiptPdfReader;
use PHPUnit\Framework\TestCase;

/**
 * Čtení PDF opisu z Daňového portálu.
 *
 * Část účetních si z asistovaného podání odnese jen tisk — dodejku P7S buď nestáhne,
 * nebo o ní neví. Bez tohohle čtení aplikace o podání neví nic, přestože podací číslo
 * je v archivovaném souboru čitelné.
 */
final class EpoReceiptPdfReaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testReadsReferenceAndTimeFromPrintout(): void
    {
        $path = $this->pdf(
            '<p>Potvrzení podání</p>'
            . '<p>Podací číslo: 568467011</p>'
            . '<p>Datum a čas podání: 21.08.2026 10:36:43</p>'
            . '<p>Kontrolní součet: ' . str_repeat('ab', 16) . '</p>',
        );

        $result = (new EpoReceiptPdfReader())->read($path);

        self::assertTrue($result['text_available']);
        self::assertSame('568467011', $result['reference']);
        self::assertSame('2026-08-21 10:36:43', $result['submitted_at']);
        self::assertSame(str_repeat('ab', 16), $result['checksum']);
    }

    /** Popisky se liší formulář od formuláře, diakritika v textové vrstvě taky. */
    public function testReadsAlternativeLabelWithoutDiacritics(): void
    {
        $path = $this->pdf('<p>Cislo podani 123456789 podano dne 1. 3. 2026</p>');

        $result = (new EpoReceiptPdfReader())->read($path);

        self::assertSame('123456789', $result['reference']);
        self::assertSame('2026-03-01 00:00:00', $result['submitted_at']);
    }

    /** Skenovaný nebo cizí soubor není chyba nahrání — jen se z něj nic nepřečte. */
    public function testFileWithoutTextLayerIsSilentlyIgnored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'epo-pdf-');
        self::assertNotFalse($path);
        file_put_contents($path, 'tohle rozhodně není PDF');
        $this->tempFiles[] = $path;

        $result = (new EpoReceiptPdfReader())->read($path);

        self::assertFalse($result['text_available']);
        self::assertNull($result['reference']);
        self::assertNull($result['submitted_at']);
    }

    private function pdf(string $html): string
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans']);
        $pdf->WriteHTML('<div style="font-family:dejavusans">' . $html . '</div>');
        $bytes = (string) $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

        $path = tempnam(sys_get_temp_dir(), 'epo-pdf-');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;
        return $path;
    }
}
