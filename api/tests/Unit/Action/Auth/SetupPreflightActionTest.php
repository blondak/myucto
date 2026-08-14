<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\SetupPreflightAction;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Service\System\EnvironmentCheckService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SetupPreflightActionTest extends TestCase
{
    private function invoke(bool $needsSetup, EnvironmentCheckService $environment): array
    {
        $lock = $this->createMock(FirstRunLockMiddleware::class);
        $lock->method('needsSetup')->willReturn($needsSetup);

        $response = (new SetupPreflightAction($lock, $environment))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/setup-preflight'),
            (new ResponseFactory())->createResponse(),
        );

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testReportsTheEnvironmentWhileTheInstallationHasNoAdmin(): void
    {
        $environment = $this->createMock(EnvironmentCheckService::class);
        $environment->expects(self::once())->method('preflight')->willReturn([
            'generated_at' => '2026-08-14T10:00:00+02:00',
            'environment'  => 'docker',
            'summary'      => ['status' => 'warn', 'ok' => 12, 'warn' => 1, 'fail' => 0, 'skip' => 0],
            'checks'       => [['id' => 'opcache', 'status' => 'warn', 'actual' => 'vypnuto', 'expected' => 'zapnuto', 'manual' => '03_Instalace_Docker', 'meta' => []]],
        ]);

        [$status, $body] = $this->invoke(true, $environment);

        self::assertSame(200, $status);
        self::assertSame('warn', $body['summary']['status']);
        self::assertSame('docker', $body['environment']);
    }

    public function testRefusesOnceTheSetupIsDone(): void
    {
        // Po setupu je totéž (a víc) za adminím přihlášením v Systém → Diagnostika,
        // takže se prostředí nesmí měřit ani vracet.
        $environment = $this->createMock(EnvironmentCheckService::class);
        $environment->expects(self::never())->method('preflight');

        [$status, $body] = $this->invoke(false, $environment);

        self::assertSame(409, $status);
        self::assertSame('setup_already_done', $body['error']['code']);
    }
}
