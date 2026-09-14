<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\ApprovalTokenLock;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class ApprovalTokenLockTest extends TestCase
{
    public function testAcquisitionTimeoutAndNullFailBeforeCallback(): void
    {
        foreach ([0, null] as $result) {
            $pdo = new ApprovalTokenLockFakePdo('mysql', $result, 1);
            $called = false;
            try {
                ApprovalTokenLock::run($pdo, function () use (&$called): void {
                    $called = true;
                }, 0);
                self::fail('Nedostupný zámek nesmí spustit zápis.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Schvalovací tokeny právě mění', $error->getMessage());
            }
            self::assertFalse($called);
            self::assertSame(0, $pdo->releaseCount);
        }
    }

    public function testReleaseFailureFailsClosed(): void
    {
        $pdo = new ApprovalTokenLockFakePdo('mysql', 1, 0);
        $called = false;
        try {
            ApprovalTokenLock::run($pdo, function () use (&$called): void {
                $called = true;
            });
            self::fail('Úspěšný zápis se nesmí vrátit při selhání RELEASE_LOCK.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Nepodařilo se uvolnit', $error->getMessage());
        }
        self::assertTrue($called);
        self::assertSame(1, $pdo->releaseCount);
    }

    public function testUnknownDriverFailsBeforeCallback(): void
    {
        $pdo = new ApprovalTokenLockFakePdo('pgsql', 1, 1);
        $called = false;
        try {
            ApprovalTokenLock::run($pdo, function () use (&$called): void {
                $called = true;
            });
            self::fail('Neznámý driver nesmí pokračovat bez zámku.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('MariaDB/MySQL nebo SQLite', $error->getMessage());
        }
        self::assertFalse($called);
    }

    public function testSqliteUsesItsOwnWriterSerializationPath(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE synthetic_approval_lock_test (id INTEGER PRIMARY KEY, marker TEXT)');
        $pdo->beginTransaction();
        $result = ApprovalTokenLock::run($pdo, static function () use ($pdo): int {
            $pdo->exec("INSERT INTO synthetic_approval_lock_test (marker) VALUES ('ok')");
            return (int) $pdo->lastInsertId();
        });
        self::assertSame(1, $result);
        $pdo->rollBack();
        $statement = $pdo->query('SELECT COUNT(*) FROM synthetic_approval_lock_test');
        self::assertNotFalse($statement);
        self::assertSame(0, (int) $statement->fetchColumn());
    }
}

final class ApprovalTokenLockFakePdo extends PDO
{
    public int $releaseCount = 0;

    public function __construct(
        private readonly string $driver,
        private readonly mixed $acquireResult,
        private readonly mixed $releaseResult,
    ) {}

    public function getAttribute(int $attribute): mixed
    {
        return $this->driver;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement
    {
        return new ApprovalTokenLockFakeStatement('synthetic_test_database');
    }

    /** @param array<string|int, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement
    {
        if (str_contains($query, 'RELEASE_LOCK')) {
            ++$this->releaseCount;
            return new ApprovalTokenLockFakeStatement($this->releaseResult);
        }
        return new ApprovalTokenLockFakeStatement($this->acquireResult);
    }

    public function inTransaction(): bool
    {
        return false;
    }
}

final class ApprovalTokenLockFakeStatement extends PDOStatement
{
    public function __construct(private readonly mixed $value) {}

    /** @param array<string|int, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}
