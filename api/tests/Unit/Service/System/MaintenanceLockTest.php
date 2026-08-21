<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\System\MaintenanceLock;
use PHPUnit\Framework\TestCase;

/**
 * Kontrakt zámku údržby je DOHODNUTÝ S PROVOZOVATELEM hostingu, ne náš návrh.
 * Každý test tady hlídá jednu větu té dohody — proto jsou formulované jako
 * „prázdný soubor stačí", ne jako „služba parsuje JSON".
 */
final class MaintenanceLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/myucto-maintenance-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function lock(string $file, mixed $retryAfter = null): MaintenanceLock
    {
        $maintenance = ['lock_file' => $file];
        if ($retryAfter !== null) {
            $maintenance['retry_after'] = $retryAfter;
        }

        return new MaintenanceLock(new Config(['maintenance' => $maintenance]));
    }

    private function path(string $name = 'maintenance.lock'): string
    {
        return $this->dir . '/' . $name;
    }

    public function testDefaultPathIsStorageMaintenanceLockNextToTheDataDir(): void
    {
        $lock = new MaintenanceLock(new Config([]));

        self::assertSame(
            RuntimePaths::storage(MaintenanceLock::DEFAULT_FILENAME),
            $lock->path(),
            'Výchozí cesta musí zůstat ${MYINVOICE_DATA_DIR}/storage/maintenance.lock — '
            . 'provozovatel zakládá soubor tam a nikam jinam se nedívá.',
        );
    }

    public function testRelativeConfiguredPathResolvesAgainstDataRootNotCwd(): void
    {
        $lock = $this->lock('storage/custom.lock');

        self::assertSame(
            RuntimePaths::base() . '/storage/custom.lock',
            $lock->path(),
            'Web a cron mají různý CWD; údržba nesmí platit jen pro jeden z nich.',
        );
    }

    /** Provozovatel zakládá soubor příkazem `touch` — obsah je volitelný. */
    public function testEmptyFileIsEnoughToActivateMaintenance(): void
    {
        $lock = $this->lock($this->path());
        self::assertFalse($lock->isActive());

        touch($this->path());

        self::assertTrue($lock->isActive());
        self::assertSame(
            ['reason' => null, 'since' => null, 'by' => null],
            $lock->details(),
        );
        self::assertSame(MaintenanceLock::MESSAGE, $lock->message());
    }

    public function testMalformedJsonDoesNotThrowAndStillMeansMaintenance(): void
    {
        file_put_contents($this->path(), '{"reason": "chybí uzávěrka');
        $lock = $this->lock($this->path());

        self::assertTrue($lock->isActive());
        self::assertSame(
            ['reason' => null, 'since' => null, 'by' => null],
            $lock->details(),
            'Nevalidní JSON je legitimní stav — kód na něm nesmí padnout ani nic dovozovat.',
        );
        self::assertSame(MaintenanceLock::MESSAGE, $lock->message());
    }

    public function testJsonPayloadIsReadWhenPresent(): void
    {
        file_put_contents($this->path(), json_encode([
            'reason' => 'Plánovaný upgrade databáze',
            'since'  => '2026-08-21T02:00:00+02:00',
            'by'     => 'ops',
        ], JSON_UNESCAPED_UNICODE));
        $lock = $this->lock($this->path());

        self::assertSame([
            'reason' => 'Plánovaný upgrade databáze',
            'since'  => '2026-08-21T02:00:00+02:00',
            'by'     => 'ops',
        ], $lock->details());
        self::assertStringContainsString('Plánovaný upgrade databáze', $lock->message());
    }

    /**
     * Odstranění souboru = konec údržby, bez restartu čehokoli. Bez
     * `clearstatcache()` by stat cache (a u neexistujícího souboru i negativní
     * záznam v realpath cache) držela starou odpověď.
     */
    public function testRemovingTheFileEndsMaintenanceImmediately(): void
    {
        $lock = $this->lock($this->path());

        // Nejdřív se na neexistující soubor zeptáme — tím se naplní negativní cache.
        self::assertFalse($lock->isActive());
        touch($this->path());
        self::assertTrue($lock->isActive(), 'Čerstvě založený zámek musí platit hned.');

        unlink($this->path());
        self::assertFalse($lock->isActive(), 'Odstranění zámku musí platit hned, bez restartu.');
    }

    public function testRetryAfterDefaultsAndClamps(): void
    {
        self::assertSame(
            MaintenanceLock::DEFAULT_RETRY_AFTER,
            (new MaintenanceLock(new Config([])))->retryAfter(),
        );
        self::assertSame(600, $this->lock($this->path(), 600)->retryAfter());
        self::assertSame(600, $this->lock($this->path(), '600')->retryAfter());
        self::assertSame(
            MaintenanceLock::DEFAULT_RETRY_AFTER,
            $this->lock($this->path(), 'nonsense')->retryAfter(),
            'Nesmyslná hodnota nesmí vyrobit Retry-After: 0 (= smyčka okamžitých pokusů).',
        );
        self::assertSame(1, $this->lock($this->path(), -5)->retryAfter());
        self::assertSame(86400, $this->lock($this->path(), 999999)->retryAfter());
    }

    /**
     * `reason` píše provozovatel a propisuje se do veřejné 503 odpovědi.
     * CR/LF by se propsalo i do hlaviček, proto se řídicí znaky zahazují.
     */
    public function testReasonIsStrippedOfControlCharactersAndTruncated(): void
    {
        file_put_contents($this->path(), json_encode([
            'reason' => "Upgrade\r\nX-Injected: 1 " . str_repeat('a', 400),
        ]));
        $reason = (string) $this->lock($this->path())->details()['reason'];

        self::assertStringNotContainsString("\r", $reason);
        self::assertStringNotContainsString("\n", $reason);
        self::assertSame(200, mb_strlen($reason));
    }

    public function testNonStringJsonRootIsTolerated(): void
    {
        file_put_contents($this->path(), '"just a string"');

        self::assertSame(
            ['reason' => null, 'since' => null, 'by' => null],
            $this->lock($this->path())->details(),
        );
    }
}
