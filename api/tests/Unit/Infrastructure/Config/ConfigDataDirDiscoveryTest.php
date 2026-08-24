<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Dohledání datového adresáře, když `MYINVOICE_DATA_DIR` chybí.
 *
 * ⚠️ Kvůli konkrétnímu selhání: proměnnou má nastavenou webserver a systémový
 * cron, ale NE přihlášení přes SSH. Aktualizace spravované instalace spuštěná
 * z příkazové řádky proto nenačetla `cfg.local.php`, migrace se pokusila
 * připojit na výchozí údaje a spadla na `Access denied for user 'root'` —
 * uprostřed nasazení, s kódem nahraným a schématem neposunutým (2026-08-24).
 */
final class ConfigDataDirDiscoveryTest extends TestCase
{
    private ?string $previousEnv = null;
    private string $tmp = '';

    protected function setUp(): void
    {
        $env = getenv('MYINVOICE_DATA_DIR');
        $this->previousEnv = is_string($env) ? $env : null;
        putenv('MYINVOICE_DATA_DIR');

        $this->tmp = sys_get_temp_dir() . '/myucto-datadir-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/web', 0777, true);
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv !== null
            ? 'MYINVOICE_DATA_DIR=' . $this->previousEnv
            : 'MYINVOICE_DATA_DIR');
        self::removeTree($this->tmp);
    }

    /** Spravovaná instalace: aplikace v `<web>/`, data v `<web>/../private/myucto`. */
    public function testFindsManagedLayoutNextToTheApplicationRoot(): void
    {
        $dataDir = $this->tmp . '/private/myucto';
        mkdir($dataDir, 0777, true);
        file_put_contents($dataDir . '/cfg.local.php', '<?php return [];');

        self::assertSame(realpath($dataDir), $this->discover($this->tmp . '/web'));
    }

    /**
     * ⚠️ Rozhoduje přítomnost `cfg.local.php`, ne existence adresáře.
     * Prázdný `private/` se nesmí přijmout — instalace bez datového adresáře
     * musí dál dostat null a chovat se přesně jako dřív.
     */
    public function testDirectoryWithoutConfigIsNotAccepted(): void
    {
        mkdir($this->tmp . '/private/myucto', 0777, true);

        self::assertNull($this->discover($this->tmp . '/web'));
    }

    public function testReturnsNullWhenThereIsNothingToFind(): void
    {
        self::assertNull($this->discover($this->tmp . '/web'));
    }

    /**
     * ⚠️ Proměnná prostředí má VŽDY přednost — je to vědomé nastavení
     * provozovatele (Docker volume, sdílený hosting) a dohledání ho nesmí
     * přebít ani tehdy, když by nějakého kandidáta našlo.
     */
    public function testEnvironmentVariableWinsOverDiscovery(): void
    {
        putenv('MYINVOICE_DATA_DIR=' . $this->tmp . '/zvolena/cesta');

        self::assertSame($this->tmp . '/zvolena/cesta', Config::resolveDataDir());
    }

    /** Prázdná proměnná se bere, jako by nastavená nebyla. */
    public function testBlankEnvironmentVariableFallsBackToDiscovery(): void
    {
        putenv('MYINVOICE_DATA_DIR=   ');

        self::assertNull(Config::resolveDataDir());
    }

    private function discover(string $root): ?string
    {
        $m = new \ReflectionMethod(Config::class, 'discoverDataDir');

        return $m->invoke(null, $root);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
