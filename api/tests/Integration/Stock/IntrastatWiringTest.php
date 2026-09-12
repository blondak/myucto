<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Intrastat\IntrastatAction;
use MyInvoice\Repository\IntrastatDataSource;
use MyInvoice\Repository\IntrastatRepository;
use MyInvoice\Service\Intrastat\IntrastatService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class IntrastatWiringTest extends StockTestCase
{
    public function testIntrastatServicesResolveFromContainer(): void
    {
        self::assertInstanceOf(IntrastatRepository::class, $this->container->get(IntrastatDataSource::class));
        self::assertInstanceOf(IntrastatService::class, $this->container->get(IntrastatService::class));
        self::assertInstanceOf(IntrastatAction::class, $this->container->get(IntrastatAction::class));
    }
}
