<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Update;

use MyInvoice\Service\Update\NativeUpdateService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Nativní updater přepisuje soubory běžící instalace, takže se testují
 * především ochranné invarianty: co se NESMÍ přepsat, co se musí zazálohovat
 * a že se z archivu nedá zapsat mimo strom.
 *
 * `swap()` a `extractBundle()` jsou private (nemají být součástí API služby),
 * volají se přes reflexi — testujeme skutečné chování, ne obcházecí wrapper.
 */
final class NativeUpdateServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/myucto-upd-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    // ---------- verze -----------------------------------------------------

    public function testOnlyPlainSemverIsAccepted(): void
    {
        self::assertTrue(NativeUpdateService::isValidVersion('5.0.5'));
        self::assertTrue(NativeUpdateService::isValidVersion('10.20.30'));

        // Cokoliv, co by mohlo prolézt do cesty nebo URL, musí spadnout.
        self::assertFalse(NativeUpdateService::isValidVersion('5.0'));
        self::assertFalse(NativeUpdateService::isValidVersion('v5.0.5'));
        self::assertFalse(NativeUpdateService::isValidVersion('5.0.5-rc1'));
        self::assertFalse(NativeUpdateService::isValidVersion('../../etc'));
        self::assertFalse(NativeUpdateService::isValidVersion('5.0.5/../..'));
        self::assertFalse(NativeUpdateService::isValidVersion(''));
    }

    // ---------- chráněné cesty --------------------------------------------

    public function testConfigAndUserDataPathsAreProtected(): void
    {
        $svc = new NativeUpdateService($this->tmp, $this->tmp);

        foreach (['cfg.php', 'cfg.local.php', 'cfg.docker.php', '.env'] as $path) {
            self::assertTrue($svc->isProtected($path), $path . ' musí být chráněná');
        }
        foreach (['storage', 'storage/documents/1.pdf', 'private/AUDIT.md', 'log/app.log', 'tmp/x', '.git/config'] as $path) {
            self::assertTrue($svc->isProtected($path), $path . ' musí být chráněná');
        }

        foreach (['VERSION', 'api/src/Bootstrap.php', 'web/dist/index.html', 'cfg.sample.php'] as $path) {
            self::assertFalse($svc->isProtected($path), $path . ' se aktualizovat musí');
        }
        // Prefix se porovnává na celý segment — `storageX` není `storage`.
        self::assertFalse($svc->isProtected('storageX/file'));
    }

    // ---------- swap ------------------------------------------------------

    public function testSwapOverwritesCodeBacksItUpAndNeverTouchesProtectedPaths(): void
    {
        $root  = $this->tmp . '/root';
        $stage = $this->tmp . '/stage';
        $backup = $this->tmp . '/backup';

        // Instalace: starý kód + konfigurace a data uživatele.
        $this->write($root . '/VERSION', '1.0.0');
        $this->write($root . '/api/src/App.php', 'STARY KOD');
        $this->write($root . '/cfg.php', 'TAJNA KONFIGURACE');
        $this->write($root . '/storage/documents/faktura.pdf', 'DATA UZIVATELE');
        $this->write($root . '/log/app.log', 'LOG');

        // Bundle: nový kód + (teoreticky) i cfg/storage, které se musí ignorovat.
        $this->write($stage . '/VERSION', '2.0.0');
        $this->write($stage . '/api/src/App.php', 'NOVY KOD');
        $this->write($stage . '/api/src/New.php', 'NOVY SOUBOR');
        $this->write($stage . '/cfg.php', 'PODVRZENA KONFIGURACE');
        $this->write($stage . '/storage/documents/faktura.pdf', 'PODVRZENA DATA');

        $svc = new NativeUpdateService($root, $this->tmp);
        mkdir($backup, 0775, true);
        $files = $this->invoke($svc, 'swap', [$stage, $backup, '2.0.0', $this->tmp . '/test.log']);

        // Kód se nasadil.
        self::assertSame('NOVY KOD', file_get_contents($root . '/api/src/App.php'));
        self::assertSame('NOVY SOUBOR', file_get_contents($root . '/api/src/New.php'));

        // Konfigurace a data uživatele zůstaly nedotčené.
        self::assertSame('TAJNA KONFIGURACE', file_get_contents($root . '/cfg.php'));
        self::assertSame('DATA UZIVATELE', file_get_contents($root . '/storage/documents/faktura.pdf'));
        self::assertSame('LOG', file_get_contents($root . '/log/app.log'));

        // VERSION se ve swapu záměrně nepřepisuje — až po migracích.
        self::assertSame('1.0.0', file_get_contents($root . '/VERSION'));

        // Přepsaný soubor je v záloze v původní podobě, nový soubor v záloze být nemá.
        self::assertSame('STARY KOD', file_get_contents($backup . '/api/src/App.php'));
        self::assertFileDoesNotExist($backup . '/api/src/New.php');

        // Po swapu nesmí zůstat žádný .myucto-upd mezisoubor.
        self::assertSame([], $this->findByExtension($root, 'myucto-upd'));

        self::assertSame(2, $files, 'nasadit se měly právě dva soubory kódu');
    }

    public function testSwapDeploysNothingOutsideRoot(): void
    {
        $root  = $this->tmp . '/root';
        $stage = $this->tmp . '/stage';
        $outside = $this->tmp . '/outside.txt';

        $this->write($root . '/keep.txt', 'ROOT');
        $this->write($outside, 'MIMO');
        $this->write($stage . '/api/x.php', 'X');

        $svc = new NativeUpdateService($root, $this->tmp);
        mkdir($this->tmp . '/bk', 0775, true);
        $this->invoke($svc, 'swap', [$stage, $this->tmp . '/bk', '2.0.0', $this->tmp . '/test.log']);

        self::assertSame('MIMO', file_get_contents($outside), 'swap nesmí zapsat mimo root');
        self::assertFileExists($root . '/api/x.php');
    }

    // ---------- extrakce archivu ------------------------------------------

    public function testExtractRejectsArchiveWithEntryOutsideVersionPrefix(): void
    {
        $work   = $this->tmp . '/work';
        $bundle = $this->buildBundle($work, '9.9.9', extraEntries: ['mimo-prefix.txt']);

        $svc = new NativeUpdateService($this->tmp . '/root', $this->tmp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mimo očekávaný adresář/u');
        $this->invoke($svc, 'extractBundle', [$bundle, $work, '9.9.9', $this->tmp . '/test.log']);
    }

    public function testExtractRejectsTraversalEntryAndWritesNothingOutside(): void
    {
        $work   = $this->tmp . '/work';
        $bundle = $this->buildBundle($work, '9.9.9', extraEntries: ['myucto-9.9.9/../../evil.txt']);
        $svc    = new NativeUpdateService($this->tmp . '/root', $this->tmp);

        $thrown = null;
        try {
            $this->invoke($svc, 'extractBundle', [$bundle, $work, '9.9.9', $this->tmp . '/test.log']);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown, 'archiv s ../ v cestě musí extrakci zastavit');
        self::assertMatchesRegularExpression('/traversal/u', $thrown->getMessage());

        // A hlavně: nikde vedle staging adresáře nesmí nic přistát.
        self::assertFileDoesNotExist($work . '/stage/evil.txt');
        self::assertFileDoesNotExist($work . '/evil.txt');
        self::assertFileDoesNotExist($this->tmp . '/evil.txt');
        self::assertFileDoesNotExist(dirname($this->tmp) . '/evil.txt');
    }

    public function testExtractRejectsSymlinkEntry(): void
    {
        $work   = $this->tmp . '/work';
        $bundle = $this->buildBundle($work, '9.9.9', linkEntry: 'myucto-9.9.9/api/evil-link');
        $svc    = new NativeUpdateService($this->tmp . '/root', $this->tmp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/odkaz/u');
        $this->invoke($svc, 'extractBundle', [$bundle, $work, '9.9.9', $this->tmp . '/test.log']);
    }

    /**
     * Regrese: production bundle staví GNU tar a cesty nad 100 znaků dělí do
     * ustar polí `prefix` + `name`. Přesně na tom PharData padal a nerozbalil
     * vůbec nic, takže tenhle případ musí být pokrytý.
     */
    public function testExtractHandlesUstarPrefixSplitLongPaths(): void
    {
        $work = $this->tmp . '/work';
        $long = 'api/vendor/carbonphp/carbon-doctrine-types/src/Carbon/Doctrine/DateTimeDefaultPrecision.php';
        self::assertGreaterThan(100, strlen('myucto-9.9.9/' . $long), 'cesta musí přetéct 100B pole name');

        $bundle = $this->buildBundle($work, '9.9.9', ustarSplitEntries: ['myucto-9.9.9/' . $long]);
        $svc    = new NativeUpdateService($this->tmp . '/root', $this->tmp);
        $stage  = $this->invoke($svc, 'extractBundle', [$bundle, $work, '9.9.9', $this->tmp . '/test.log']);

        self::assertFileExists($stage . '/' . $long);
        self::assertSame('x', file_get_contents($stage . '/' . $long));
    }

    public function testExtractRejectsBundleWhoseVersionDoesNotMatchTarget(): void
    {
        $work   = $this->tmp . '/work';
        $bundle = $this->buildBundle($work, '9.9.9', stagedVersion: '1.2.3');

        $svc = new NativeUpdateService($this->tmp . '/root', $this->tmp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/hlásí verzi/u');
        $this->invoke($svc, 'extractBundle', [$bundle, $work, '9.9.9', $this->tmp . '/test.log']);
    }

    public function testExtractRejectsIncompleteBundle(): void
    {
        $work   = $this->tmp . '/work';
        $bundle = $this->buildBundle($work, '9.9.9', complete: false);

        $svc = new NativeUpdateService($this->tmp . '/root', $this->tmp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kompletní production bundle/u');
        $this->invoke($svc, 'extractBundle', [$bundle, $work, '9.9.9', $this->tmp . '/test.log']);
    }

    public function testExtractAcceptsCompleteBundle(): void
    {
        $work   = $this->tmp . '/work';
        $bundle = $this->buildBundle($work, '9.9.9');

        $svc   = new NativeUpdateService($this->tmp . '/root', $this->tmp);
        $stage = $this->invoke($svc, 'extractBundle', [$bundle, $work, '9.9.9', $this->tmp . '/test.log']);

        self::assertDirectoryExists($stage);
        self::assertSame('9.9.9', trim((string) file_get_contents($stage . '/VERSION')));
        self::assertFileExists($stage . '/api/vendor/autoload.php');
    }

    // ---------- checksum ---------------------------------------------------

    public function testChecksumMismatchDeletesBundleAndFails(): void
    {
        $bundle = $this->tmp . '/bundle.tar.gz';
        $this->write($bundle, 'obsah');

        $svc = new NativeUpdateService($this->tmp . '/root', $this->tmp);

        try {
            $this->invoke($svc, 'verifyChecksum', [$bundle, str_repeat('a', 64), $this->tmp . '/test.log']);
            self::fail('neshoda checksumu musí vyhodit výjimku');
        } catch (RuntimeException $e) {
            self::assertMatchesRegularExpression('/Kontrolní součet nesouhlasí/u', $e->getMessage());
        }

        self::assertFileDoesNotExist($bundle, 'nedůvěryhodný bundle se musí smazat');
    }

    public function testChecksumMatchPasses(): void
    {
        $bundle = $this->tmp . '/bundle.tar.gz';
        $this->write($bundle, 'obsah');

        $svc = new NativeUpdateService($this->tmp . '/root', $this->tmp);
        $this->invoke($svc, 'verifyChecksum', [$bundle, (string) hash_file('sha256', $bundle), $this->tmp . '/test.log']);

        self::assertFileExists($bundle);
    }

    // ---------- URL allowlist ---------------------------------------------

    public function testOnlyHttpsGithubHostsAreAllowed(): void
    {
        $svc = new NativeUpdateService($this->tmp . '/root', $this->tmp);

        // Povolené
        $this->invoke($svc, 'assertAllowedUrl', ['https://api.github.com/repos/x/y/releases/tags/v1.0.0']);
        $this->invoke($svc, 'assertAllowedUrl', ['https://objects.githubusercontent.com/blob']);

        foreach ([
            'http://github.com/x'            => '/Povolené je jen HTTPS/u',
            'https://evil.example.com/x'     => '/Nepovolený host/u',
            'https://github.com.evil.tld/x'  => '/Nepovolený host/u',
            'file:///etc/passwd'             => '/Povolené je jen HTTPS/u',
        ] as $url => $pattern) {
            try {
                $this->invoke($svc, 'assertAllowedUrl', [$url]);
                self::fail('mělo být odmítnuto: ' . $url);
            } catch (RuntimeException $e) {
                self::assertMatchesRegularExpression($pattern, $e->getMessage(), $url);
            }
        }
    }

    // ---------- preflight -------------------------------------------------

    public function testPreflightBlocksInvalidTargetVersion(): void
    {
        $svc = new NativeUpdateService($this->tmp, $this->tmp);
        $result = $svc->preflight('nesmysl');

        self::assertFalse($result['ok']);
        self::assertNotSame([], $result['blockers']);
    }

    public function testPreflightPassesOnWritableRoot(): void
    {
        $svc = new NativeUpdateService($this->tmp, $this->tmp);
        $result = $svc->preflight('9.9.9');

        self::assertTrue($result['ok'], 'blockery: ' . implode(' | ', $result['blockers']));
        // Probe soubory po sobě nesmí nechat.
        self::assertFileDoesNotExist($this->tmp . '/.myucto-write-probe');
        self::assertFileDoesNotExist($this->tmp . '/.myucto-replace-probe');
    }

    // ---------- helpers ---------------------------------------------------

    /**
     * Postaví tar.gz ve tvaru production bundlu: vše pod `myucto-<verze>/`.
     *
     * Tar se skládá ručně (ustar hlavičky + gzencode) záměrně — PharData jako
     * *zapisovač* by nedovolil vyrobit archiv s `..` v cestě, takže by nešlo
     * otestovat právě ten útok, proti kterému validace stojí.
     *
     * @param list<string> $extraEntries       cesty vložené do archivu tak, jak jsou
     * @param list<string> $ustarSplitEntries  cesty uložené přes ustar prefix + name
     */
    private function buildBundle(
        string $work,
        string $version,
        bool $complete = true,
        ?string $stagedVersion = null,
        array $extraEntries = [],
        array $ustarSplitEntries = [],
        ?string $linkEntry = null,
    ): string {
        if (!is_dir($work)) {
            mkdir($work, 0775, true);
        }

        $prefix  = 'myucto-' . $version . '/';
        $entries = [$prefix . 'VERSION' => $stagedVersion ?? $version];
        if ($complete) {
            $entries[$prefix . 'api/vendor/autoload.php'] = '<?php';
            $entries[$prefix . 'api/public/index.php']    = '<?php';
            $entries[$prefix . 'api/src/Bootstrap.php']   = '<?php';
            $entries[$prefix . 'web/dist/index.html']     = '<html></html>';
            $entries[$prefix . 'db/migrations/0001_init.sql'] = 'SELECT 1;';
        }
        foreach ($extraEntries as $name) {
            $entries[$name] = 'x';
        }

        // Kořenový záznam `./` posílá GNU tar jako první — musí projít jako no-op.
        $tar = $this->tarEntry('./', '', '5');
        foreach ($entries as $name => $content) {
            $tar .= $this->tarEntry((string) $name, $content);
        }
        foreach ($ustarSplitEntries as $name) {
            $tar .= $this->tarEntry($name, 'x', '0', splitUstar: true);
        }
        if ($linkEntry !== null) {
            $tar .= $this->tarEntry($linkEntry, '', '2', linkName: '../../../../etc/passwd');
        }
        $tar .= str_repeat("\0", 1024); // dva prázdné bloky = konec archivu

        $path = $work . '/myucto-' . $version . '.tar.gz';
        file_put_contents($path, (string) gzencode($tar, 6));

        return $path;
    }

    /**
     * Jeden ustar záznam: 512B hlavička + obsah zarovnaný na 512B bloky.
     *
     * `$splitUstar` uloží cestu jako `prefix` + `name` (tak, jak to dělá GNU tar
     * u cest nad 100 znaků).
     */
    private function tarEntry(
        string $name,
        string $content,
        string $typeflag = '0',
        bool $splitUstar = false,
        string $linkName = '',
    ): string {
        $prefixField = '';
        if ($splitUstar) {
            $cut = strrpos($name, '/');
            self::assertNotFalse($cut, 'ustar split potřebuje cestu s adresářem');
            $prefixField = substr($name, 0, $cut);
            $name        = substr($name, $cut + 1);
            self::assertLessThanOrEqual(100, strlen($name));
            self::assertLessThanOrEqual(155, strlen($prefixField));
        }

        $header = str_pad($name, 100, "\0");
        $header .= str_pad('0000644', 8, "\0");                    // mode
        $header .= str_pad('0000000', 8, "\0");                    // uid
        $header .= str_pad('0000000', 8, "\0");                    // gid
        $header .= sprintf('%011o', strlen($content)) . "\0";      // size
        $header .= sprintf('%011o', 0) . "\0";                     // mtime
        $header .= '        ';                                     // checksum placeholder
        $header .= $typeflag;                                      // typeflag
        $header .= str_pad($linkName, 100, "\0");                  // linkname
        $header .= "ustar\0" . '00';                               // magic + version
        $header .= str_repeat("\0", 32) . str_repeat("\0", 32);    // uname + gname
        $header .= str_repeat("\0", 8) . str_repeat("\0", 8);      // devmajor + devminor
        $header .= str_pad($prefixField, 155, "\0");               // prefix
        $header .= str_repeat("\0", 12);                           // padding

        self::assertSame(512, strlen($header), 'tar hlavička musí mít 512 B');

        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);

        $padding = (512 - (strlen($content) % 512)) % 512;

        return $header . $content . str_repeat("\0", $padding);
    }

    /** @param list<mixed> $args */
    private function invoke(NativeUpdateService $svc, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($svc, $method);

        return $ref->invokeArgs($svc, $args);
    }

    private function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $content);
    }

    /** @return list<string> */
    private function findByExtension(string $dir, string $ext): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var \SplFileInfo $f */
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.' . $ext)) {
                $found[] = $f->getPathname();
            }
        }

        return $found;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $f */
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
