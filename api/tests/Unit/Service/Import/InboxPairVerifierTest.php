<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use Mpdf\Mpdf;
use MyInvoice\Service\Import\InboxPairVerifier;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfTotalExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Měkká kontrola dvojice ISDOC + sourozenecké PDF (issue #16).
 *
 * Klíčová vlastnost, kterou testy hlídají: verifier NIKDY nic neblokuje a mlčí
 * všude, kde by mohl mít falešně pozitivní názor (chybějící textová vrstva,
 * cizí měna). Varuje jen tehdy, když VS v PDF chybí A ZÁROVEŇ částka nesedí.
 */
final class InboxPairVerifierTest extends TestCase
{
    private InboxPairVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new InboxPairVerifier(new PdfTotalExtractor(new PdfIsdocExtractor()));
    }

    public function testSilentWhenVariableSymbolIsFoundInPdf(): void
    {
        // Částka schválně nesedí — nalezený VS je silnější signál a kontrolu ukončí.
        $pdf = $this->pdf('Variabilní symbol: 2026001<br>Celkem k úhradě 9 999,00 Kč');

        $this->assertNull($this->verifier->verify($pdf, $this->isdoc('2026001', 1502.00)));
    }

    public function testSilentWhenAmountMatchesEvenWithoutVariableSymbol(): void
    {
        $pdf = $this->pdf('Celkem k úhradě 1 502,00 Kč');

        $this->assertNull($this->verifier->verify($pdf, $this->isdoc('2026001', 1502.00)));
    }

    public function testWarnsWhenNeitherVariableSymbolNorAmountMatch(): void
    {
        $pdf = $this->pdf('Celkem k úhradě 9 999,00 Kč');

        $warning = $this->verifier->verify($pdf, $this->isdoc('2026001', 1502.00));

        $this->assertNotNull($warning);
        $this->assertStringContainsString('2026001', $warning);
        $this->assertStringContainsString('9', $warning);
    }

    /** Haléřové rozjezdy MAX heuristiky nesmí uživatele otravovat. */
    public function testToleratesSmallDifference(): void
    {
        $pdf = $this->pdf('Celkem k úhradě 1 502,03 Kč');

        $this->assertNull($this->verifier->verify($pdf, $this->isdoc('2026001', 1502.00)));
    }

    /** Skenovaný doklad nemá textovou vrstvu — nelze ověřit nic, tak se mlčí. */
    public function testSilentWhenPdfHasNoTextLayer(): void
    {
        $this->assertNull($this->verifier->verify('%PDF-1.4 tohle není čitelné PDF', $this->isdoc('2026001', 1502.00)));
    }

    public function testSilentWhenPdfBytesAreGarbage(): void
    {
        $this->assertNull($this->verifier->verify('rozhodně ne PDF', $this->isdoc('2026001', 1502.00)));
    }

    /**
     * Cizoměnový doklad tiskne obvykle obě částky (EUR i CZK). MAX z textu by proti
     * částce v měně faktury hlásil rozpor pokaždé, takže se porovnání vypíná.
     */
    public function testSilentForForeignCurrencyInvoice(): void
    {
        $pdf = $this->pdf('Celkem k úhradě 24 500,00 Kč');

        $invoice = $this->isdoc('2026001', 1000.00);
        $invoice['currency'] = 'EUR';

        $this->assertNull($this->verifier->verify($pdf, $invoice));
    }

    public function testSilentWhenIsdocHasNoUsablePayableAmount(): void
    {
        $pdf = $this->pdf('Celkem k úhradě 9 999,00 Kč');

        $invoice = $this->isdoc('2026001', 0.0);
        $this->assertNull($this->verifier->verify($pdf, $invoice));

        $invoice['payable_amount'] = null;
        $this->assertNull($this->verifier->verify($pdf, $invoice));
    }

    /** VS nesmí „projít" jen proto, že je podřetězcem delšího čísla na dokladu. */
    public function testVariableSymbolMustNotMatchInsideLongerNumber(): void
    {
        $pdf = $this->pdf('Číslo účtu 1000000005/0100<br>Celkem k úhradě 9 999,00 Kč');

        $this->assertNotNull($this->verifier->verify($pdf, $this->isdoc('100000000', 1502.00)));
    }

    /** @return array<string,mixed> */
    private function isdoc(string $varsymbol, ?float $payable): array
    {
        return [
            'varsymbol'      => $varsymbol,
            'currency'       => 'CZK',
            'payable_amount' => $payable,
        ];
    }

    private function pdf(string $html): string
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans']);
        $pdf->WriteHTML('<p style="font-family:dejavusans">' . $html . '</p>');
        return (string) $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
