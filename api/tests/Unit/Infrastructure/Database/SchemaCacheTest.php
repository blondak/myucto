<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\SchemaCache;
use PHPUnit\Framework\TestCase;

/**
 * Cache drží odpovědi na „existuje tenhle sloupec?" mezi requesty. Chyba tady je
 * zákeřná: aplikace by tvrdila, že sloupec zavedený migrací neexistuje, a tiše
 * běžela bez příslušné funkce. Proto se testuje hlavně to, KDY se cache NEPOUŽIJE.
 */
final class SchemaCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/schema-cache-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/storage/cache/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/storage/cache');
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir);
    }

    private function path(string $db = 'testdb'): string
    {
        $p = SchemaCache::pathFor($this->dir, $db);
        self::assertIsString($p);

        return $p;
    }

    public function testRoundTripPersistsBothPositiveAndNegativeAnswers(): void
    {
        $c = new SchemaCache($this->path(), 'testdb');
        $c->put('table:license', true);
        // Negativní odpověď se MUSÍ cachovat taky — feature-detekce se ptá hlavně
        // na věci, které ještě neexistují, a právě ty dotazy jsou ty drahé.
        $c->put('table:jeste_nemigrovana', false);
        $c->flush();

        $fresh = new SchemaCache($this->path(), 'testdb');
        self::assertTrue($fresh->get('table:license'));
        self::assertFalse($fresh->get('table:jeste_nemigrovana'));
        self::assertNull($fresh->get('table:nikdy_nedotazovana'), 'Neznámý klíč musí vrátit null, ne false.');
    }

    public function testCacheFromAnotherDatabaseIsIgnored(): void
    {
        // Kdyby se klíče míchaly mezi databázemi, testovací běh by otrávil ostrou
        // instalaci (nebo naopak) a projevilo by se to až chybějící funkcí.
        $c = new SchemaCache($this->path('db_a'), 'db_a');
        $c->put('table:license', true);
        $c->flush();

        $other = new SchemaCache($this->path('db_a'), 'db_b');
        self::assertNull($other->get('table:license'));
    }

    public function testExpiredCacheIsIgnored(): void
    {
        $c = new SchemaCache($this->path(), 'testdb', 300);
        $c->put('table:license', true);
        $c->flush();

        // Posuň zápis do minulosti za TTL — pojistka pro schéma změněné mimo migrace.
        $raw = json_decode((string) file_get_contents($this->path()), true);
        $raw['written_at'] = time() - 301;
        file_put_contents($this->path(), json_encode($raw));

        $expired = new SchemaCache($this->path(), 'testdb', 300);
        self::assertNull($expired->get('table:license'));

        $stillValid = new SchemaCache($this->path(), 'testdb', 3600);
        self::assertTrue($stillValid->get('table:license'), 'Delší TTL musí tentýž soubor ještě uznat.');
    }

    public function testInvalidateRemovesTheFile(): void
    {
        $c = new SchemaCache($this->path(), 'testdb');
        $c->put('table:license', true);
        $c->flush();
        self::assertFileExists($this->path());

        self::assertTrue(SchemaCache::invalidate($this->path()));
        self::assertFileDoesNotExist($this->path());

        // Druhé volání nesmí spadnout ani lhát, že něco smazalo.
        self::assertFalse(SchemaCache::invalidate($this->path()));
        self::assertFalse(SchemaCache::invalidate(null));
    }

    public function testCorruptFileDegradesToMissInsteadOfThrowing(): void
    {
        $path = $this->path();
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, '{ tohle není validní JSON');

        $c = new SchemaCache($path, 'testdb');
        self::assertNull($c->get('table:license'), 'Poškozený soubor = cache miss, ne výjimka.');
    }

    public function testFlushWithoutChangesDoesNotCreateFile(): void
    {
        $c = new SchemaCache($this->path(), 'testdb');
        $c->flush();
        self::assertFileDoesNotExist($this->path());
    }

    public function testPathIsNullWhenThereIsNowhereToWrite(): void
    {
        self::assertNull(SchemaCache::pathFor(null, 'testdb'));
        self::assertNull(SchemaCache::pathFor($this->dir, ''));
    }

    public function testDatabaseNameCannotEscapeTheCacheDirectory(): void
    {
        $p = SchemaCache::pathFor($this->dir, '../../etc/passwd');
        self::assertIsString($p);
        self::assertStringNotContainsString('..', basename($p));
        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache', dirname($p));
    }
}
