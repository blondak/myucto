<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierDomainRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SupplierDomainRepositoryVerificationTest extends TestCase
{
    public function testStaleChallengeResultIsRejectedByAtomicSnapshotConditions(): void
    {
        $snapshot = $this->domain();
        $rotated = array_replace($snapshot, [
            'status' => 'pending',
            'verification_token' => str_repeat('b', 64),
            'verified_at' => null,
            'updated_at' => '2026-08-18 10:05:00.000000',
        ]);

        $update = $this->createMock(PDOStatement::class);
        $update->expects(self::once())
            ->method('execute')
            ->with([
                'verified',
                1,
                null,
                17,
                41,
                73,
                'portal.synthetic.example',
                str_repeat('a', 64),
                'verified',
                '2026-08-17 09:00:00.000000',
            ])
            ->willReturn(true);
        $update->expects(self::once())->method('rowCount')->willReturn(0);

        $select = $this->createMock(PDOStatement::class);
        $select->expects(self::once())->method('execute')->with([41, 73])->willReturn(true);
        $select->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn($rotated);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnCallback(static function (string $sql) use ($update, $select): PDOStatement {
                if (str_starts_with(ltrim($sql), 'UPDATE supplier_domains')) {
                    self::assertStringContainsString('hostname = ?', $sql);
                    self::assertStringContainsString('verification_token = ?', $sql);
                    self::assertStringContainsString('status = ?', $sql);
                    self::assertStringContainsString('updated_at = ?', $sql);
                    return $update;
                }
                self::assertStringContainsString(
                    'WHERE supplier_id = ? AND id = ?',
                    preg_replace('/\s+/', ' ', $sql) ?? $sql,
                );
                return $select;
            });

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('pdo')->willReturn($pdo);
        $repository = new SupplierDomainRepository($connection, EntityCache::disabled());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Doména se během ověření změnila; spusť kontrolu znovu.');
        $repository->recordVerification(41, 73, $snapshot, true, null, 17);
    }

    /** @return array<string,mixed> */
    private function domain(): array
    {
        return [
            'id' => 73,
            'supplier_id' => 41,
            'hostname' => 'portal.synthetic.example',
            'purpose' => 'portal',
            'status' => 'verified',
            'is_primary' => false,
            'is_primary_portal' => false,
            'is_primary_public' => false,
            'verification_token' => str_repeat('a', 64),
            'verified_at' => '2026-08-17 09:00:00.000000',
            'last_checked_at' => '2026-08-17 09:00:00.000000',
            'verification_error' => null,
            'created_at' => '2026-08-17 08:59:00.000000',
            'updated_at' => '2026-08-17 09:00:00.000000',
        ];
    }
}
