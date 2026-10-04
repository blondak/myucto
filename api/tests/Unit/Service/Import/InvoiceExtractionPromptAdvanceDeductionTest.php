<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Kontrakt extrakčního promptu pro konečnou fakturu po nezdaněné záloze.
 *
 * Doklad má mezi položkami řádek „Odpočet zálohy <číslo>" bez sazby a DPH; rekapitulace
 * obsahuje plný základ. Model ho vracel jako položku (kladně i záporně), součet položek
 * se pak rozešel s rekapitulací a import nahradil všechny řádky jediným součtovým
 * (AiPdfExtractor::authoritativeRecapBaseLine) — itemizace se ztratila.
 *
 * Řádek odpočtu ZDANĚNÉ zálohy (se sazbou a DPH) ale do položek patří jako záporný,
 * jinak by součet položek přesáhl rekapitulaci, která obsahuje jen rozdíl. Obě větve
 * musí být v promptu výslovně.
 *
 * Testuje se proti textu, který se posílá modelu: sdílený prompt přes `invoiceSystem()`,
 * Anthropic (vlastní inline prompt) přes zdrojový soubor. Řetězce existují jen v promptu.
 */
final class InvoiceExtractionPromptAdvanceDeductionTest extends TestCase
{
    /** @return array<string, string> */
    private function prompts(): array
    {
        $file = (new ReflectionClass(AnthropicClient::class))->getFileName();
        self::assertIsString($file);
        $anthropic = file_get_contents($file);
        self::assertIsString($anthropic);
        return ['sdílený' => InvoiceExtractionPrompt::invoiceSystem(), 'anthropic' => $anthropic];
    }

    public function testUntaxedAdvanceDeductionIsExcludedFromItems(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            self::assertStringContainsString('Řádek ODPOČTU / ÚHRADY ZÁLOHY', $prompt, "{$label}: chybí pravidlo pro řádek odpočtu zálohy");
            self::assertStringContainsString('který NEMÁ vlastní sazbu ani DPH, NENÍ položka', $prompt, "{$label}: nezdaněná záloha musí ven z items");
        }
    }

    public function testTaxedAdvanceDeductionStaysAsNegativeItem(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            self::assertStringContainsString('vrať ho jako položku se ZÁPORNOU cenou a jeho sazbou', $prompt, "{$label}: zdaněná záloha musí zůstat jako záporná položka");
        }
    }

    /** „Odpočet" je na dokladech stejně častý jako „Odečet" — oba musí vést do advance_reference. */
    public function testBothSpellingsLeadToAdvanceReference(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            $section = (string) strstr($prompt, 'DŮLEŽITÉ k poli `advance_reference`:');
            self::assertNotSame('', $section, "{$label}: chybí sekce advance_reference");
            $section = substr($section, 0, 600);
            self::assertStringContainsString('"Odečet zálohy"', $section, $label);
            self::assertStringContainsString('"Odpočet zálohy"', $section, $label);
        }
    }
}
