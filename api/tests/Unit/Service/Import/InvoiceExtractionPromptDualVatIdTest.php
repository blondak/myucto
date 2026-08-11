<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Issue #8 — kontrakt extrakčního promptu pro souhrnný doklad palivových karet.
 *
 * Doklad má v hlavičce DVĚ DIČ („DIČ: CZ<IČO>" a „DIČ k DPH: CZ699xxxxxx" =
 * skupinová registrace odštěpného závodu), nemá sloupec jednotkové ceny a uvádí
 * částky před slevou i po slevě. Model si vybíral DIČ náhodně a jednotkovou cenu
 * dopočítával z předslevových čísel.
 *
 * Prompt je textový, takže regresi hlídá tenhle kontrakt. Testuje se PROTI TEXTU,
 * který se reálně posílá modelu — u sdíleného promptu přes `invoiceSystem()`, u
 * Anthropicu (vlastní inline prompt) přes zdrojový soubor, stejně jako
 * {@see InvoiceExtractionPromptExpenseKindTest}. Řetězce jsou volené tak, aby
 * existovaly JEN v promptu, ne v PHP komentáři.
 */
final class InvoiceExtractionPromptDualVatIdTest extends TestCase
{
    private function anthropicSource(): string
    {
        $file = (new ReflectionClass(AnthropicClient::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);
        return $source;
    }

    // ── Dvě DIČ na jednom dokladu ────────────────────────────────────────────

    public function testSchemaDeclaresVatDicNextToDic(): void
    {
        $schema = InvoiceExtractionPrompt::invoiceJsonSchema()['properties']['vendor']['properties'];

        self::assertArrayHasKey('dic', $schema, '`dic` musí zůstat — stojí na něm párování karty dodavatele.');
        self::assertArrayHasKey('vat_dic', $schema);
        self::assertSame(['string', 'null'], $schema['vat_dic']['type']);
    }

    /** Pole musí být deklarované i v textu promptu, jinak ho model nevyplní. */
    public function testBothPromptsDeclareVatDicField(): void
    {
        self::assertStringContainsString('"vat_dic": string|null', InvoiceExtractionPrompt::invoiceSystem());
        self::assertStringContainsString('"vat_dic": string|null', $this->anthropicSource());
    }

    /** Popisky, podle kterých se skupinové DIČ pozná — bez nich model tipuje. */
    public function testBothPromptsExplainHowToRecogniseGroupVatId(): void
    {
        foreach (['sdílený' => InvoiceExtractionPrompt::invoiceSystem(), 'anthropic' => $this->anthropicSource()] as $label => $prompt) {
            foreach (['DIČ k DPH', 'DIČ pro DPH', 'DIČ skupiny', 'CZ699xxxxxx'] as $needle) {
                self::assertStringContainsString($needle, $prompt, "{$label}: chybí popisek „{$needle}\"");
            }
        }
    }

    /**
     * NEJDŮLEŽITĚJŠÍ PRAVIDLO CELÉ ZMĚNY: skupinové DIČ se nesmí dostat do `dic`.
     * Na `dic` stojí dohledání karty dodavatele v ClientResolveru — kdyby se tam
     * začalo vracet DIČ skupiny, přestala by se párovat existující karta a založila
     * by se duplicitní.
     */
    public function testBothPromptsForbidGroupVatIdInDicField(): void
    {
        self::assertStringContainsString(
            'skupinové DIČ do `dic` NIKDY nepatří',
            InvoiceExtractionPrompt::invoiceSystem(),
        );
        self::assertStringContainsString(
            'skupinové DIČ do `dic` NIKDY nepatří',
            $this->anthropicSource(),
        );
    }

    // ── Doklad bez jednotkových cen ──────────────────────────────────────────

    public function testSchemaDeclaresUnitPricesStated(): void
    {
        $schema = InvoiceExtractionPrompt::invoiceJsonSchema()['properties'];

        self::assertArrayHasKey('unit_prices_stated', $schema);
        self::assertSame(['boolean', 'null'], $schema['unit_prices_stated']['type']);
        // Pole nesmí nahradit `unit_prices_include_vat` — jsou to dvě různé otázky
        // („uvádí doklad jednotkovou cenu?" vs „je v ní DPH?").
        self::assertArrayHasKey('unit_prices_include_vat', $schema);
    }

    public function testBothPromptsForbidInventingUnitPrices(): void
    {
        foreach (['sdílený' => InvoiceExtractionPrompt::invoiceSystem(), 'anthropic' => $this->anthropicSource()] as $label => $prompt) {
            self::assertStringContainsString('"unit_prices_stated": boolean|null', $prompt, $label);
            self::assertStringContainsString('NEDOPOČÍTÁVEJ a NEVYMÝŠLEJ', $prompt, $label);
        }
    }

    // ── Slevy: řádek vs. souhrnný blok, částky po slevě ──────────────────────

    /**
     * Dvě pravidla, která si NESMÍ odporovat: slevový ŘÁDEK mezi položkami je
     * položka (a musí mít mínus), kdežto souhrnný BLOK slevy pod položkami položka
     * NENÍ — slevu už nesou částky u položek, takže by se odečetla podruhé.
     */
    public function testBothPromptsDistinguishDiscountLineFromDiscountBlock(): void
    {
        foreach (['sdílený' => InvoiceExtractionPrompt::invoiceSystem(), 'anthropic' => $this->anthropicSource()] as $label => $prompt) {
            self::assertStringContainsString('SOUHRNNÝ BLOK SLEVY NENÍ POLOŽKA', $prompt, $label);
            self::assertStringContainsString('odečetla by se sleva podruhé', $prompt, $label);
        }
        // Slevový ŘÁDEK musí dál chodit se záporným znaménkem (pravidlo, které tu bylo
        // před touhle změnou a nesmí ho nové pravidlo o bloku zrušit).
        self::assertStringContainsString('ZÁPORNÝM', InvoiceExtractionPrompt::invoiceSystem());
        self::assertStringContainsString('**ZÁPORNÉ** číslo', $this->anthropicSource());
    }

    public function testBothPromptsPinPostDiscountAmounts(): void
    {
        foreach (['sdílený' => InvoiceExtractionPrompt::invoiceSystem(), 'anthropic' => $this->anthropicSource()] as $label => $prompt) {
            self::assertStringContainsString('závazné jsou VŽDY částky', $prompt, $label);
            self::assertStringContainsString('Základ daně po slevě', $prompt, $label);
            self::assertStringContainsString('Předslevové částky do výstupu NEPATŘÍ', $prompt, $label);
        }
    }

    /** Rekapitulace je autorita — model ji nesmí „opravovat", aby seděla na položky. */
    public function testBothPromptsMakeVatRecapAuthoritative(): void
    {
        foreach (['sdílený' => InvoiceExtractionPrompt::invoiceSystem(), 'anthropic' => $this->anthropicSource()] as $label => $prompt) {
            self::assertStringContainsString('DAŇOVÁ REKAPITULACE JE AUTORITA', $prompt, $label);
            self::assertStringContainsString('rekapitulace (po slevě)', $prompt, $label);
        }
    }
}
