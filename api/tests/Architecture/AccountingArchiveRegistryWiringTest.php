<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Accounting\Archive\AccountingArchiveCatalog;
use MyInvoice\Service\Accounting\Archive\ArchiveRestoreService;
use MyInvoice\Service\Accounting\Archive\ArchiveService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class AccountingArchiveRegistryWiringTest extends TestCase
{
    public function testArchiveServicesDependOnSharedCatalog(): void
    {
        foreach ([ArchiveService::class, ArchiveRestoreService::class] as $service) {
            $constructor = (new ReflectionClass($service))->getConstructor();
            self::assertNotNull($constructor);
            $catalogParameters = array_filter(
                $constructor->getParameters(),
                static function (\ReflectionParameter $parameter): bool {
                    $type = $parameter->getType();
                    return $type instanceof ReflectionNamedType
                        && $type->getName() === AccountingArchiveCatalog::class;
                },
            );
            self::assertCount(1, $catalogParameters, $service . ' musí používat společný katalog.');
        }
    }

    public function testArchiveServicesDoNotKeepCompetingTableCatalogs(): void
    {
        $exportConstants = array_keys((new ReflectionClass(ArchiveService::class))->getConstants());
        self::assertNotContains('DIRECT_TABLES', $exportConstants);
        self::assertNotContains('STOCK_TABLES', $exportConstants);
        self::assertNotContains('EXCLUDED_COLUMNS', $exportConstants);
        self::assertNotContains('SUPPLIER_SECRET_PATTERNS', $exportConstants);

        $restoreConstants = array_keys((new ReflectionClass(ArchiveRestoreService::class))->getConstants());
        self::assertNotContains('RESTORE_ORDER', $restoreConstants);
        self::assertNotContains('NONFK_REFS', $restoreConstants);
        self::assertNotContains('NO_ID_TABLES', $restoreConstants);
    }
}
