<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\MigrationCompanyLock;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportJobService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class MigrationCompanyLockTest extends TestCase
{
    public function testOnlyOneWorkerCanRunTheSameCompanyAndLockIsReleased(): void
    {
        self::assertTrue(is_subclass_of(StereoNxImportJobService::class, \MyInvoice\Service\Migration\Shared\AbstractImportJobService::class));
        if (!is_file(dirname(__DIR__, 5) . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            self::markTestSkipped('Integration database is not configured.');
        }
        $config = Bootstrap::buildApp()->getContainer()->get(Config::class);
        $firstDb = Connection::withoutSharedTestConnection(static fn (): Connection => new Connection($config));
        $secondDb = Connection::withoutSharedTestConnection(static fn (): Connection => new Connection($config));
        self::assertTrue(str_ends_with((string) $firstDb->pdo()->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $first = new MigrationCompanyLock($firstDb);
        $second = new MigrationCompanyLock($secondDb);
        $supplierId = 1_000_000_000 + random_int(1, 100_000);
        try {
            self::assertTrue($first->acquire(StereoNxImportJobService::SOURCE, $supplierId));
            self::assertFalse($second->acquire(StereoNxImportJobService::SOURCE, $supplierId));
            $first->release(StereoNxImportJobService::SOURCE, $supplierId);
            self::assertTrue($second->acquire(StereoNxImportJobService::SOURCE, $supplierId));
        } finally {
            $second->release(StereoNxImportJobService::SOURCE, $supplierId);
            $first->release(StereoNxImportJobService::SOURCE, $supplierId);
            $firstDb->close();
            $secondDb->close();
        }
    }
}
