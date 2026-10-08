<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {TYP} ve formátu klienta (#277) pojmenuje doklad jako PDF, ale výchozí předmět
 * zůstává beze změny — ten platí pro všechny instalace a měnit se nesmí.
 */
final class InvoiceEmailTypeLabelTest extends TestCase
{
    /** @return array<string,array{string,string,string}> */
    public static function labelProvider(): array
    {
        return [
            'faktura'                   => ['invoice', 'cs', 'Faktura'],
            'záloha'                    => ['proforma', 'cs', 'Zálohová faktura'],
            'dobropis'                  => ['credit_note', 'cs', 'Opravný daňový doklad'],
            'daňový doklad k platbě'    => ['tax_document', 'cs', 'Daňový doklad k přijaté platbě'],
            'platební kalendář'         => ['payment_calendar', 'cs', 'Platební kalendář'],
            'faktura en'                => ['invoice', 'en', 'Invoice'],
            'daňový doklad k platbě en' => ['tax_document', 'en', 'Tax document for payment received'],
            'platební kalendář en'      => ['payment_calendar', 'en', 'Payment calendar'],
        ];
    }

    #[DataProvider('labelProvider')]
    public function testTypVeFormatuKlientaPojmenujeDoklad(string $type, string $locale, string $expected): void
    {
        self::assertSame($expected, $this->call('formatTypeLabel', $type, $locale));
    }

    public function testHodnotaTypPouzijeNazevDokladu(): void
    {
        $values = $this->call('formatValues', [
            'invoice_type' => 'tax_document',
            'varsymbol' => '2610001',
            'issue_date' => '2026-10-06',
            'client_company_name' => 'Klient a.s.',
            'supplier_snapshot' => ['company_name' => 'Dodavatel s.r.o.'],
        ], 'cs');

        self::assertSame('Daňový doklad k přijaté platbě', $values['TYP']);
    }

    /** @return array<string,array{string}> */
    public static function unchangedDefaultProvider(): array
    {
        return [
            'daňový doklad k platbě' => ['tax_document'],
            'platební kalendář'      => ['payment_calendar'],
        ];
    }

    #[DataProvider('unchangedDefaultProvider')]
    public function testVychoziPredmetZustavaBezeZmeny(string $type): void
    {
        $invoice = [
            'invoice_type' => $type,
            'varsymbol' => '2610001',
            'supplier_snapshot' => ['company_name' => 'Dodavatel s.r.o.'],
        ];

        self::assertSame('Faktura 2610001 — Dodavatel s.r.o.', $this->call('buildSubject', $invoice, false, 'cs'));
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $builder = (new \ReflectionClass(InvoiceEmailVarsBuilder::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(InvoiceEmailVarsBuilder::class, $method))->invoke($builder, ...$args);
    }
}
