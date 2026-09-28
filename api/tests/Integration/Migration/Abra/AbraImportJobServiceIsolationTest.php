<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Abra;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbstractMigrationImportRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Migration\Abra\AbraImportJobService;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class AbraImportJobServiceIsolationTest extends TestCase
{
    public function testProgressAndCancellationUseSeparateDatabaseSession(): void
    {
        if (!is_file(dirname(__DIR__, 5) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $service = Bootstrap::buildContainer()->get(AbraImportJobService::class);
        $jobs = $this->property(AbstractImportJobService::class, 'jobs')->getValue($service);
        $runs = $this->property(AbstractImportJobService::class, 'runs')->getValue($service);

        self::assertInstanceOf(ImportJobRepository::class, $jobs);
        self::assertInstanceOf(AbstractMigrationImportRepository::class, $runs);
        $jobDb = $this->property(ImportJobRepository::class, 'db')->getValue($jobs);
        $runDb = $this->property(AbstractMigrationImportRepository::class, 'db')->getValue($runs);
        self::assertInstanceOf(Connection::class, $jobDb);
        self::assertInstanceOf(Connection::class, $runDb);
        self::assertNotSame($runDb->pdo(), $jobDb->pdo());
    }

    private function property(string $class, string $name): \ReflectionProperty
    {
        $property = new \ReflectionProperty($class, $name);
        return $property;
    }
}
