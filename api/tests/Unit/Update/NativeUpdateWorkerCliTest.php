<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;

/**
 * Brána CLI workeru `api/bin/native-update.php`.
 *
 * Na spravované instalaci drží zámek {@see \MyInvoice\Service\System\ManagedModeGuard}
 * self-update, aby si aktualizaci nespustil ZÁKAZNÍK z UI. Zákazník ale na
 * spravovanou instanci nemá podle smlouvy (čl. 6.5) přístup přes SSH ani FTP —
 * shell má jedině provozovatel, a ten musí mít jak dostat na flotilu
 * bezpečnostní opravu. Proto `--operator` zámek vědomě obchází; HTTP endpoint
 * `POST /api/admin/update/trigger` zůstává zamčený beze změny.
 *
 * Testuje se opravdovým procesem — zámek je v tom skriptu, ne ve třídě, takže
 * jinak by se dal tiše ztratit.
 *
 * Návratové kódy: 0 = nasazeno, 1 = selhalo, 2 = chyba použití, 3 = zamčeno.
 */
final class NativeUpdateWorkerCliTest extends TestCase
{
    private const EXIT_USAGE  = 2;
    private const EXIT_LOCKED = 3;

    private static function worker(): string
    {
        return dirname(__DIR__, 3) . '/bin/native-update.php';
    }

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php není k dispozici — worker se bez konfigurace nespustí.');
        }
        if (!is_file(dirname(__DIR__, 3) . '/vendor/autoload.php')) {
            self::markTestSkipped('vendor/autoload.php není k dispozici.');
        }
    }

    protected function tearDown(): void
    {
        putenv('MYINVOICE_APP_MANAGED');
    }

    public function testManagedInstallationRefusesWorkerWithoutOperatorFlag(): void
    {
        [$rc, $out] = $this->runWorker(['--target=9.9.9'], managed: true);

        self::assertSame(self::EXIT_LOCKED, $rc, 'spravovaná instalace musí bez --operator skončit exit 3: ' . $out);
        self::assertMatchesRegularExpression('/spravovan/ui', $out);
        self::assertMatchesRegularExpression('/--operator/u', $out, 'odmítnutí musí říct, jak to má udělat provozovatel');
    }

    /**
     * S `--operator` musí worker branou projít. Dál se schválně nepouští
     * (chybí `--target`, takže spadne na chybu použití) — smyslem testu je
     * zámek, ne nasazení: to by přepsalo soubory repozitáře.
     */
    public function testOperatorFlagPassesTheManagedLock(): void
    {
        [$rc, $out] = $this->runWorker(['--operator'], managed: true);

        self::assertSame(self::EXIT_USAGE, $rc, 'zámek měl být obejit a spadnout až na chybějícím --target: ' . $out);
        self::assertMatchesRegularExpression('/warning/ui', $out, 'obejití zámku musí být v logu hlasité');
        self::assertMatchesRegularExpression('/provozovatel/ui', $out);
        self::assertMatchesRegularExpression('/requested-by=operator/u', $out, 'výchozí původ operátorského běhu');
    }

    /** Na neřízené instalaci `--operator` nic neznamená a NENÍ to chyba. */
    public function testOperatorFlagIsHarmlessOnUnmanagedInstallation(): void
    {
        [$rc, $out] = $this->runWorker(['--operator'], managed: false);

        self::assertSame(self::EXIT_USAGE, $rc, 'bez cílové verze je to chyba použití, ne cokoliv jiného: ' . $out);
        self::assertStringNotContainsString('warning', strtolower($out), 'bez aktivního zámku není co obcházet');
    }

    public function testIncompleteBundlePairIsUsageError(): void
    {
        [$rc, $out] = $this->runWorker([
            '--target=9.9.9',
            '--operator',
            '--bundle-url=https://github.com/radekhulan/myucto/releases/download/v9.9.9/myucto-9.9.9.tar.gz',
        ], managed: true);

        self::assertSame(self::EXIT_USAGE, $rc, 'půlka dvojice je chyba použití: ' . $out);
        self::assertMatchesRegularExpression('/dvojice/u', $out);
    }

    public function testMalformedBundleChecksumIsUsageError(): void
    {
        [$rc, $out] = $this->runWorker([
            '--target=9.9.9',
            '--operator',
            '--bundle-url=https://github.com/radekhulan/myucto/releases/download/v9.9.9/myucto-9.9.9.tar.gz',
            '--bundle-sha256=nesmysl',
        ], managed: true);

        self::assertSame(self::EXIT_USAGE, $rc, 'neplatný otisk je chyba použití: ' . $out);
        self::assertMatchesRegularExpression('/64 hexadecimálních/u', $out);
    }

    public function testBundleUrlOutsideAllowlistIsUsageError(): void
    {
        [$rc, $out] = $this->runWorker([
            '--target=9.9.9',
            '--operator',
            '--bundle-url=https://evil.example.com/myucto-9.9.9.tar.gz',
            '--bundle-sha256=' . str_repeat('ab', 32),
        ], managed: true);

        self::assertSame(self::EXIT_USAGE, $rc, 'cizí host se nesmí dostat ani k stahování: ' . $out);
        self::assertMatchesRegularExpression('/Nepovolený host/u', $out);
    }

    /**
     * @param list<string> $args
     * @return array{0:int, 1:string}
     */
    private function runWorker(array $args, bool $managed): array
    {
        // ENV override `MYINVOICE_APP_MANAGED` je jediná cesta, jak spravovaný
        // režim nasimulovat bez sahání na cfg.php repozitáře.
        putenv($managed ? 'MYINVOICE_APP_MANAGED=1' : 'MYINVOICE_APP_MANAGED=0');

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::worker());
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $out = [];
        $rc  = 0;
        exec($cmd . ' 2>&1', $out, $rc);

        return [$rc, implode("\n", $out)];
    }
}
