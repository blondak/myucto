<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Shoptet;

use MyInvoice\Service\Http\OutboundUrlGuard;
use MyInvoice\Service\Shoptet\ShoptetImportException;
use MyInvoice\Service\Shoptet\ShoptetOrderFetcher;
use MyInvoice\Service\Shoptet\ShoptetOrderUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Odkaz na export objednávek je tajemství a míří ven: jen https, přes SSRF guard,
 * s pravidlem Shoptetu o 15 minutách a bez prozrazení hashe v hláškách.
 */
final class ShoptetOrderUrlTest extends TestCase
{
    private const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function testNormalizeKeepsExportParametersAndDropsUpdateTimeFrom(): void
    {
        $url = ShoptetOrderUrl::normalize(
            'https://Obchod.Example.TEST/export/orders.xml?patternId=-11&hash=' . self::HASH . '&updateTimeFrom=2020-01-01'
        );

        self::assertStringStartsWith('https://obchod.example.test/export/orders.xml?', $url);
        self::assertStringContainsString('patternId=-11', $url);
        self::assertStringContainsString('hash=' . self::HASH, $url);
        self::assertStringNotContainsString('updateTimeFrom', $url);
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejected(string $url): void
    {
        $this->expectException(ShoptetImportException::class);
        ShoptetOrderUrl::normalize($url);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidUrls(): iterable
    {
        yield 'http' => ['http://obchod.example.test/export/orders.xml?hash=abc'];
        yield 'bez hash' => ['https://obchod.example.test/export/orders.xml?patternId=-11'];
        yield 'credentials' => ['https://user:pass@obchod.example.test/export/orders.xml?hash=abc'];
        yield 'file' => ['file:///etc/passwd?hash=abc'];
        yield 'prázdný' => [''];
        yield 'jiný port' => ['https://obchod.example.test:8443/export/orders.xml?hash=abc'];
        yield 'port 80' => ['https://obchod.example.test:80/export/orders.xml?hash=abc'];
    }

    public function testStandardHttpsPortIsAcceptedAndDropped(): void
    {
        $url = ShoptetOrderUrl::normalize('https://obchod.example.test:443/export/orders.xml?hash=abc');

        self::assertStringStartsWith('https://obchod.example.test/export/orders.xml?', $url);
    }

    public function testMaskShowsOnlyHostPathAndHashTail(): void
    {
        $mask = ShoptetOrderUrl::mask('https://obchod.example.test/export/orders.xml?patternId=-11&hash=' . self::HASH);

        self::assertSame('https://obchod.example.test/export/orders.xml?…hash=…8f90', $mask);
        self::assertStringNotContainsString(substr(self::HASH, 0, 20), $mask);
    }

    public function testUpdateTimeFromUsesShoptetFormatInPragueTime(): void
    {
        $from = new \DateTimeImmutable('2026-09-01 08:30:00', new \DateTimeZone('UTC'));
        $url = ShoptetOrderUrl::withUpdateTimeFrom('https://obchod.example.test/export/orders.xml?hash=abc', $from);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('2026-09-01 10:30:00', $query['updateTimeFrom']);
        self::assertStringContainsString('updateTimeFrom=2026-09-01%2010%3A30%3A00', $url);
    }

    #[DataProvider('blockedTargets')]
    public function testFetcherRefusesNonPublicTargetsWithoutLeakingTheHash(string $host): void
    {
        $fetcher = new ShoptetOrderFetcher(new OutboundUrlGuard());
        try {
            $fetcher->fetch('https://' . $host . '/export/orders.xml?hash=' . self::HASH, [], new \DateTimeImmutable());
            self::fail('Neveřejný cíl musí guard odmítnout.');
        } catch (ShoptetImportException $e) {
            self::assertSame('shoptet_fetch_failed', $e->errorCode);
            self::assertStringNotContainsString(self::HASH, $e->getMessage());
            self::assertStringNotContainsString($host, $e->getMessage());
        }
    }

    /** @return iterable<string,array{string}> */
    public static function blockedTargets(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'privátní' => ['10.0.0.8'];
        yield 'metadata' => ['169.254.169.254'];
        yield 'ipv6 loopback' => ['[::1]'];
    }

    public function testFullExportIsNotRepeatedWithinFifteenMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-09-01 12:00:00');
        $fetcher = new ShoptetOrderFetcher(new OutboundUrlGuard());

        try {
            $fetcher->fetch('https://127.0.0.1/export/orders.xml?hash=abc', [
                'fetch_cursor' => null,
                'last_full_fetch_at' => '2026-09-01 11:50:00',
            ], $now);
            self::fail('Úplný export do 15 minut musí být odmítnut dřív, než se sáhne na síť.');
        } catch (ShoptetImportException $e) {
            self::assertSame('shoptet_fetch_too_soon', $e->errorCode);
        }
    }

    public function testCursorOverlapsTheLastFetch(): void
    {
        $next = ShoptetOrderFetcher::nextCursor(new \DateTimeImmutable('2026-09-01 12:00:00'));

        self::assertSame('2026-09-01 11:58:00', $next->format('Y-m-d H:i:s'));
    }
}
