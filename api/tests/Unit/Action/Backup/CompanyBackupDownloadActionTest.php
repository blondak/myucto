<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Backup;

use MyInvoice\Action\Backup\CompanyBackupDownloadAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadException;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadProvider;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadRangeException;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadStream;
use MyInvoice\Service\Backup\Company\CompanyBackupPreparedDownload;
use MyInvoice\Service\Backup\Company\CompanyBackupStoredArtifact;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CompanyBackupDownloadActionTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const SHA256 =
        '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
    }

    public function testRejectsBearerAndDefaultAccountantBeforeLookingUpJob(): void
    {
        $provider = $this->createMock(CompanyBackupDownloadProvider::class);
        $provider->expects(self::never())->method('prepare');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');
        $action = $this->action($provider, $activity);

        $bearer = $action->download(
            $this->request('accountant', 'bearer'),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );
        $accountant = $action->download(
            $this->request('accountant'),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );

        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));
        self::assertSame(403, $accountant->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($accountant));
    }

    public function testRejectsMalformedIdWithoutLookingUpJob(): void
    {
        $provider = $this->createMock(CompanyBackupDownloadProvider::class);
        $provider->expects(self::never())->method('prepare');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($provider, $activity)->download(
            $this->authorizedRequest(),
            (new ResponseFactory())->createResponse(),
            ['backupId' => '../foreign.zip'],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->errorCode($response));
    }

    #[DataProvider('serviceErrors')]
    public function testMapsSafeServiceErrors(string $errorCode, int $status): void
    {
        $provider = $this->createStub(CompanyBackupDownloadProvider::class);
        $provider->method('prepare')->willThrowException(
            new CompanyBackupDownloadException($errorCode),
        );
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($provider, $activity)->download(
            $this->authorizedRequest(),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($errorCode, $this->errorCode($response));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /** @return iterable<string,array{string,int}> */
    public static function serviceErrors(): iterable
    {
        yield 'missing or foreign' => ['not_found', 404];
        yield 'unfinished' => ['not_ready', 409];
        yield 'expired' => ['artifact_expired', 410];
        yield 'missing physical artifact' => ['artifact_unavailable', 410];
    }

    public function testReturnsRangeErrorWithRepresentationSize(): void
    {
        $provider = $this->createStub(CompanyBackupDownloadProvider::class);
        $provider->method('prepare')->willThrowException(
            new CompanyBackupDownloadRangeException(12_345),
        );
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $response = $this->action($provider, $activity)->download(
            $this->authorizedRequest()->withHeader('Range', 'bytes=99999-'),
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );

        self::assertSame(416, $response->getStatusCode());
        self::assertSame('range_not_satisfiable', $this->errorCode($response));
        self::assertSame('bytes */12345', $response->getHeaderLine('Content-Range'));
        self::assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testStreamsAuthorizedRangeWithImmutableHeadersAndAudit(): void
    {
        $plan = CompanyBackupDownloadPlan::forArchive(
            10,
            self::SHA256,
            'bytes=4-7',
            '"sha256:' . self::SHA256 . '"',
        );
        $artifact = new CompanyBackupStoredArtifact(
            41,
            self::BACKUP_ID,
            'sup-41/' . self::BACKUP_ID . '.zip',
            'myucto-company-backup-' . self::BACKUP_ID . '.zip',
            10,
            self::SHA256,
            3,
        );
        $path = tempnam(sys_get_temp_dir(), 'company-backup-action-');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, '0123456789');
        $stream = CompanyBackupDownloadStream::open($path, $plan);
        $prepared = new CompanyBackupPreparedDownload($artifact, $plan, $stream);

        $provider = $this->createMock(CompanyBackupDownloadProvider::class);
        $provider->expects(self::once())
            ->method('prepare')
            ->with(
                self::BACKUP_ID,
                41,
                'bytes=4-7',
                '"sha256:' . self::SHA256 . '"',
            )
            ->willReturn($prepared);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())
            ->method('log')
            ->with(
                'company_backup.downloaded',
                17,
                'supplier',
                41,
                [
                    'backup_id' => self::BACKUP_ID,
                    'sha256' => self::SHA256,
                    'status_code' => 206,
                    'range_start' => 4,
                    'range_length' => 4,
                ],
                '127.0.0.1',
                'CompanyBackupDownloadActionTest',
                41,
            );

        $request = $this->authorizedRequest()
            ->withHeader('Range', 'bytes=4-7')
            ->withHeader('If-Range', '"sha256:' . self::SHA256 . '"')
            ->withHeader('User-Agent', 'CompanyBackupDownloadActionTest');
        $response = $this->action($provider, $activity)->download(
            $request,
            (new ResponseFactory())->createResponse(),
            ['backupId' => self::BACKUP_ID],
        );

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="' . $artifact->downloadName . '"',
            $response->getHeaderLine('Content-Disposition'),
        );
        self::assertSame('4', $response->getHeaderLine('Content-Length'));
        self::assertSame($plan->etag, $response->getHeaderLine('ETag'));
        self::assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        self::assertSame('bytes 4-7/10', $response->getHeaderLine('Content-Range'));
        self::assertSame(self::SHA256, $response->getHeaderLine('X-Checksum-SHA256'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('4567', (string) $response->getBody());
    }

    private function action(
        CompanyBackupDownloadProvider $provider,
        ActivityLogger $activity,
    ): CompanyBackupDownloadAction {
        return new CompanyBackupDownloadAction(
            $provider,
            $activity,
            new IpMatcher(new Config([])),
        );
    }

    private function authorizedRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->request('readonly')->withAttribute(
            'auth.effective_role',
            new EffectiveRole(
                27,
                'Správce záloh',
                'staff',
                true,
                ['utilities.company_backup' => AccessLevel::READ->value],
            ),
        );
    }

    private function request(
        string $legacyRole,
        string $method = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                '/api/admin/company-backups/' . self::BACKUP_ID . '/download',
                ['REMOTE_ADDR' => '127.0.0.1'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'role' => $legacyRole,
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41);
    }

    private function errorCode(\Psr\Http\Message\ResponseInterface $response): string
    {
        $payload = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        return (string) ($payload['error']['code'] ?? '');
    }
}
