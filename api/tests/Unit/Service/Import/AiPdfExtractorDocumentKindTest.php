<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Regrese: běžná přijatá faktura s nadpisem „Daňový doklad" se importovala jako DDKP
 * (`document_kind='tax_document'`, § 28 ZDPH) — účtovala by se 343/314 a vypadla by
 * z nákladů, ze závazků i z příkazu k úhradě.
 *
 * `AiPdfExtractor::resolveDocumentKind()` proto AI klasifikaci `tax_document` uzná jen
 * tehdy, když má doklad STOPU PO ZÁLOZE: `advance_reference`, nebo text položek /
 * povahy plnění mluvící o přijaté či provedené platbě nebo o záloze.
 * Ostatní druhy (invoice/credit_note/advance/receipt) nechává být.
 */
final class AiPdfExtractorDocumentKindTest extends TestCase
{
    /** @param array<string,mixed> $data */
    private function resolve(array $data): string
    {
        $ref = new \ReflectionMethod(AiPdfExtractor::class, 'resolveDocumentKind');
        return (string) $ref->invoke(null, $data);
    }

    public function testPlainTaxDocumentHeaderWithoutAnyAdvanceTraceBecomesInvoice(): void
    {
        // Přesně tvar, který vrátí model nad běžnou fakturou operátora nadepsanou
        // „Daňový doklad": žádný odkaz na zálohu, položky jsou dodané služby.
        $kind = $this->resolve([
            'document_kind'     => 'tax_document',
            'advance_reference' => null,
            'supply_nature'     => 'services',
            'items'             => [
                ['description' => 'Základní tarif — období 06/2026'],
                ['description' => 'Datové služby'],
            ],
        ]);

        self::assertSame('invoice', $kind);
    }

    public function testTaxDocumentWithAdvanceReferenceStays(): void
    {
        $kind = $this->resolve([
            'document_kind'     => 'tax_document',
            'advance_reference' => '2026001234',
            'items'             => [['description' => 'DPH z uhrazené částky']],
        ]);

        self::assertSame('tax_document', $kind);
    }

    public function testTaxDocumentWithAdvanceWordingInItemsStays(): void
    {
        $kind = $this->resolve([
            'document_kind'     => 'tax_document',
            'advance_reference' => '',
            'items'             => [['description' => 'Daňový doklad k přijaté platbě — záloha na dodávku']],
        ]);

        self::assertSame('tax_document', $kind);
    }

    public function testOtherKindsAreUntouched(): void
    {
        foreach (['invoice', 'credit_note', 'advance', 'receipt'] as $kind) {
            self::assertSame($kind, $this->resolve([
                'document_kind' => $kind,
                'items'         => [['description' => 'Cokoliv']],
            ]));
        }
        // Neznámý druh z modelu padá na 'invoice' jako dřív.
        self::assertSame('invoice', $this->resolve(['document_kind' => 'nesmysl']));
    }
}
