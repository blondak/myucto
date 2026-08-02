<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Infrastructure\Config\InstallStateCache;
use PHPUnit\Framework\TestCase;

/**
 * Značka „setup hotový" zkratuje dotaz do DB v nejvnějším middleware. Nebezpečná
 * je jen jedním směrem: kdyby existovala předčasně, instalace by nikdy neposlala
 * uživatele na setup wizard. Testy proto hlídají hlavně to, kdy značka vzniknout
 * NESMÍ, a že se pod PHPUnitem nesahá na skutečný stroj.
 */
final class InstallStateCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/install-state-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $marker = InstallStateCache::markerPath($this->dir);
        if (is_string($marker)) {
            @unlink($marker);
            @rmdir(dirname($marker));
            @rmdir(dirname($marker, 2));
        }
        @rmdir($this->dir);
    }

    public function testMarkerIsAbsentUntilExplicitlyWritten(): void
    {
        self::assertFalse(InstallStateCache::isSetupComplete($this->dir));

        InstallStateCache::markSetupComplete($this->dir);
        self::assertTrue(InstallStateCache::isSetupComplete($this->dir));
    }

    public function testInvalidateRemovesTheMarker(): void
    {
        InstallStateCache::markSetupComplete($this->dir);
        self::assertTrue(InstallStateCache::invalidate($this->dir));
        self::assertFalse(InstallStateCache::isSetupComplete($this->dir));

        // Opakované volání nesmí spadnout ani hlásit úspěch.
        self::assertFalse(InstallStateCache::invalidate($this->dir));
    }

    public function testMarkingTwiceIsHarmless(): void
    {
        InstallStateCache::markSetupComplete($this->dir);
        $path = InstallStateCache::markerPath($this->dir);
        self::assertIsString($path);
        $first = (string) file_get_contents($path);

        InstallStateCache::markSetupComplete($this->dir);
        self::assertSame($first, (string) file_get_contents($path), 'Druhé označení nesmí značku přepsat.');
    }

    /**
     * Bez explicitní cesty se pod PHPUnitem značka vůbec neřeší — jinak by testy
     * četly (a přepisovaly) stav vývojového stroje a jejich výsledek by závisel
     * na tom, jestli na něm někdy proběhl setup.
     */
    public function testDefaultPathIsDisabledUnderPhpunit(): void
    {
        self::assertTrue(defined('PHPUNIT_COMPOSER_INSTALL'), 'Předpoklad testu.');
        self::assertNull(InstallStateCache::markerPath());
        self::assertFalse(InstallStateCache::isSetupComplete());

        InstallStateCache::markSetupComplete();
        self::assertFalse(InstallStateCache::isSetupComplete(), 'Zápis bez cesty nesmí nic vytvořit.');
    }

    public function testMarkerLivesInTheDisposableCacheDirectory(): void
    {
        $path = InstallStateCache::markerPath($this->dir);
        self::assertIsString($path);
        // storage/cache je gitignorovaný a reset.php ho čistí — značka tam patří,
        // ať se nikdy nedostane do repa ani nepřežije reset instalace.
        self::assertStringContainsString(
            DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR,
            $path,
        );
    }
}
