<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraPageCache;
use MyInvoice\Service\Migration\Abra\AbraReadOnlyClient;
use PHPUnit\Framework\TestCase;

final class AbraPageCacheTest extends TestCase
{
    public function testPruneExpiredRemovesOnlyOldCachePages(): void
    {
        $directory = sys_get_temp_dir() . '/abra-page-cache-' . bin2hex(random_bytes(8));
        try {
            $old = new AbraPageCache(1, 1, $directory);
            $old->save('banka', [], 0, ['winstrom' => ['banka' => []]]);
            $recent = new AbraPageCache(1, 2, $directory);
            $recent->save('banka', [], 0, ['winstrom' => ['banka' => []]]);
            $oldFile = glob($directory . '/sup-1/version-1/*.json')[0];
            touch($oldFile, time() - 31 * 86400);

            self::assertSame(1, AbraPageCache::pruneExpired($directory, 30 * 86400));
            self::assertFalse($old->has('banka', [], 0));
            self::assertTrue($recent->has('banka', [], 0));
        } finally {
            if (is_dir($directory)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($directory);
            }
        }
    }

    public function testLargePagedExportResumesWithoutRepeatingFullPagesAndDetectsChanges(): void
    {
        $directory = sys_get_temp_dir() . '/abra-page-cache-' . bin2hex(random_bytes(8));
        try {
            $client = new CacheFixtureAbraClient();
            $client->rows = array_map(static fn (int $id): array => [
                'id' => $id, 'lastUpdate' => '2026-01-01T00:00:00+01:00', 'sumCelkem' => 121.0,
            ], range(1, 1001));
            $client->usePageCache(new AbraPageCache(1, 1, $directory));
            self::assertCount(1001, $client->list([], 'faktura-vydana', ['filter' => 'datUcto >= 2026-01-01']));
            self::assertSame(2, $client->fullCalls());
            self::assertSame(0, $client->metadataCalls());

            $cachedPage = glob($directory . '/sup-1/version-1/*.json')[0];
            touch($cachedPage, time() - 31 * 86400);

            $client->calls = [];
            self::assertCount(1001, $client->list([], 'faktura-vydana', ['filter' => 'datUcto >= 2026-01-01']));
            self::assertSame(0, $client->fullCalls());
            self::assertSame(2, $client->metadataCalls());
            clearstatcache(true, $cachedPage);
            self::assertGreaterThan(time() - 60, filemtime($cachedPage));

            $client->rows[0]['lastUpdate'] = '2026-01-02T00:00:00+01:00';
            $client->calls = [];
            self::assertCount(1001, $client->list([], 'faktura-vydana', ['filter' => 'datUcto >= 2026-01-01']));
            self::assertSame(1, $client->fullCalls());
            self::assertSame(2, $client->metadataCalls());

            $client->rows[] = ['id' => 1002, 'lastUpdate' => '2026-01-02T00:00:00+01:00', 'sumCelkem' => 121.0];
            $client->calls = [];
            self::assertCount(1002, $client->list([], 'faktura-vydana', ['filter' => 'datUcto >= 2026-01-01']));
            self::assertSame(1, $client->fullCalls());
            self::assertSame(2, $client->metadataCalls());

            $client->usePageCache(new AbraPageCache(2, 1, $directory));
            $client->calls = [];
            self::assertCount(1002, $client->list([], 'faktura-vydana', ['filter' => 'datUcto >= 2026-01-01']));
            self::assertSame(2, $client->fullCalls());
        } finally {
            if (is_dir($directory)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($directory);
            }
        }
    }
}

final class CacheFixtureAbraClient extends AbraReadOnlyClient
{
    public array $rows = [];
    public array $calls = [];

    public function __construct() {}

    public function get(array $credentials, string $evidence, array $query = []): array
    {
        $metadata = str_starts_with((string) ($query['detail'] ?? ''), 'custom:');
        $this->calls[] = $metadata ? 'metadata' : 'full';
        $rows = array_slice($this->rows, (int) ($query['start'] ?? 0), (int) ($query['limit'] ?? 1000));
        if ($metadata) {
            $rows = array_map(static fn (array $row): array => [
                'id' => $row['id'], 'lastUpdate' => $row['lastUpdate'],
            ], $rows);
        }
        return ['winstrom' => ['@rowCount' => count($this->rows), $evidence => $rows]];
    }

    public function fullCalls(): int { return count(array_filter($this->calls, static fn (string $call): bool => $call === 'full')); }
    public function metadataCalls(): int { return count(array_filter($this->calls, static fn (string $call): bool => $call === 'metadata')); }
}
