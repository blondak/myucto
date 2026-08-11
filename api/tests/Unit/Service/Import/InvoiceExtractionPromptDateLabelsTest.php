<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Nález z provozu — model vzal DATUM OBJEDNÁVKY jako datum vystavení A ZÁROVEŇ jako
 * datum splatnosti. Doklad měl v hlavičce vedle sebe „Číslo objednávky", „Datum
 * objednávky", „Datum vystavení" a „DUZP", splatnost neuváděl vůbec a číslo dokladu
 * mělo v sobě datum objednávky zakódované (`CZ260723-…` = 23. 07. 2026). Doklad tím
 * spadl do jiného účetního období. Silnější model ho přečetl správně, slabší ne —
 * pravidlo proto musí být v promptu vypsané, ne odvoditelné.
 *
 * Fixtura je SYNTETICKÁ (vymyšlený dodavatel, číslo i data).
 */
final class InvoiceExtractionPromptDateLabelsTest extends TestCase
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
        return [
            'sdílený'   => InvoiceExtractionPrompt::invoiceSystem(),
            'anthropic' => $this->anthropicSource(),
        ];
    }

    /**
     * Doklad, který způsobil nález: tři různá data (objednávka / vystavení / DUZP),
     * žádná splatnost a čtvrté datum zakódované v čísle dokladu.
     *
     * @return array<string,mixed>
     */
    private function fixture(): array
    {
        return [
            'vendor'                => ['company_name' => 'Testovací elektro s.r.o.', 'ic' => '12345678', 'dic' => 'CZ12345678'],
            'customer'              => ['company_name' => 'Testovací odběratel s.r.o.', 'ic' => '87654321'],
            'vendor_invoice_number' => 'XX260201-10000001', // „260201" = 01. 02. 2026, ale NENÍ to datum
            'document_kind'         => 'invoice',
            // Datum objednávky na dokladu = 2026-02-01, vystavení = 2026-02-12, DUZP = 2026-02-12.
            'issue_date'            => '2026-02-12',
            'tax_date'              => '2026-02-12',
            'due_date'              => null, // doklad splatnost neuvádí
            'currency'              => 'CZK',
            'items'                 => [
                ['description' => 'Mobilní telefon', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 20000.00, 'vat_rate' => 21.0],
            ],
            'unit_prices_include_vat' => false,
            'total_without_vat'       => 20000.00,
            'total_with_vat'          => 24200.00,
        ];
    }

    /** Popisky, ze kterých se datum vystavení brát SMÍ. */
    public function testBothPromptsListIssueDateLabels(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            foreach (['Datum vystavení', 'Date of issue', 'Invoice date'] as $needle) {
                self::assertStringContainsString($needle, $prompt, "{$label}: chybí popisek „{$needle}\"");
            }
        }
    }

    /**
     * JÁDRO NÁLEZU: datum objednávky (a další „skoro-vystavení" popisky) se za datum
     * vystavení brát NESMÍ. Bez explicitního zákazu si slabší model vybere to první
     * datum, které v hlavičce potká.
     */
    public function testBothPromptsForbidOrderDateAsIssueDate(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            self::assertStringContainsString('BRÁT NESMÍ', $prompt, $label);
            foreach ([
                'Datum objednávky',
                'Datum přijetí objednávky',
                'Order date',
                'PO date',
                'Datum odeslání',
                'Datum tisku',
            ] as $needle) {
                self::assertStringContainsString($needle, $prompt, "{$label}: chybí zakázaný popisek „{$needle}\"");
            }
        }
    }

    /**
     * Druhá cesta k témuž chybnému datu: číselná sekvence v čísle dokladu / objednávky
     * / VS vypadá jako datum. Model si ho odtud nesmí odvozovat.
     */
    public function testBothPromptsForbidDerivingDatesFromDocumentNumber(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            self::assertStringContainsString('NEODVOZUJ Z ČÍSLA DOKLADU', $prompt, $label);
            self::assertStringContainsString('CZ260723-10226320', $prompt, "{$label}: chybí konkrétní příklad, na kterém se to stalo");
        }
    }

    /** Chybějící splatnost = null, ne opsané datum vystavení. */
    public function testBothPromptsRequireNullDueDateWhenNotStated(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            self::assertStringContainsString('NEOPISUJ', $prompt, $label);
            self::assertStringContainsString('vrať `due_date: null`', $prompt, $label);
        }
    }

    /**
     * Protipól: DUZP PŘED datem vystavení je legitimní (doklad vystavený 2. dne měsíce
     * za plnění k poslednímu dni předchozího). Prompt to musí říct výslovně, jinak si
     * model „logickou kontrolu" splatnosti přenese i na DUZP a začne ho posouvat.
     */
    public function testBothPromptsAllowTaxDateBeforeIssueDate(): void
    {
        foreach ($this->prompts() as $label => $prompt) {
            self::assertStringContainsString('PŘED `issue_date` je zcela legitimní', $prompt, $label);
            self::assertStringContainsString('NEOPRAVUJ', $prompt, $label);
        }
    }

    /**
     * A totéž na úrovni kódu: sanity guard na prohozené datumy sahá VÝHRADNĚ na
     * vystavení↔splatnost. DUZP před vystavením přes něj musí projít beze změny.
     */
    public function testSwapGuardNeverTouchesTaxDateBeforeIssueDate(): void
    {
        $data = [
            'issue_date' => '2026-08-02',
            'tax_date'   => '2026-07-31', // DUZP dřív než vystavení — legitimní
            'due_date'   => '2026-08-16',
        ];

        self::assertNull(
            AiPdfExtractor::fixSwappedIssueDueDates($data),
            'DUZP před vystavením není prohození — guard do něj nesmí sahat.',
        );
    }

    /** Doklad bez splatnosti nesmí guard „opravit" na nesmyslnou dvojici. */
    public function testSwapGuardIgnoresMissingDueDate(): void
    {
        self::assertNull(AiPdfExtractor::fixSwappedIssueDueDates($this->fixture()));
    }

    /**
     * Fixtura reprezentuje správné vytěžení: vystavení je datum vystavení (ne datum
     * objednávky ani datum z čísla dokladu) a splatnost zůstala null.
     */
    public function testFixtureUsesIssueDateNotOrderDateNorNumberEncodedDate(): void
    {
        $data = $this->fixture();

        self::assertSame('2026-02-12', $data['issue_date']);
        self::assertNotSame('2026-02-01', $data['issue_date'], 'Datum objednávky NENÍ datum vystavení.');
        self::assertNull($data['due_date'], 'Nezmíněná splatnost se nesmí opsat z vystavení.');
        self::assertStringContainsString('260201', (string) $data['vendor_invoice_number']);
        self::assertStringNotContainsString(
            str_replace('-', '', substr((string) $data['issue_date'], 2)),
            (string) $data['vendor_invoice_number'],
            'Kontrola fixtury: datum v čísle dokladu se musí lišit od data vystavení.',
        );
    }
}
