<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Web faktura je vlastnost instalace: zapnutá, dokud ji provozovatel výslovně
 * nevypne. Server dostupný jen z LAN/VPN by jinak do e-mailu s fakturou vkládal
 * odkaz, který klient neotevře.
 */
final class InvoicePublicLinkFeatureTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718';

    public function testChybejiciVolbaZnamenaZapnuto(): void
    {
        self::assertTrue((new InvoicePublicLinkFeature(new Config([])))->isEnabled());
    }

    /** @return array<string,array{mixed,bool}> */
    public static function valueProvider(): array
    {
        return [
            'bool false'     => [false, false],
            'bool true'      => [true, true],
            'řetězec 0'      => ['0', false],
            'řetězec false'  => ['false', false],
            'řetězec off'    => ['off', false],
            'řetězec 1'      => ['1', true],
            'nesrozumitelné' => ['možná', true],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testHodnotaZCfg(mixed $value, bool $expected): void
    {
        $feature = new InvoicePublicLinkFeature(new Config(['invoices' => ['public_links' => $value]]));

        self::assertSame($expected, $feature->isEnabled());
    }

    public function testVypnutaWebFakturaNedaOdkazDoEmailuANezakladaToken(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->expects(self::never())->method('ensurePublicToken');
        $service = new InvoicePublicLinkService(new Config([
            'app' => ['url' => 'https://app.example.test'],
            'invoices' => ['public_links' => false],
        ]), $invoices);

        self::assertNull($service->ensureUrl(['id' => 5, 'status' => 'sent', 'public_token' => self::TOKEN]));
        self::assertNull($service->ensureUrl(['id' => 6, 'status' => 'issued', 'public_token' => null]));
    }

    public function testZapnutaWebFakturaDaOdkazAChybejiciTokenZalozi(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->expects(self::once())->method('ensurePublicToken')->with(6)->willReturn(self::TOKEN);
        $service = new InvoicePublicLinkService(new Config([
            'app' => ['url' => 'https://app.example.test/'],
        ]), $invoices);

        self::assertSame(
            'https://app.example.test/invoice/' . self::TOKEN,
            $service->ensureUrl(['id' => 6, 'status' => 'issued', 'public_token' => null]),
        );
        self::assertNull($service->ensureUrl(['id' => 7, 'status' => 'draft', 'public_token' => null]));
    }
}
