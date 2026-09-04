<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupAutoIncrementColumn;
use PHPUnit\Framework\TestCase;

final class CompanyBackupAutoIncrementColumnTest extends TestCase
{
    public function testDerivesRestorableIntegerLimitsFromMariaDbTypes(): void
    {
        self::assertSame(
            127,
            CompanyBackupAutoIncrementColumn::fromDatabaseMetadata(
                'id',
                'tinyint',
                'tinyint(4)',
            )->maximumValue,
        );
        self::assertSame(
            65_535,
            CompanyBackupAutoIncrementColumn::fromDatabaseMetadata(
                'id',
                'smallint',
                'smallint(5) unsigned',
            )->maximumValue,
        );
        self::assertSame(
            4_294_967_295,
            CompanyBackupAutoIncrementColumn::fromDatabaseMetadata(
                'id',
                'int',
                'int unsigned',
            )->maximumValue,
        );
        self::assertSame(
            PHP_INT_MAX,
            CompanyBackupAutoIncrementColumn::fromDatabaseMetadata(
                'id',
                'bigint',
                'bigint(20) unsigned',
            )->maximumValue,
        );
    }

    public function testRejectsNonIntegerAndMalformedColumnTypes(): void
    {
        foreach ([
            ['decimal', 'decimal(20,0)'],
            ['int', 'bigint unsigned'],
            ['int', 'int unsigned unexpected'],
        ] as [$dataType, $columnType]) {
            try {
                CompanyBackupAutoIncrementColumn::fromDatabaseMetadata(
                    'id',
                    $dataType,
                    $columnType,
                );
                self::fail('Neznámý AUTO_INCREMENT typ nesmí vytvořit rozsah.');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }
}
