<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\Company\CompanyBackupSmallAssetsProjection as Projection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Read-only kontrola fyzického kontraktu v izolované testovací MariaDB. */
#[Group('integration')]
final class CompanyBackupSmallAssetsProjectionSchemaTest extends TestCase
{
    public function testSmallAssetProjectionMatchesPersistedColumnsAndForeignKeys(): void
    {
        if (getenv('MYINVOICE_DB_HOST') !== '127.0.0.1'
            || getenv('MYINVOICE_DB_PORT') !== '33070'
            || getenv('MYINVOICE_DB_NAME') !== 'myucto_codex_test'
            || getenv('MYINVOICE_DB_USER') !== 'root'
            || !is_string(getenv('MYINVOICE_DB_PASS'))
        ) {
            $this->markTestSkipped('Izolovaná testovací MariaDB není nakonfigurována.');
        }
        $password = getenv('MYINVOICE_DB_PASS');
        self::assertIsString($password);
        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=33070;dbname=myucto_codex_test;charset=utf8mb4',
            'root', $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $columns = $pdo->query(
            "SELECT COLUMN_NAME, IS_NULLABLE, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'small_assets'
             ORDER BY ORDINAL_POSITION",
        );
        self::assertNotFalse($columns);
        $runtimeColumns = $columns->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(Projection::dataColumns(), array_column($runtimeColumns, 'COLUMN_NAME'));
        self::assertSame([], array_keys(array_filter(
            $runtimeColumns,
            static fn (array $column): bool =>
                str_contains(strtoupper((string) $column['EXTRA']), 'GENERATED'),
        )));

        $declared = [];
        foreach (Projection::references() as $reference) {
            $column = $reference['columns'][0];
            $declared[$column] = $reference['target'] . ':' . $reference['target_columns'][0];
            $runtime = array_values(array_filter(
                $runtimeColumns,
                static fn (array $row): bool => $row['COLUMN_NAME'] === $column,
            ));
            self::assertCount(1, $runtime);
            self::assertSame(
                $runtime[0]['IS_NULLABLE'] === 'YES' ? [$column] : [],
                $reference['nullable_columns'],
                $column,
            );
            self::assertSame(CompanyBackupReferenceConstraint::Required->value,
                $reference['constraint'], $column);
        }

        $keys = $pdo->query(
            "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'small_assets'
               AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY COLUMN_NAME",
        );
        self::assertNotFalse($keys);
        $runtime = [];
        foreach ($keys->fetchAll(PDO::FETCH_ASSOC) as $key) {
            $runtime[$key['COLUMN_NAME']] =
                'table:' . $key['REFERENCED_TABLE_NAME'] . ':' . $key['REFERENCED_COLUMN_NAME'];
        }
        ksort($runtime);
        ksort($declared);
        self::assertSame($runtime, $declared);
    }
}
