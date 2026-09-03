<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Backup;

use MyInvoice\Action\Backup\CompanyBackupJobAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Backup\Company\CompanyBackupCreationException;
use MyInvoice\Service\Backup\Company\CompanyBackupCreator;
use MyInvoice\Service\Backup\Company\CompanyBackupJobManager;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CompanyBackupCreateActionTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const PASSWORD = 'Synthetic-backup-password-42';

    public function testCreateRejectsBearerAndReadOnlyGrantBeforeDispatch(): void
    {
        $creator = $this->createMock(CompanyBackupCreator::class);
        $creator->expects(self::never())->method('create');
        $action = $this->action($creator);

        $bearer = $action->create(
            $this->request(AccessLevel::WRITE, 'bearer'),
            (new ResponseFactory())->createResponse(),
        );
        $readOnly = $action->create(
            $this->request(AccessLevel::READ),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));
        self::assertSame(403, $readOnly->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($readOnly));
    }

    #[DataProvider('invalidBodies')]
    public function testCreateValidatesArchivePasswordBeforeDispatch(
        array $body,
        string $code,
    ): void {
        $creator = $this->createMock(CompanyBackupCreator::class);
        $creator->expects(self::never())->method('create');

        $response = $this->action($creator)->create(
            $this->request(AccessLevel::WRITE)->withParsedBody($body),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame($code, $this->errorCode($response));
        $submittedPassword = $body['password'] ?? null;
        if (is_string($submittedPassword) && $submittedPassword !== '') {
            self::assertStringNotContainsString(
                $submittedPassword,
                (string) $response->getBody(),
            );
        }
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidBodies(): iterable
    {
        yield 'missing password' => [[
            'password_confirm' => self::PASSWORD,
            'step_up_token' => 'proof-token',
        ], 'validation_failed'];
        yield 'non-string password' => [[
            'password' => ['unexpected'],
            'password_confirm' => self::PASSWORD,
            'step_up_token' => 'proof-token',
        ], 'validation_failed'];
        yield 'confirmation mismatch' => [[
            'password' => self::PASSWORD,
            'password_confirm' => 'Different-backup-password-42',
            'step_up_token' => 'proof-token',
        ], 'password_confirmation_mismatch'];
        yield 'weak password' => [[
            'password' => 'short',
            'password_confirm' => 'short',
            'step_up_token' => 'proof-token',
        ], 'archive_password_weak'];
    }

    public function testCreateRequiresPurposeBoundStepUpProof(): void
    {
        $creator = $this->createMock(CompanyBackupCreator::class);
        $creator->expects(self::never())->method('create');

        $response = $this->action($creator)->create(
            $this->request(AccessLevel::WRITE)->withParsedBody([
                'password' => self::PASSWORD,
                'password_confirm' => self::PASSWORD,
            ]),
            (new ResponseFactory())->createResponse(),
        );
        $payload = $this->payload($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('step_up_required', $payload['error']['code'] ?? null);
        self::assertSame(
            MfaStepUpService::OPERATION_COMPANY_BACKUP_CREATE,
            $payload['error']['operation'] ?? null,
        );
    }

    public function testCreateRejectsMissingSessionTokenBeforeDispatch(): void
    {
        $creator = $this->createMock(CompanyBackupCreator::class);
        $creator->expects(self::never())->method('create');

        $response = $this->action($creator)->create(
            $this->request(AccessLevel::WRITE)
                ->withoutAttribute(AuthMiddleware::ATTR_TOKEN)
                ->withParsedBody([
                    'password' => self::PASSWORD,
                    'password_confirm' => self::PASSWORD,
                    'step_up_token' => 'proof-token',
                ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('session_expired', $this->errorCode($response));
    }

    public function testCreateDispatchesTenantJobWithoutReturningPassword(): void
    {
        $creator = $this->createMock(CompanyBackupCreator::class);
        $creator->expects(self::once())
            ->method('create')
            ->with(
                41,
                17,
                'session-token',
                'proof-token',
                self::PASSWORD,
                '127.0.0.1',
                'CompanyBackupCreateActionTest',
            )
            ->willReturn(self::BACKUP_ID);

        $response = $this->action($creator)->create(
            $this->request(AccessLevel::WRITE)->withParsedBody([
                'password' => self::PASSWORD,
                'password_confirm' => self::PASSWORD,
                'step_up_token' => 'proof-token',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        $payload = $this->payload($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(self::BACKUP_ID, $payload['backup_id'] ?? null);
        self::assertSame('queued', $payload['status'] ?? null);
        self::assertStringNotContainsString(
            self::PASSWORD,
            (string) $response->getBody(),
        );
    }

    #[DataProvider('creationErrors')]
    public function testCreateMapsOnlyStableCreationErrors(
        string $errorCode,
        int $status,
    ): void {
        $creator = $this->createStub(CompanyBackupCreator::class);
        $creator->method('create')->willThrowException(
            new CompanyBackupCreationException($errorCode),
        );

        $response = $this->action($creator)->create(
            $this->request(AccessLevel::WRITE)->withParsedBody([
                'password' => self::PASSWORD,
                'password_confirm' => self::PASSWORD,
                'step_up_token' => 'proof-token',
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($errorCode, $this->errorCode($response));
        self::assertStringNotContainsString('internal', (string) $response->getBody());
    }

    /** @return iterable<string,array{string,int}> */
    public static function creationErrors(): iterable
    {
        yield 'registry incomplete' => ['registry_incomplete', 503];
        yield 'active job exists' => ['already_running', 409];
        yield 'job key unavailable' => ['job_secret_key_unavailable', 503];
        yield 'worker unavailable' => ['worker_unavailable', 503];
    }

    private function action(CompanyBackupCreator $creator): CompanyBackupJobAction
    {
        return new CompanyBackupJobAction(
            $this->createStub(CompanyBackupJobManager::class),
            $creator,
            $this->createStub(ActivityLogger::class),
            new IpMatcher(new Config([])),
        );
    }

    private function request(
        AccessLevel $level,
        string $authMethod = 'session',
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                '/api/admin/company-backups',
                ['REMOTE_ADDR' => '127.0.0.1'],
            )
            ->withHeader('User-Agent', 'CompanyBackupCreateActionTest')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'role' => 'readonly',
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41)
            ->withAttribute(
                'auth.effective_role',
                new EffectiveRole(
                    27,
                    'Správce záloh',
                    'staff',
                    true,
                    ['utilities.company_backup' => $level->value],
                ),
            );
    }

    /** @return array<string,mixed> */
    private function payload(ResponseInterface $response): array
    {
        $payload = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        return $payload;
    }

    private function errorCode(ResponseInterface $response): string
    {
        return (string) ($this->payload($response)['error']['code'] ?? '');
    }
}
