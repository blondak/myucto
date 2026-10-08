<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bez zapnutí vypínače se instalace chová jako před jeho zavedením: odkaz na
 * web fakturu v e-mailu vzniká stejně (včetně líného založení tokenu), ať cfg
 * sekci `invoices` nemá vůbec, nebo v ní má jen jiné volby. Konfigurace se tu
 * načítá skutečnou cestou Config::load, protože výchozí hodnotu dodávají
 * baseline defaults, ne konstruktor Config.
 */
final class InvoicePublicLinkDefaultTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718';
    private const ENV = 'MYINVOICE_INVOICE_PUBLIC_LINKS';

    private string $tmpDir;
    private string|false $envBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/myinvoice-public-link-default-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        $this->envBackup = getenv(self::ENV);
        putenv(self::ENV);
        unset($_ENV[self::ENV], $_SERVER[self::ENV]);
    }

    protected function tearDown(): void
    {
        if ($this->envBackup !== false) {
            putenv(self::ENV . '=' . $this->envBackup);
        }
        @unlink($this->tmpDir . '/cfg.php');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** @return array<string,array{string}> */
    public static function cfgProvider(): array
    {
        return [
            'bez sekce invoices' => [''],
            'sekce invoices s jinou volbou' => ["'invoices' => ['overdue_includes_today' => true],"],
            'sekce invoices s null' => ["'invoices' => ['public_links' => null],"],
            'sekce invoices s prázdným řetězcem' => ["'invoices' => ['public_links' => ''],"],
        ];
    }

    #[DataProvider('cfgProvider')]
    public function testBezVypnutiVznikaOdkazStejneJakoDriv(string $invoicesSection): void
    {
        $config = $this->loadConfig($invoicesSection);
        self::assertTrue((new InvoicePublicLinkFeature($config))->isEnabled());

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->expects(self::once())->method('ensurePublicToken')->with(6)->willReturn(self::TOKEN);
        $service = new InvoicePublicLinkService($config, $invoices);

        self::assertSame(
            'https://app.example.test/invoice/' . self::TOKEN,
            $service->ensureUrl(['id' => 6, 'status' => 'issued', 'public_token' => null]),
        );
        self::assertSame(
            'https://app.example.test/invoice/' . self::TOKEN,
            $service->ensureUrl(['id' => 5, 'status' => 'sent', 'public_token' => self::TOKEN]),
        );
        self::assertNull($service->ensureUrl(['id' => 7, 'status' => 'draft', 'public_token' => null]));
    }

    private function loadConfig(string $invoicesSection): Config
    {
        file_put_contents($this->tmpDir . '/cfg.php', <<<PHP
<?php
return [
    'app' => ['url' => 'https://app.example.test/'],
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'myinvoice', 'user' => 'root', 'pass' => 'root'],
    'redis' => ['enabled' => false, 'host' => '127.0.0.1', 'port' => 6379],
    {$invoicesSection}
];
PHP);
        return Config::load($this->tmpDir);
    }
}
