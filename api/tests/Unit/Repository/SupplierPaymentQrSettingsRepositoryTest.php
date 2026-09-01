<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SupplierPaymentQrSettingsRepositoryTest extends TestCase
{
    public function testUpdateChangesBothIndependentSettingsAndReturnsBeforeState(): void
    {
        $select = $this->createMock(PDOStatement::class);
        $select->expects(self::once())->method('execute')->with([41])->willReturn(true);
        $select->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'invoice_qr_include_due_date' => '1',
            'purchase_invoice_qr_include_due_date' => '0',
        ]);

        $update = $this->createMock(PDOStatement::class);
        $update->expects(self::once())->method('execute')->with([0, 1, 41])->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('prepare')->willReturnCallback(
            static function (string $sql) use ($select, $update): PDOStatement {
                if (str_starts_with(ltrim($sql), 'SELECT')) return $select;
                self::assertStringContainsString(
                    'invoice_qr_include_due_date = ?, purchase_invoice_qr_include_due_date = ?',
                    $sql,
                );
                self::assertStringContainsString('WHERE id = ?', $sql);
                return $update;
            },
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('pdo')->willReturn($pdo);

        $result = (new SupplierPaymentQrSettingsRepository($connection))->update(41, [
            'invoice_qr_include_due_date' => false,
            'purchase_invoice_qr_include_due_date' => true,
        ]);

        self::assertSame([
            'invoice_qr_include_due_date' => true,
            'purchase_invoice_qr_include_due_date' => false,
        ], $result['before']);
        self::assertSame([
            'invoice_qr_include_due_date' => false,
            'purchase_invoice_qr_include_due_date' => true,
        ], $result['settings']);
        self::assertSame([
            'invoice_qr_include_due_date',
            'purchase_invoice_qr_include_due_date',
        ], $result['changed']);
    }

    public function testNoOpUpdateDoesNotIssueAnUpdateStatement(): void
    {
        $select = $this->createMock(PDOStatement::class);
        $select->expects(self::once())->method('execute')->with([41])->willReturn(true);
        $select->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'invoice_qr_include_due_date' => '1',
            'purchase_invoice_qr_include_due_date' => '0',
        ]);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($select);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('pdo')->willReturn($pdo);

        $result = (new SupplierPaymentQrSettingsRepository($connection))->update(41, [
            'invoice_qr_include_due_date' => true,
        ]);

        self::assertSame([], $result['changed']);
        self::assertSame($result['before'], $result['settings']);
    }
}
