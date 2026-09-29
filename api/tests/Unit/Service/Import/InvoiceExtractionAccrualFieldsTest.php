<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AiIssuedInvoiceExtractor;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Období plnění položky (`accrual_from` / `accrual_to`) je součástí extrakčního kontraktu
 * všech providerů: sdílený prompt, strict JSON schéma i inline prompt Anthropicu.
 */
final class InvoiceExtractionAccrualFieldsTest extends TestCase
{
    private const FIELDS = ['accrual_from', 'accrual_to'];

    public function testJsonSchemaDeclaresNullableItemFields(): void
    {
        $item = InvoiceExtractionPrompt::invoiceJsonSchema()['properties']['items']['items'];
        foreach (self::FIELDS as $field) {
            self::assertSame(['string', 'null'], $item['properties'][$field]['type']);
            self::assertContains($field, $item['required'], 'strict schéma vyžaduje výčet všech vlastností');
        }
    }

    public function testSharedAndAnthropicPromptsDescribeFields(): void
    {
        $source = file_get_contents((string) (new ReflectionClass(AnthropicClient::class))->getFileName());
        self::assertIsString($source);

        foreach (['sdílený' => InvoiceExtractionPrompt::invoiceSystem(), 'anthropic' => $source] as $label => $prompt) {
            foreach (self::FIELDS as $field) {
                self::assertStringContainsString('"' . $field . '": "YYYY-MM-DD"|null', $prompt, "{$label}: schéma položky uvádí {$field}");
            }
        }
        self::assertStringContainsString(InvoiceExtractionPrompt::accrualFieldRules(), InvoiceExtractionPrompt::invoiceSystem());
        self::assertStringContainsString('InvoiceExtractionPrompt::accrualFieldRules()', $source);
        self::assertStringContainsString('NEODHADUJ', InvoiceExtractionPrompt::accrualFieldRules());
    }

    public function testIssuedMapItemsTakesModelPeriodElseDetectsFromDescription(): void
    {
        $extractor = (new ReflectionClass(AiIssuedInvoiceExtractor::class))->newInstanceWithoutConstructor();
        $items = (new \ReflectionMethod($extractor, 'mapItems'))->invoke($extractor, [
            ['description' => 'Podpora', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate' => 21,
             'accrual_from' => '2026-10-01', 'accrual_to' => '2027-09-30'],
            ['description' => 'Předplatné na rok 2027', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate' => 21,
             'accrual_from' => null, 'accrual_to' => null],
            ['description' => 'Nájem za září 2026', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate' => 21,
             'accrual_from' => '2026-09-30', 'accrual_to' => '2026-09-01'],
            ['description' => 'Vývoj, DUZP 28. 9. 2026', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate' => 21],
        ]);

        self::assertSame(
            [
                ['2026-10-01', '2027-09-30'],
                ['2027-01-01', '2027-12-31'],
                ['2026-09-01', '2026-09-30'],
                [null, null],
            ],
            array_map(static fn (array $i): array => [$i['accrual_from'], $i['accrual_to']], $items),
        );
    }
}
