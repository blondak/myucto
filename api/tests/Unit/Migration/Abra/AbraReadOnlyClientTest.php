<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Migration\Abra;

use MyInvoice\Service\Http\OutboundUrlGuard;
use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Migration\Abra\AbraException;
use MyInvoice\Service\Migration\Abra\AbraReadOnlyClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AbraReadOnlyClientTest extends TestCase
{
    public function testNormalizesCompanyUrl(): void
    {
        self::assertSame('https://example.com:5434/c/demo', AbraReadOnlyClient::normalizeUrl(' https://EXAMPLE.com:5434/flexi/demo/ '));
        self::assertSame('https://example.com/c/demo', AbraReadOnlyClient::normalizeUrl('https://example.com/v2/c/demo'));
    }

    public static function unsafeUrls(): array
    {
        return array_map(static fn ($url) => [$url], [
            'http://example.com/c/demo', 'https://user:secret@example.com/c/demo',
            'https://example.com/c/demo?token=secret', 'https://example.com/c/demo#fragment',
            'https://example.com/c/demo/../other', 'https://example.com/c/demo/ucetni-obdobi/inicializace-noveho-obdobi',
        ]);
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeCompanyUrls(string $url): void
    {
        $this->expectException(AbraException::class);
        AbraReadOnlyClient::normalizeUrl($url);
    }

    public function testRefusesMutatingGetActionBeforeAnyNetworkRequest(): void
    {
        $client = new AbraReadOnlyClient(new OutboundUrlGuard());
        try {
            $client->get([], 'ucetni-obdobi/inicializace-noveho-obdobi');
            self::fail('A mutating GET must not be sent.');
        } catch (AbraException $e) {
            self::assertSame('unsafe_request', $e->errorCode);
        }
    }

    public function testOwnPaginationCannotBeOverriddenByCaller(): void
    {
        $client = new PagingAbraClient();
        $rows = $client->list([], 'adresar', ['start' => 800, 'limit' => 1]);
        self::assertCount(1001, $rows);
        self::assertSame([0, 1000], array_column($client->queries, 'start'));
        self::assertSame([1000, 1000], array_column($client->queries, 'limit'));
    }

    public function testChangingCountStopsIncompleteExport(): void
    {
        $client = new PagingAbraClient();
        $client->changeCount = true;
        $this->expectException(AbraException::class);
        $this->expectExceptionMessage('Data ve zdroji se během načítání změnila.');
        $client->list([], 'adresar');
    }

    public function testExactPageCountDoesNotRequestAnExtraEmptyPage(): void
    {
        $client = new PagingAbraClient();
        $client->total = 1000;
        self::assertCount(1000, $client->list([], 'adresar'));
        self::assertCount(1, $client->queries);
    }

    public function testScanDeliversBoundedPagesInsteadOfCollectingAllRows(): void
    {
        $client = new PagingAbraClient();
        $sizes = [];
        $client->scan([], 'cenik', ['limit' => 500], static function (array $rows) use (&$sizes): void {
            $sizes[] = count($rows);
        });

        self::assertSame([500, 500, 1], $sizes);
        self::assertCount(3, $client->queries);
    }

    public function testCancellationStopsBeforeNextPage(): void
    {
        $client = new PagingAbraClient();
        $cancelled = false;
        try {
            $client->list([], 'adresar', [], static function () use (&$cancelled) { $cancelled = true; },
                static function () use (&$cancelled) { return $cancelled; });
            self::fail('Cancelled export continued.');
        } catch (AbraException $e) {
            self::assertSame('cancelled', $e->errorCode);
            self::assertCount(1, $client->queries);
        }
    }

    public function testCompletedPagesAreCapturedWithoutCredentials(): void
    {
        $client = new PagingAbraClient();
        $pages = [];
        $client->capturePages(static function (string $evidence, array $query, int $start, array $payload) use (&$pages): void {
            $pages[] = [$evidence, $query, $start, count($payload['winstrom'][$evidence])];
        });
        $client->list(['password' => 'synthetic-password'], 'adresar');
        self::assertSame([0, 1000], array_column($pages, 2));
        self::assertSame([1000, 1], array_column($pages, 3));
        self::assertStringNotContainsString('synthetic-password', json_encode($pages));
    }

    public function testTransportDiagnosticNeverExposesRawException(): void
    {
        $error = AbraReadOnlyClient::transportFailure(new OutboundRequestException('Operation timed out at https://secret.example/c/private'));
        self::assertSame('connection_timeout', $error->errorCode);
        self::assertStringNotContainsString('secret.example', $error->getMessage());
        self::assertNull($error->getPrevious());
        self::assertSame('response_too_large', AbraReadOnlyClient::transportFailure(new OutboundRequestException('private', OutboundRequestException::SIZE_LIMIT))->errorCode);
    }
}

final class PagingAbraClient extends AbraReadOnlyClient
{
    public array $queries = [];
    public bool $changeCount = false;
    public int $total = 1001;
    public function __construct() {}
    public function get(array $credentials, string $evidence, array $query = []): array
    {
        $this->queries[] = $query;
        $start = $query['start'];
        $rows = array_fill(0, min((int) $query['limit'], max(0, $this->total - $start)), ['id' => 'synthetic']);
        return ['winstrom' => ['@rowCount' => $this->changeCount && $start > 0 ? $this->total + 1 : $this->total, $evidence => $rows]];
    }
}
