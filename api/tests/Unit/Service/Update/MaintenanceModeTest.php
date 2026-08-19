<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Update;

use MyInvoice\Service\Update\MaintenanceMode;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/maintenance-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir . '/storage', 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink(MaintenanceMode::path($this->stateDir));
        @rmdir($this->stateDir . '/storage');
        @rmdir($this->stateDir);
    }

    public function testBeginWritesFlagWithFutureExpiry(): void
    {
        MaintenanceMode::begin($this->stateDir, 'MyÚčto.cz', '5.16.0');

        $flag = json_decode((string) file_get_contents(MaintenanceMode::path($this->stateDir)), true);

        self::assertSame('MyÚčto.cz', $flag['product']);
        self::assertSame('5.16.0', $flag['target']);
        self::assertGreaterThan(time(), strtotime($flag['expires_at']));
    }

    /**
     * Dlouhý přechod hlásí průběh opakovaně a značku tím prodlužuje. Začátek
     * okna ale musí zůstat původní — jinak by se z „běží 20 minut" stalo
     * pokaždé „běží teď" a expirace by přestala mít smysl.
     */
    public function testBeginKeepsOriginalStartWhenRefreshed(): void
    {
        MaintenanceMode::begin($this->stateDir, 'MyÚčto.cz', '5.16.0');
        $first = json_decode((string) file_get_contents(MaintenanceMode::path($this->stateDir)), true);

        MaintenanceMode::begin($this->stateDir, 'MyÚčto.cz', '5.16.0');
        $second = json_decode((string) file_get_contents(MaintenanceMode::path($this->stateDir)), true);

        self::assertSame($first['started_at'], $second['started_at']);
    }

    public function testEndRemovesFlag(): void
    {
        MaintenanceMode::begin($this->stateDir, 'MyÚčto.cz', '5.16.0');
        MaintenanceMode::end($this->stateDir);

        self::assertFileDoesNotExist(MaintenanceMode::path($this->stateDir));
    }

    /** Úklid nesmí spadnout, když značka není — `finally` ho volá i tam. */
    public function testEndIsIdempotent(): void
    {
        MaintenanceMode::end($this->stateDir);
        MaintenanceMode::end($this->stateDir);

        self::assertFileDoesNotExist(MaintenanceMode::path($this->stateDir));
    }
}
