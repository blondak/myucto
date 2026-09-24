<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Číslo dokladu a variabilní symbol jsou dvě pole. Daňový doklad k proformě má u nadpisu
 * vlastní číslo, pod ním odkaz na proformu a VS proformy — model nesmí vzít za číslo
 * dokladu odkaz ani VS a nesmí VS doplnit z čísla.
 *
 * Testuje se proti textu, který se reálně posílá modelu: sdílený prompt přes
 * `invoiceSystem()`, Anthropic (vlastní inline prompty) přes zdrojový soubor.
 */
final class InvoiceExtractionPromptDocumentNumberTest extends TestCase
{
    private function anthropicSource(): string
    {
        $file = (new ReflectionClass(AnthropicClient::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);
        return $source;
    }

    /** @return array<string,string> */
    private function prompts(): array
    {
        return ['sdílený' => InvoiceExtractionPrompt::invoiceSystem(), 'anthropic' => $this->anthropicSource()];
    }

    public function testBothPromptsTellOwnNumberFromReferencedProforma(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            self::assertStringContainsString('Daňový doklad k proformě 1-20940077', $prompt, "{$label}: chybí příklad odkazu na proformu");
            self::assertStringContainsString('NEPŘEBÍREJ číslo z variabilního symbolu', $prompt, "{$label}: chybí zákaz čísla z VS");
        }
    }

    public function testNoPromptClaimsVariableSymbolUsuallyEqualsInvoiceNumber(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            self::assertStringNotContainsString('typicky shodný s číslem faktury', $prompt, $label);
            self::assertStringNotContainsString('(typicky číslo faktury)', $prompt, $label);
            self::assertStringContainsString('opsaný z pole „Variabilní symbol"', $prompt, $label);
        }
    }
}
