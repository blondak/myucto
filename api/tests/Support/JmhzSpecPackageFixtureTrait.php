<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzSpecPackageRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

trait JmhzSpecPackageFixtureTrait
{
    protected function installDefaultJmhzSpecPackage(Connection $db): int
    {
        $manifest = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
        );

        return (new JmhzSpecPackageRepository($db))->install($manifest);
    }
}
