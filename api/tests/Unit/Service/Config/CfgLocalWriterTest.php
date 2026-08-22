<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Config;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Config\CfgLocalWriter;
use PHPUnit\Framework\TestCase;

/**
 * Pokrývá zápis a merge cfg.local.php (auth.require_totp + obecná dot-notation)
 * a hlavně jeho BEZPEČNOST: na spravované instanci leží v tomhle souboru všechna
 * tajemství (pepper, šifrovací klíč, heslo k DB), takže poškozený nebo částečný
 * zápis = mrtvá instance a nedešifrovatelná záloha.
 *
 * Všechna „tajemství" v testech jsou syntetická.
 */
final class CfgLocalWriterTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/myinvoice-cfglocal-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);
        // Minimální cfg.php — Config::load to vyžaduje
        file_put_contents($this->tmpRoot . '/cfg.php', "<?php return ['auth' => ['require_totp' => false]];");
    }

    protected function tearDown(): void
    {
        @chmod($this->tmpRoot, 0700);
        foreach (glob($this->tmpRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpRoot);
    }

    public function testWritesFreshCfgLocalWithDotNotation(): void
    {
        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);

        $path = $this->cfgLocalPath();
        self::assertFileExists($path);

        $loaded = require $path;
        self::assertSame(['auth' => ['require_totp' => true]], $loaded);
    }

    public function testMergesIntoExistingCfgLocal(): void
    {
        file_put_contents(
            $this->cfgLocalPath(),
            "<?php return ['app' => ['debug' => true], 'auth' => ['require_totp' => false]];",
        );

        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);

        $loaded = require $this->cfgLocalPath();
        self::assertTrue($loaded['app']['debug'], 'Existující klíče nesmí být ztraceny');
        self::assertTrue($loaded['auth']['require_totp']);
    }

    public function testSupportsDeepDotPaths(): void
    {
        CfgLocalWriter::setKeys($this->tmpRoot, ['smtp.dkim.enabled' => true]);

        $loaded = require $this->cfgLocalPath();
        self::assertTrue($loaded['smtp']['dkim']['enabled']);
    }

    public function testConfigLoadAppliesCfgLocalOverride(): void
    {
        // cfg.php má require_totp = false; cfg.local.php přepíše na true
        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);

        $config = Config::load($this->tmpRoot);
        self::assertTrue($config->get('auth.require_totp'));
    }

    public function testThrowsWhenExistingCfgLocalDoesNotReturnArray(): void
    {
        file_put_contents($this->cfgLocalPath(), "<?php return 'not-an-array';");

        $this->expectException(\RuntimeException::class);
        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);
    }

    public function testResolveTargetDirFallsBackToRootWhenDataDirUnset(): void
    {
        putenv('MYINVOICE_DATA_DIR');  // ensure unset
        self::assertSame($this->tmpRoot, CfgLocalWriter::resolveTargetDir($this->tmpRoot));
    }

    public function testResolveTargetDirPrefersDataDirWhenSet(): void
    {
        $dataDir = sys_get_temp_dir() . '/myinvoice-cfglocal-datadir-' . bin2hex(random_bytes(4));
        mkdir($dataDir, 0700, true);
        putenv('MYINVOICE_DATA_DIR=' . $dataDir);
        try {
            self::assertSame($dataDir, CfgLocalWriter::resolveTargetDir($this->tmpRoot));
        } finally {
            putenv('MYINVOICE_DATA_DIR');
            @rmdir($dataDir);
        }
    }

    /**
     * Round-trip nad realistickým obsahem spravované instance: vnořená pole do
     * několika úrovní, prázdné pole, null, bool, int, číslo v řetězci, dlouhý
     * base64 klíč, UTF-8 s diakritikou, znaky ' \ $ i \0 uvnitř řetězce a klíč
     * s tečkou v názvu. Po zápisu musí `require` vrátit IDENTICKÉ pole.
     */
    public function testRoundTripsRealisticConfigWithoutLosingAnything(): void
    {
        $path = $this->cfgLocalPath();

        $fixture = [
            'app' => [
                'pepper'                => base64_encode(str_repeat('synthetic-pepper', 8)),
                'secret_encryption_key' => base64_encode(str_repeat("\x01\x02\x03\x04", 16)),
                'debug'                 => false,
                'maintenance'           => true,
                'nothing'               => null,
                'empty_list'            => [],
                'retention_days'        => 365,
                'legacy_port'           => '0025',
                'popis'                 => 'Příliš žluťoučký kůň úpěl ďábelské ódy',
                'specials'              => "apostrof ' zpetne \\ dolar \$ nula \0 konec",
                'deep'                  => ['a' => ['b' => ['c' => ['d' => 'dno']]]],
            ],
            'db' => [
                'host'    => 'localhost',
                'options' => [],
            ],
            'literal.dot' => 'doslovný tečkový klíč zůstane',
            'url_value'   => 'https://instance.example.test/a.b.c',
        ];

        file_put_contents($path, "<?php return " . var_export($fixture, true) . ";\n");

        CfgLocalWriter::setKeys($this->tmpRoot, [
            'auth.require_totp' => true,
            'app.url'           => 'https://instance.example.test/setup.x.y',
        ]);

        $expected                       = $fixture;
        $expected['app']['url']         = 'https://instance.example.test/setup.x.y';
        $expected['auth']               = ['require_totp' => true];

        $loaded = require $path;
        self::assertSame($expected, $loaded);
    }

    /**
     * Tečka se rozpadá jen v KLÍČI, ne v hodnotě — hodnota s tečkou zůstane celá,
     * ale doslovný tečkový klíč přes setKeys() nastavit nejde (vznikne zanoření).
     */
    public function testDotSplitsKeyNotValue(): void
    {
        CfgLocalWriter::setKeys($this->tmpRoot, ['app.url' => 'https://a.b.c/d.e']);

        $loaded = require $this->cfgLocalPath();
        self::assertSame(['app' => ['url' => 'https://a.b.c/d.e']], $loaded);
        self::assertArrayNotHasKey('app.url', $loaded);
    }

    public function testCreatesFileWithOwnerOnlyPermissions(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();

        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);

        $path = $this->cfgLocalPath();
        clearstatcache(true, $path);
        self::assertSame('0600', decoct(fileperms($path) & 0777));
    }

    public function testPreservesPermissionsOfExistingFile(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();

        $path = $this->cfgLocalPath();
        file_put_contents($path, "<?php return ['app' => ['debug' => true]];");
        chmod($path, 0640);

        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);

        clearstatcache(true, $path);
        self::assertSame('0640', decoct(fileperms($path) & 0777));
    }

    /**
     * Nezapisovatelný adresář = dočasný soubor nelze založit → zápis musí SELHAT
     * a původní soubor zůstat bajt po bajtu stejný (starý kód psal rovnou do cíle,
     * takže mu adresářová práva nevadila a soubor přepsal).
     */
    public function testKeepsOriginalFileWhenTargetDirIsNotWritable(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();

        $path = $this->cfgLocalPath();
        file_put_contents($path, "<?php return ['app' => ['pepper' => 'synthetic-pepper-value']];");
        chmod($path, 0600);
        $before = file_get_contents($path);

        chmod($this->tmpRoot, 0500);
        try {
            $thrown = null;
            try {
                CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        } finally {
            chmod($this->tmpRoot, 0700);
        }

        self::assertNotNull($thrown, 'Zápis do nezapisovatelného adresáře musí vyhodit výjimku');
        self::assertSame($before, file_get_contents($path), 'Původní soubor se nesmí změnit');
        self::assertStringNotContainsString('synthetic-pepper-value', $thrown->getMessage());
    }

    /**
     * Hodnota, kterou var_export neumí zapsat (resource), by se starým kódem
     * vyrobila nevalidní PHP soubor — a přepsala by jím tajemství instance.
     * Musí letět výjimka JEŠTĚ PŘED dotykem souboru.
     */
    public function testUnexportableValueDoesNotTouchExistingFile(): void
    {
        $path = $this->cfgLocalPath();
        file_put_contents($path, "<?php return ['app' => ['pepper' => 'synthetic-pepper-value']];");
        $before = file_get_contents($path);

        $handle = fopen('php://memory', 'rb');
        try {
            $thrown = null;
            try {
                CfgLocalWriter::setKeys($this->tmpRoot, ['app.handle' => $handle]);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        } finally {
            fclose($handle);
        }

        self::assertNotNull($thrown, 'Neexportovatelná hodnota musí vyhodit výjimku');
        self::assertSame($before, file_get_contents($path), 'Původní soubor se nesmí změnit');
        self::assertStringContainsString('app.handle', $thrown->getMessage());
        self::assertStringNotContainsString('synthetic-pepper-value', $thrown->getMessage());
    }

    /**
     * Poškozený cfg.local.php se NESMÍ přepsat prázdným polem — to by smazalo
     * tajemství instance. A zpráva výjimky nesmí nést nic z obsahu: ParseError
     * cituje doslovný kus zdrojáku, takže se nesmí propagovat ani jako `previous`.
     */
    public function testCorruptedCfgLocalThrowsAndKeepsFileAndLeaksNothing(): void
    {
        $path   = $this->cfgLocalPath();
        $secret = 'synthetic-pepper-DO-NOT-LOG';
        // Syntaktická chyba hned za hodnotou → PHP ji cituje ve zprávě ParseError.
        file_put_contents($path, "<?php return ['app' => ['pepper' '{$secret}']];");
        $before = file_get_contents($path);

        $thrown = null;
        try {
            CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'Poškozený soubor musí vyhodit výjimku');
        self::assertSame($before, file_get_contents($path), 'Poškozený soubor se nesmí přepsat');
        self::assertStringNotContainsString($secret, $thrown->getMessage());
        self::assertStringNotContainsString($secret, $thrown->getTraceAsString());
        self::assertNull(
            $thrown->getPrevious(),
            'ParseError se nesmí řetězit — jeho zpráva obsahuje kus obsahu souboru',
        );
        self::assertStringContainsString($path, $thrown->getMessage());
    }

    /** Ani u „nevrací pole" nesmí ve zprávě skončit nic z obsahu souboru. */
    public function testExceptionMessageCarriesOnlyPathWhenFileIsNotAnArray(): void
    {
        $path   = $this->cfgLocalPath();
        $secret = 'synthetic-secret-key-value';
        file_put_contents($path, "<?php return '{$secret}';");

        $thrown = null;
        try {
            CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertStringNotContainsString($secret, $thrown->getMessage());
        self::assertStringNotContainsString($secret, $thrown->getTraceAsString());
        self::assertStringContainsString($path, $thrown->getMessage());
        self::assertSame("<?php return '{$secret}';", file_get_contents($path));
    }

    /** Po úspěšném i neúspěšném zápisu nesmí v adresáři zůstat dočasný soubor. */
    public function testDoesNotLeaveTemporaryFilesBehind(): void
    {
        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);
        self::assertSame(['cfg.local.php', 'cfg.php'], $this->dirListing());

        $handle = fopen('php://memory', 'rb');
        try {
            CfgLocalWriter::setKeys($this->tmpRoot, ['app.handle' => $handle]);
            self::fail('Neexportovatelná hodnota musí vyhodit výjimku');
        } catch (\RuntimeException) {
            // očekáváno
        } finally {
            fclose($handle);
        }

        self::assertSame(['cfg.local.php', 'cfg.php'], $this->dirListing());
    }

    /** Cesta, kterou staví i writer — na Windows s obráceným lomítkem. */
    private function cfgLocalPath(): string
    {
        return $this->tmpRoot . DIRECTORY_SEPARATOR . 'cfg.local.php';
    }

    /** @return list<string> */
    private function dirListing(): array
    {
        $files = array_values(array_diff(scandir($this->tmpRoot) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }

    /**
     * Windows práva na souborech nevynucuje (chmod přepíná jen read-only flag)
     * a pod rootem se práva adresáře neuplatní — v obou případech by tvrzení
     * bylo falešně zelené.
     */
    private function skipWhenPermissionsAreNotEnforced(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows nevynucuje POSIX práva souborů.');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Pod rootem se omezení práv neuplatní.');
        }
    }
}
