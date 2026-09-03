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
use MyInvoice\Service\Backup\Company\CompanyBackupJobManager;
use MyInvoice\Service\Backup\Company\CompanyBackupCreator;
use MyInvoice\Service\Backup\Company\CompanyBackupManagementException;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CompanyBackupJobActionTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const SHA256 =
        '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testListRejectsBearerAndDefaultAccountantBeforeStoreAccess(): void
    {
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::never())->method('list');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');
        $action = $this->action($manager, $activity);

        $bearer = $action->list(
            $this->request('accountant', 'bearer'),
            (new ResponseFactory())->createResponse(),
        );
        $accountant = $action->list(
            $this->request('accountant'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));
        self::assertSame(403, $accountant->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($accountant));
    }

    public function testListsOnlySanitizedJobsReturnedByDomainService(): void
    {
        $job = $this->job();
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::once())
            ->method('list')
            ->with(41, 20)
            ->willReturn([$job]);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($manager, $activity)->list(
            $this->authorizedRequest(AccessLevel::READ),
            (new ResponseFactory())->createResponse(),
        );
        $payload = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$job], $payload['items'] ?? null);
        self::assertSame(20, $payload['limit'] ?? null);
    }

    public function testMalformedIdIsHiddenBeforeDomainLookup(): void
    {
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::never())->method('detail');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($manager, $activity)->status(
            $this->authorizedRequest(AccessLevel::READ),
            (new ResponseFactory())->createResponse(),
            ['backupId' => '../foreign.zip'],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->errorCode($response));
    }

    public function testReturnsTenantScopedJobStatus(): void
    {
        $job = $this->job();
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::once())
            ->method('detail')
            ->with(self::BACKUP_ID, 41)
            ->willReturn($job);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($manager, $activity)->status(
            $this->authorizedRequest(AccessLevel::READ),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($job, $this->payload($response)['job'] ?? null);
    }

    public function testReadGrantCannotCancelJob(): void
    {
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::never())->method('cancel');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($manager, $activity)->cancel(
            $this->authorizedRequest(AccessLevel::READ),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($response));
    }

    public function testCancelIsTenantScopedIdempotentAndAuditedOnlyWhenChanged(): void
    {
        $job = $this->job([
            'status' => 'snapshotting',
            'cancel_requested' => true,
            'cancellable' => false,
        ]);
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::once())
            ->method('cancel')
            ->with(self::BACKUP_ID, 41)
            ->willReturn(['job' => $job, 'changed' => true]);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())
            ->method('log')
            ->with(
                'company_backup.cancel_requested',
                17,
                'supplier',
                41,
                ['backup_id' => self::BACKUP_ID, 'status' => 'snapshotting'],
                '127.0.0.1',
                'CompanyBackupJobActionTest',
                41,
            );

        $response = $this->action($manager, $activity)->cancel(
            $this->authorizedRequest(AccessLevel::WRITE),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );
        $payload = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['cancel_requested'] ?? false);
        self::assertTrue($payload['changed'] ?? false);
        self::assertSame($job, $payload['job'] ?? null);
    }

    public function testRepeatedCancelDoesNotDuplicateAudit(): void
    {
        $job = $this->job([
            'status' => 'snapshotting',
            'cancel_requested' => true,
            'cancellable' => false,
        ]);
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::once())
            ->method('cancel')
            ->with(self::BACKUP_ID, 41)
            ->willReturn(['job' => $job, 'changed' => false]);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($manager, $activity)->cancel(
            $this->authorizedRequest(AccessLevel::WRITE),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );
        $payload = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['cancel_requested'] ?? false);
        self::assertFalse($payload['changed'] ?? true);
        self::assertSame($job, $payload['job'] ?? null);
    }

    public function testDeletePurgesArtifactAndAuditsItsChecksum(): void
    {
        $job = $this->job([
            'status' => 'expired',
            'artifact_name' => null,
            'size_bytes' => null,
            'sha256' => null,
            'entry_count' => null,
            'expires_at' => null,
            'downloadable' => false,
            'deletable' => false,
        ]);
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::once())
            ->method('deleteArtifact')
            ->with(self::BACKUP_ID, 41)
            ->willReturn([
                'job' => $job,
                'changed' => true,
                'sha256' => self::SHA256,
            ]);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())
            ->method('log')
            ->with(
                'company_backup.deleted',
                17,
                'supplier',
                41,
                ['backup_id' => self::BACKUP_ID, 'sha256' => self::SHA256],
                '127.0.0.1',
                'CompanyBackupJobActionTest',
                41,
            );

        $response = $this->action($manager, $activity)->delete(
            $this->authorizedRequest(AccessLevel::WRITE),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );
        $payload = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['artifact_removed'] ?? false);
        self::assertSame($job, $payload['job'] ?? null);
    }

    public function testRepeatedDeleteKeepsTombstoneWithoutDuplicateAudit(): void
    {
        $job = $this->job([
            'status' => 'expired',
            'artifact_name' => null,
            'size_bytes' => null,
            'sha256' => null,
            'entry_count' => null,
            'expires_at' => null,
            'downloadable' => false,
            'deletable' => false,
        ]);
        $manager = $this->createMock(CompanyBackupJobManager::class);
        $manager->expects(self::once())
            ->method('deleteArtifact')
            ->with(self::BACKUP_ID, 41)
            ->willReturn([
                'job' => $job,
                'changed' => false,
                'sha256' => null,
            ]);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($manager, $activity)->delete(
            $this->authorizedRequest(AccessLevel::WRITE),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );
        $payload = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['artifact_removed'] ?? true);
        self::assertSame($job, $payload['job'] ?? null);
    }

    #[DataProvider('managementErrors')]
    public function testMapsSafeManagementErrors(
        string $operation,
        string $errorCode,
        int $status,
    ): void {
        $manager = $this->createStub(CompanyBackupJobManager::class);
        $method = match ($operation) {
            'status' => 'detail',
            'cancel' => 'cancel',
            'delete' => 'deleteArtifact',
            default => throw new \LogicException('Neznámá testovaná operace.'),
        };
        $manager->method($method)->willThrowException(
            new CompanyBackupManagementException($errorCode),
        );
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');
        $request = $this->authorizedRequest(
            $operation === 'status' ? AccessLevel::READ : AccessLevel::WRITE,
        );
        $action = $this->action($manager, $activity);

        $response = match ($operation) {
            'status' => $action->status(
                $request,
                (new ResponseFactory())->createResponse(),
                ['backupId' => self::BACKUP_ID],
            ),
            'cancel' => $action->cancel(
                $request,
                (new ResponseFactory())->createResponse(),
                ['backupId' => self::BACKUP_ID],
            ),
            'delete' => $action->delete(
                $request,
                (new ResponseFactory())->createResponse(),
                ['backupId' => self::BACKUP_ID],
            ),
        };

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($errorCode, $this->errorCode($response));
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function managementErrors(): iterable
    {
        yield 'foreign job' => ['status', 'not_found', 404];
        yield 'terminal cancellation' => ['cancel', 'not_cancellable', 409];
        yield 'active deletion' => ['delete', 'not_deletable', 409];
        yield 'locked artifact' => ['delete', 'artifact_delete_deferred', 409];
        yield 'concurrent state change' => ['delete', 'state_conflict', 409];
    }

    private function action(
        CompanyBackupJobManager $manager,
        ActivityLogger $activity,
    ): CompanyBackupJobAction {
        return new CompanyBackupJobAction(
            $manager,
            $this->createStub(CompanyBackupCreator::class),
            $activity,
            new IpMatcher(new Config([])),
        );
    }

    private function authorizedRequest(AccessLevel $level): ServerRequestInterface
    {
        return $this->request('readonly')->withAttribute(
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

    private function request(
        string $legacyRole,
        string $method = 'session',
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                '/api/admin/company-backups',
                [
                    'REMOTE_ADDR' => '127.0.0.1',
                    'HTTP_USER_AGENT' => 'CompanyBackupJobActionTest',
                ],
            )
            ->withHeader('User-Agent', 'CompanyBackupJobActionTest')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'role' => $legacyRole,
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function job(array $overrides = []): array
    {
        return array_replace([
            'backup_id' => self::BACKUP_ID,
            'status' => 'completed',
            'processed_steps' => 7,
            'total_steps' => 7,
            'cancel_requested' => false,
            'error_code' => null,
            'artifact_name' => 'myucto-company-backup-' . self::BACKUP_ID . '.zip',
            'size_bytes' => 12_345,
            'sha256' => self::SHA256,
            'entry_count' => 8,
            'expires_at' => '2026-09-03T12:00:00.000000+02:00',
            'started_at' => '2026-09-02T12:00:01.000000+02:00',
            'finished_at' => '2026-09-02T12:05:00.000000+02:00',
            'created_at' => '2026-09-02T12:00:00.000000+02:00',
            'updated_at' => '2026-09-02T12:05:00.000000+02:00',
            'downloadable' => true,
            'cancellable' => false,
            'deletable' => true,
        ], $overrides);
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
        $payload = $this->payload($response);
        return (string) ($payload['error']['code'] ?? '');
    }
}
