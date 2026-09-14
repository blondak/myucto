<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\ChangePasswordAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Rozpracované (nezaktivované) TOTP zřízení je vázané na autorizaci, která ho
 * spustila. Změna hesla je reakce na podezření z kompromitace, takže pending
 * secret se zahodí spolu se sessions; aktivní faktor zůstává nedotčený.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChangePasswordClearsPendingTotpTest extends TestCase
{
    public function testPendingTotpSecretIsDiscardedOnPasswordChange(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password_hash TEXT, totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO users VALUES (17, 'old-hash', 'enc:PENDING', 0)");
        $pdo->exec("INSERT INTO users VALUES (18, 'old-hash', 'enc:ACTIVE', 1)");

        $response = $this->action($pdo)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/password')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
                ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
                ->withParsedBody([
                    'current_password' => 'Old-synthetic-password-1',
                    'new_password' => 'New-synthetic-password-1',
                    'new_password_confirm' => 'New-synthetic-password-1',
                ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($pdo->query('SELECT totp_secret FROM users WHERE id = 17')->fetchColumn() ?: null);
        self::assertSame('enc:ACTIVE', $pdo->query('SELECT totp_secret FROM users WHERE id = 18')->fetchColumn());
    }

    public function testActiveTotpSecretSurvivesPasswordChange(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password_hash TEXT, totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO users VALUES (17, 'old-hash', 'enc:ACTIVE', 1)");

        $response = $this->action($pdo)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/password')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
                ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
                ->withParsedBody([
                    'current_password' => 'Old-synthetic-password-1',
                    'new_password' => 'New-synthetic-password-1',
                    'new_password_confirm' => 'New-synthetic-password-1',
                ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('enc:ACTIVE', $pdo->query('SELECT totp_secret FROM users WHERE id = 17')->fetchColumn());
    }

    private function action(\PDO $pdo): ChangePasswordAction
    {
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->method('verify')->willReturn(true);
        $hasher->method('hash')->willReturn('new-hash');
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('destroyAllForUser')->willReturn(0);

        return new ChangePasswordAction(
            $db,
            $hasher,
            $sessions,
            $this->createMock(ActivityLogger::class),
            new IpMatcher(),
        );
    }
}
