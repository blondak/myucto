<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Update;

use MyInvoice\Service\Update\NativeUpdateService;
use PHPUnit\Framework\TestCase;

/**
 * Swap fáze nativní aktualizace.
 *
 * ⚠️ Tenhle test existuje kvůli konkrétnímu selhání. Na první spravované
 * instanci (2026-08-24) aktualizace spadla uprostřed: swap zálohoval
 * KOPÍROVÁNÍM, takže na svazku musely naráz existovat tři stromy — instalace,
 * rozbalený stage a rostoucí záloha. Sdílený hosting měl strop počtu souborů
 * (inody) jen kolem dvojnásobku instalace, záloha narazila po 1682 souborech
 * a rollback pak neobnovil ANI JEDEN, protože i on si zapisoval dočasnou kopii
 * vedle cíle.
 *
 * Klíčová vlastnost proto není „soubory se nasadí", ale **kolik souborů swap
 * potřebuje mít na disku naráz**. To hlídá
 * {@see self::testSwapNeverIncreasesTheNumberOfFilesOnDisk()}.
 */
final class NativeUpdateSwapTest extends TestCase
{
    private string $tmp;
    private string $root;
    private string $stage;
    private string $backup;
    private string $log;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/myucto-swap-' . bin2hex(random_bytes(6));
        $this->root   = $this->tmp . '/root';
        $this->stage  = $this->tmp . '/stage';
        $this->backup = $this->tmp . '/backup';
        foreach ([$this->root, $this->stage, $this->backup] as $d) {
            mkdir($d, 0777, true);
        }
        $this->log = $this->tmp . '/update.log';
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ---- to podstatné --------------------------------------------------

    /**
     * Swap nesmí zvýšit počet souborů na svazku.
     *
     * Záloha vzniká PŘESUNEM původního souboru a nová verze se PŘESOUVÁ ze
     * stage, takže co jinde ubude, jinde přibude. Soubory beze změny se ze
     * stage rovnou zahodí, takže bilance dokonce klesá.
     */
    public function testSwapNeverIncreasesTheNumberOfFilesOnDisk(): void
    {
        // 20 souborů beze změny (typický `vendor/`), 5 změněných, 2 nové.
        for ($i = 0; $i < 20; $i++) {
            $this->put($this->root, "api/vendor/lib$i.php", "stejne-$i");
            $this->put($this->stage, "api/vendor/lib$i.php", "stejne-$i");
        }
        for ($i = 0; $i < 5; $i++) {
            $this->put($this->root, "api/src/Zmena$i.php", "stara-$i");
            $this->put($this->stage, "api/src/Zmena$i.php", "nova-$i");
        }
        for ($i = 0; $i < 2; $i++) {
            $this->put($this->stage, "api/src/Nova$i.php", "nova-$i");
        }

        $before = self::countFiles($this->tmp);
        $this->swap();
        $after = self::countFiles($this->tmp);

        self::assertLessThanOrEqual(
            $before,
            $after,
            'swap si nesmí naráz vyžádat víc souborů, než kolik jich na disku už bylo'
        );
    }

    /** Soubor beze změny se nezálohuje ani nepřepisuje — jen zmizí ze stage. */
    public function testUnchangedFileIsSkippedEntirely(): void
    {
        $this->put($this->root, 'api/vendor/same.php', 'totozne');
        $this->put($this->stage, 'api/vendor/same.php', 'totozne');

        $deployed = $this->swap();

        self::assertSame(0, $deployed, 'beze změny se nic nenasazuje');
        self::assertFileDoesNotExist($this->backup . '/api/vendor/same.php');
        self::assertFileDoesNotExist($this->stage . '/api/vendor/same.php');
        self::assertSame('totozne', file_get_contents($this->root . '/api/vendor/same.php'));
    }

    /** Změněný soubor: záloha drží STAROU verzi, instalace novou, stage je prázdný. */
    public function testChangedFileIsBackedUpAndReplaced(): void
    {
        $this->put($this->root, 'api/src/A.php', 'stara');
        $this->put($this->stage, 'api/src/A.php', 'nova');

        $deployed = $this->swap();

        self::assertSame(1, $deployed);
        self::assertSame('nova', file_get_contents($this->root . '/api/src/A.php'));
        self::assertSame('stara', file_get_contents($this->backup . '/api/src/A.php'));
        self::assertFileDoesNotExist($this->stage . '/api/src/A.php');
    }

    /** Nový soubor se nasadí a zálohovat není co. */
    public function testNewFileIsDeployedWithoutBackup(): void
    {
        $this->put($this->stage, 'api/src/New.php', 'obsah');

        $deployed = $this->swap();

        self::assertSame(1, $deployed);
        self::assertSame('obsah', file_get_contents($this->root . '/api/src/New.php'));
        self::assertFileDoesNotExist($this->backup . '/api/src/New.php');
    }

    /**
     * ⚠️ Konfigurace a data se nesmí přepsat ani teď, když swap přesouvá.
     * `VERSION` píše až krok `finish`, takže swap ho nechává být taky.
     */
    public function testProtectedPathsAndVersionSurvive(): void
    {
        $this->put($this->root, 'cfg.local.php', 'MOJE-KONFIGURACE');
        $this->put($this->stage, 'cfg.local.php', 'VZOR');
        $this->put($this->root, 'storage/data.txt', 'moje-data');
        $this->put($this->stage, 'storage/data.txt', 'z-balicku');
        $this->put($this->root, 'VERSION', '5.25.2');
        $this->put($this->stage, 'VERSION', '5.25.3');

        $this->swap();

        self::assertSame('MOJE-KONFIGURACE', file_get_contents($this->root . '/cfg.local.php'));
        self::assertSame('moje-data', file_get_contents($this->root . '/storage/data.txt'));
        self::assertSame('5.25.2', file_get_contents($this->root . '/VERSION'));
    }

    /**
     * Rollback musí vrátit starou verzi i tehdy, když se swap zastavil až
     * v půlce — to je jediná situace, ve které se vůbec spouští.
     */
    public function testRollbackRestoresBackedUpFiles(): void
    {
        $this->put($this->root, 'api/src/A.php', 'stara');
        $this->put($this->stage, 'api/src/A.php', 'nova');

        $svc = new NativeUpdateService($this->root, $this->tmp);
        $this->swap($svc);
        self::assertSame('nova', file_get_contents($this->root . '/api/src/A.php'));

        $m = new \ReflectionMethod($svc, 'rollback');
        $restored = $m->invoke($svc, $this->backup, $this->log);

        self::assertSame(1, $restored);
        self::assertSame('stara', file_get_contents($this->root . '/api/src/A.php'));
    }

    // ---- pomocné -------------------------------------------------------

    private function swap(?NativeUpdateService $svc = null): int
    {
        $svc ??= new NativeUpdateService($this->root, $this->tmp);
        $m = new \ReflectionMethod($svc, 'swap');

        return (int) $m->invoke($svc, $this->stage, $this->backup, '5.25.3', $this->log);
    }

    private function put(string $base, string $rel, string $content): void
    {
        $path = $base . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $n++;
            }
        }

        return $n;
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
