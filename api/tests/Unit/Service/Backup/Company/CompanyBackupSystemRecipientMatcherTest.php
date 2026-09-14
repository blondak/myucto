<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupSystemRecipientMatcher as Matcher;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSystemRecipientMatcherTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidSourceRows(): iterable
    {
        yield 'tenant-owned' => [['supplier_id' => 5], 'supplier_id'];
        yield 'missing supplier scope' => [['supplier_id' => 'unset'], 'supplier_id'];
        yield 'invalid id' => [['id' => 0], 'id'];
        yield 'string id' => [['id' => '42'], 'id'];
        yield 'invalid code' => [['code' => 'ZP_TEST'], 'code'];
        yield 'invalid kind' => [['kind' => 'unknown'], 'kind'];
        yield 'invalid box' => [['isds_box_id' => 'invalid-box'], 'isds_box_id'];
        yield 'missing box' => [['isds_box_id' => 'unset'], 'isds_box_id'];
        yield 'invalid business id' => [['business_id' => '123'], 'business_id'];
        yield 'missing business id' => [['business_id' => 'unset'], 'business_id'];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidSourceRows')]
    public function testRejectsInvalidSourceBeforeQuery(array $changes, string $column): void
    {
        $row = self::source();
        foreach ($changes as $key => $value) {
            if ($value === 'unset') {
                unset($row[$key]);
            } else {
                $row[$key] = $value;
            }
        }
        try {
            Matcher::match(new PDO('sqlite::memory:'), $row);
            self::fail('Neplatný zdrojový systémový příjemce musí být odmítnut.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('system_recipient_source_invalid', $e->errorCode);
            self::assertSame(Matcher::REGISTRY_KEY, $e->registryKey);
            self::assertSame($column, $e->column);
            self::assertStringNotContainsString('ZP_TEST', $e->getMessage());
        }
    }

    public function testLockRequiresActiveMysqlTransaction(): void
    {
        $database = new PDO('sqlite::memory:');
        foreach ([false, true] as $transaction) {
            if ($transaction) {
                $database->beginTransaction();
            }
            try {
                Matcher::match($database, self::source(), true);
                self::fail('Zámek mimo aktivní MariaDB transakci musí být odmítnut.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('system_recipient_lock_unavailable', $e->errorCode);
            } finally {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private static function source(): array
    {
        return [
            'id' => 42, 'supplier_id' => null, 'code' => 'zp_test',
            'kind' => 'health_insurer', 'isds_box_id' => 'abc1234',
            'business_id' => '12345678',
        ];
    }
}
