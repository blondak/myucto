<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiPayloadSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Dotaz účetní u přijaté faktury („Zeptat se AI na kontaci").
 *
 * Klasifikace faktur běžela jen na pozadí podle toho, co je na dokladu. Jenže o nákladovém
 * účtu často rozhoduje souvislost, kterou z faktury není vidět — táž faktura od téhož
 * dodavatele může být nájem serverovny i nájem kanceláře. Bez dotazu model tuhle informaci
 * nemá odkud vzít.
 */
final class PurchaseUserContextTest extends TestCase
{
    private function sanitizer(): AiPayloadSanitizer
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(str_repeat('a', 32));

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return new AiPayloadSanitizer($db);
    }

    /** @return array<string,mixed> */
    private function invoice(): array
    {
        return [
            'vendor_name' => 'Datacentrum s.r.o.',
            'description' => 'Fakturujeme sluzby',
            'total_with_vat' => 12100.0,
            'currency' => 'CZK',
            'tax_date' => '2026-05-31',
        ];
    }

    /** Dotaz se dostane do dat i do textu, který jde do modelu. */
    public function testUserContextReachesModelInput(): void
    {
        $out = $this->sanitizer()->sanitizePurchaseInvoice(1, $this->invoice(), 'nájem serverovny, ne kancelář');

        self::assertTrue($out['ok']);
        self::assertNotSame('', (string) $out['data']['user_context']);
        self::assertStringContainsString('context=', $out['text']);
    }

    /**
     * Bez dotazu zůstává vstup přesně takový, jaký byl — tenhle test hlídá, že se
     * přidáním volitelného parametru nezměnila klasifikace běžící na pozadí.
     */
    public function testWithoutQueryTheInputIsUnchanged(): void
    {
        $out = $this->sanitizer()->sanitizePurchaseInvoice(1, $this->invoice());

        self::assertTrue($out['ok']);
        self::assertSame('', (string) $out['data']['user_context']);
        self::assertStringNotContainsString('context=', $out['text']);
    }

    /**
     * Dotaz MĚNÍ vstupní text, a tím i jeho hash.
     *
     * Na tomhle stojí kontrola „nezměnil se doklad, než odpověděl model": hash se počítá
     * ze vstupu dvakrát, před voláním a po něm. Kdyby dotaz šel jen do prvního výpočtu,
     * hashe by se rozešly vždy a každý dotaz by skončil chybou „stale_document" —
     * tlačítko by nikdy nic nevrátilo.
     */
    public function testQueryChangesInputHashSoBothSidesMustIncludeIt(): void
    {
        $s = $this->sanitizer();
        $withQuery = $s->sanitizePurchaseInvoice(1, $this->invoice(), 'nájem serverovny');
        $without = $s->sanitizePurchaseInvoice(1, $this->invoice());

        self::assertNotSame(
            hash('sha256', (string) $withQuery['text']),
            hash('sha256', (string) $without['text']),
        );
    }
}
