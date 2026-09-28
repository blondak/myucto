<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraException;
use MyInvoice\Service\Migration\Abra\AbraRequestBudget;
use PHPUnit\Framework\TestCase;

final class AbraRequestBudgetTest extends TestCase
{
    private string $dir;
    private string|false $previous;
    protected function setUp(): void
    {
        $this->previous = getenv('MYINVOICE_DATA_DIR');
        $this->dir = sys_get_temp_dir() . '/abra-budget-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        putenv('MYINVOICE_DATA_DIR=' . $this->dir);
    }
    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $this->previous);
        foreach (glob($this->dir . '/storage/abra-flexi/request-budget/*.json') ?: [] as $file) unlink($file);
        foreach (['/storage/abra-flexi/request-budget', '/storage/abra-flexi', '/storage', ''] as $suffix) @rmdir($this->dir . $suffix);
    }
    public function testBudgetIsSharedAcrossClientInstancesAndHostCasing(): void
    {
        (new AbraRequestBudget(2))->reserve('EXAMPLE.com');
        (new AbraRequestBudget(2))->reserve('example.com');
        try {
            (new AbraRequestBudget(2))->reserve('example.com');
            self::fail('Daily budget exceeded.');
        } catch (AbraException $e) { self::assertSame('request_budget', $e->errorCode); }
        $files = glob($this->dir . '/storage/abra-flexi/request-budget/*.json');
        self::assertCount(1, $files);
        self::assertStringNotContainsString('example.com', basename($files[0]));
        self::assertSame(2, json_decode(file_get_contents($files[0]), true)['count']);
    }
    public function testRateLimitCooldownSurvivesNewClientInstance(): void
    {
        (new AbraRequestBudget())->cooldown('example.com');
        try {
            (new AbraRequestBudget())->reserve('example.com');
            self::fail('Cooldown bypassed.');
        } catch (AbraException $e) { self::assertSame('rate_limited', $e->errorCode); }
    }
    public function testOperatorCanSetDailyBudgetForKnownLicence(): void
    {
        $previous = getenv('MYINVOICE_ABRA_DAILY_REQUEST_LIMIT');
        putenv('MYINVOICE_ABRA_DAILY_REQUEST_LIMIT=3');
        try {
            for ($i = 0; $i < 3; $i++) (new AbraRequestBudget())->reserve('configured.example');
            try {
                (new AbraRequestBudget())->reserve('configured.example');
                self::fail('Configured daily budget exceeded.');
            } catch (AbraException $error) {
                self::assertSame('request_budget', $error->errorCode);
            }
        } finally {
            putenv($previous === false ? 'MYINVOICE_ABRA_DAILY_REQUEST_LIMIT'
                : 'MYINVOICE_ABRA_DAILY_REQUEST_LIMIT=' . $previous);
        }
    }
}
