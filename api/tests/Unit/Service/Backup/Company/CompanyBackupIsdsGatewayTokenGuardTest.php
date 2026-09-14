<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupIsdsGatewayTokenGuard as Guard;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupIsdsGatewayTokenGuardTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->database->exec('CREATE TABLE isds_gateway_sessions (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL,
            app_token TEXT NOT NULL UNIQUE
        )');
    }

    public function testCollisionAcrossTenantsRejectsWithoutChangingOrDisclosingTarget(): void
    {
        $token = '0000000001';
        $this->database->exec("INSERT INTO isds_gateway_sessions VALUES (11, 90, '0000000001')");
        $source = ['supplier_id' => 7, 'app_token' => $token];

        try {
            Guard::assertAvailable($this->database, Guard::REGISTRY_KEY, $source);
            self::fail('Globální kolize musí obnovu zastavit.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame(Guard::COLLISION, $e->errorCode);
            self::assertSame('isds_gateway_token_collision: table:isds_gateway_sessions.app_token', $e->getMessage());
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertStringNotContainsString('90', $e->getMessage());
        }
        self::assertSame($token, $source['app_token']);
        self::assertSame(1, $this->countSessions());
    }

    public function testFreshCheckDetectsCollisionCreatedAfterPreflight(): void
    {
        $source = ['app_token' => '00000000000000000001'];
        Guard::assertAvailable($this->database, Guard::REGISTRY_KEY, $source);
        $this->database->exec("INSERT INTO isds_gateway_sessions VALUES (12, 91, '00000000000000000001')");

        $this->expectException(CompanyBackupPreflightException::class);
        $this->expectExceptionMessage(Guard::COLLISION);
        Guard::assertAvailable($this->database, Guard::REGISTRY_KEY, $source);
    }

    #[DataProvider('validTokens')]
    public function testValidAsciiTokensRemainStrings(string $token): void
    {
        Guard::assertAvailable($this->database, Guard::REGISTRY_KEY, ['app_token' => $token]);
        self::assertSame(0, $this->countSessions());
    }

    /** @return array<string,array{string}> */
    public static function validTokens(): array
    {
        return [
            'ten digits with leading zeroes' => ['0000000001'],
            'twenty digits with leading zeroes' => ['00000000000000000001'],
            'twenty digits without leading zeroes' => ['12345678901234567890'],
        ];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('invalidTokens')]
    public function testInvalidTokenIsRejectedBeforeDatabaseLookup(array $row): void
    {
        $this->database->exec('DROP TABLE isds_gateway_sessions');
        $this->expectException(CompanyBackupPreflightException::class);
        $this->expectExceptionMessage('isds_gateway_token_invalid: table:isds_gateway_sessions.app_token');
        Guard::assertAvailable($this->database, Guard::REGISTRY_KEY, $row);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidTokens(): array
    {
        return [
            'missing' => [[]],
            'null' => [['app_token' => null]],
            'integer' => [['app_token' => 1234567890]],
            'nine digits' => [['app_token' => '123456789']],
            'twenty-one digits' => [['app_token' => '123456789012345678901']],
            'sign' => [['app_token' => '+1234567890']],
            'whitespace' => [['app_token' => '1234567890 ']],
            'unicode digit' => [['app_token' => '123456789０']],
        ];
    }

    public function testOtherRegistryDoesNotTouchDatabase(): void
    {
        $this->database->exec('DROP TABLE isds_gateway_sessions');
        Guard::assertAvailable($this->database, 'table:documents', ['app_token' => 'bad']);
        $statement = $this->database->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE name = 'isds_gateway_sessions'",
        );
        self::assertNotFalse($statement);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    private function countSessions(): int
    {
        $statement = $this->database->query('SELECT COUNT(*) FROM isds_gateway_sessions');
        self::assertNotFalse($statement);
        return (int) $statement->fetchColumn();
    }
}
