<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression guardy pro §1 analýzy private/CACHE.md (2026-07-20) — chování
 * bezpečnostních vrstev, když Redis chybí nebo spadne uprostřed requestu.
 *
 * Nálezy:
 *   §1.1  redis.auth se nikdy nepředal Predisu → tichý fallback na DB
 *   §1.2  RateLimitMiddleware bez Redisu = no-op (žádný limit na /api/public/*)
 *   §1.3  Predis volání mimo try → HTTP 500 při pádu spojení uprostřed requestu
 *   §1.4  Redis bez maxmemory-policy → OOM kill místo evikce
 *
 * Code-inspection (bez Redisu v CI). Behaviorální pokrytí DB fallbacku limiteru
 * je v Integration/RateLimitDbFallbackTest.
 */
final class RedisDegradationTest extends TestCase
{
    private function src(string $rel): string
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/' . $rel);
        self::assertIsString($code, "Soubor $rel musí jít načíst");
        return $code;
    }

    private function repoFile(string $rel): string
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/' . $rel);
        self::assertIsString($code, "Soubor $rel musí jít načíst");
        return $code;
    }

    // ---- §1.1 — heslo se musí předat Predisu ---------------------------------

    public function testRedisFactoryPassesPassword(): void
    {
        $code = $this->src('Infrastructure/Cache/RedisFactory.php');
        self::assertStringContainsString("\$this->config->get('redis.auth')", $code,
            'RedisFactory musí číst redis.auth');
        self::assertStringContainsString("\$params['password']", $code,
            'RedisFactory musí předat heslo Predisu — jinak spojení proti requirepass tiše spadne na DB');
    }

    public function testRedisProbePassesPassword(): void
    {
        $code = $this->src('Infrastructure/Cache/RedisProbe.php');
        self::assertStringContainsString("\$params['password']", $code,
            'RedisProbe musí předat heslo — jinak /api/health hlásí redis:false i u běžícího Redisu');
    }

    // ---- §1.3 — pád spojení nesmí shodit request -----------------------------

    public function testRedisFactoryExposesGuardedRun(): void
    {
        $code = $this->src('Infrastructure/Cache/RedisFactory.php');
        self::assertStringContainsString('public function run(', $code,
            'RedisFactory musí nabídnout run() s ochranou proti výjimkám z Predisu');
        self::assertMatchesRegularExpression('/catch \(\\\\Throwable\).*\$this->client = null/s', $code,
            'run() musí při chybě zneplatnit memoizovaného klienta a degradovat, ne propustit výjimku');
    }

    /**
     * Bezpečnostní vrstvy nesmí volat Predis přímo přes client() — jediné
     * povolené místo je run(), které pád spojení převede na degradaci.
     */
    public function testSecurityServicesUseGuardedRun(): void
    {
        foreach ([
            'Service/Auth/BruteForceGuard.php',
            'Service/Auth/ApiTokenService.php',
            'Middleware/RateLimitMiddleware.php',
        ] as $rel) {
            $code = $this->src($rel);
            self::assertStringNotContainsString('$this->redis->client()', $code,
                "$rel nesmí volat client() přímo — nechráněné volání shodí request na 500 při pádu Redisu");
            self::assertStringContainsString('$this->redis->run(', $code,
                "$rel musí jít přes RedisFactory::run()");
        }
    }

    /**
     * Session jsou od passkeys autoritativně v MariaDB (upstream PR #239): výpadek
     * Redisu nesmí obnovit odvolanou, nahrazenou ani zamčenou session. Redis se
     * proto do SessionManageru nesmí vrátit v žádné podobě — ani přes run().
     */
    public function testSessionManagerDoesNotUseRedisAtAll(): void
    {
        $code = $this->src('Service/Auth/SessionManager.php');
        self::assertStringNotContainsString('$this->redis', $code,
            'SessionManager musí být čistě databázový — Redis cache session by obešla revokaci i zámek');
    }

    // ---- §1.2 — limiter platí i bez Redisu -----------------------------------

    public function testRateLimitHasDatabaseFallback(): void
    {
        $code = $this->src('Middleware/RateLimitMiddleware.php');
        self::assertStringContainsString('rate_limit_counters', $code,
            'RateLimitMiddleware musí mít DB fallback — bez Redisu byl limiter no-op');
        self::assertStringContainsString('dbState', $code);
        self::assertStringContainsString('dbIncrement', $code);
        self::assertStringNotContainsString('// bez Redis no-op', $code,
            'No-op větev bez Redisu se nesmí vrátit');
    }

    public function testRateLimitCountersMigrationExists(): void
    {
        $sql = $this->repoFile('db/migrations/1135_rate_limit_counters.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS rate_limit_counters', $sql);
        self::assertStringContainsString('idx_rlc_bucket', $sql,
            'Bucket lookup musí být indexovaný');
    }

    /**
     * MEMORY tabulka má tvrdý strop `max_heap_table_size` (16 MB). Model „jeden
     * řádek na request" ho na produkci vyčerpal (31 665 řádků / 15,7 MB) a plná
     * tabulka shodila celé API do 500. Čítač proto MUSÍ být agregovaný.
     */
    public function testRateLimitCountersAreAggregated(): void
    {
        $sql = $this->repoFile('db/migrations/1317_rate_limit_counters_agregace.sql');
        self::assertStringContainsString('hits', $sql,
            'Tabulka musí držet čítač, ne řádek na každý request');
        self::assertStringContainsString('PRIMARY KEY (bucket_key, window_start)', $sql,
            'Jeden řádek na (bucket, okno) je to, co drží velikost tabulky konečnou');

        $code = $this->src('Middleware/RateLimitMiddleware.php');
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE hits = hits + 1', $code,
            'Zápis musí inkrementovat čítač, ne přidávat řádek');
    }

    public function testCleanupPurgesRateLimitCounters(): void
    {
        $code = $this->repoFile('api/bin/cron-cleanup.php');
        self::assertStringContainsString('rate_limit_counters', $code,
            'cron-cleanup musí uklízet rate_limit_counters — MEMORY tabulka jinak roste');

        // Denní cron je jen záchranná brzda — hlavní úklid dělá middleware sám,
        // jinak instalace bez naplánované úlohy zaplní tabulku znovu.
        $mw = $this->src('Middleware/RateLimitMiddleware.php');
        self::assertStringContainsString('dbPruneExpired', $mw,
            'Limiter musí mazat expirované buckety za běhu, ne spoléhat na cron');
    }

    /**
     * Plná/nedostupná tabulka čítačů nesmí shodit request — přesně tohle udělalo
     * z pomocné MEMORY tabulky výpadek celého API.
     */
    public function testRateLimitFailsOpenOnDatabaseError(): void
    {
        $code = $this->src('Middleware/RateLimitMiddleware.php');
        self::assertStringContainsString('dbUnavailable', $code,
            'DB větev limiteru musí mít fail-open cestu');
        self::assertMatchesRegularExpression('/catch \(\\\\Throwable/', $code,
            'Chyba zápisu čítače se musí zachytit, ne propadnout do 500');
    }

    // ---- §1.4 — Redis nesmí běžet bez limitu paměti ---------------------------

    public function testComposeSetsMaxmemoryPolicy(): void
    {
        foreach ([
            'docker-compose.yml',
            'docker-compose.production.yml',
            'docker-compose.portainer.yml',
        ] as $rel) {
            $yaml = $this->repoFile($rel);
            self::assertStringContainsString('--maxmemory-policy', $yaml,
                "$rel: Redis bez maxmemory-policy se nechá OOM-killnout místo evikce");
            self::assertStringContainsString('volatile-lru', $yaml,
                "$rel: allkeys-lru by evikoval i klíče bez TTL — použij volatile-lru");
            self::assertStringContainsString('MYINVOICE_REDIS_HOST', $yaml,
                "$rel: bez ENV wiringu aplikace o Redisu neví ani se spuštěným profilem");
        }
    }
}
