<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Settings;

use MyInvoice\Action\Settings\SupplierDomainAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpProof;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\DomainVerificationService;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Tenant\SupplierDomainRegistrationService;
use MyInvoice\Service\Tenant\SupplierDomainVerificationService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SupplierDomainActivationTest extends TestCase
{
    public function testExplicitVerificationUsesTheSameCurrentSnapshotWorkflow(): void
    {
        $stored = $this->domain();
        $fresh = array_replace($stored, [
            'verified_at' => '2026-08-18 10:05:00.000000',
            'last_checked_at' => '2026-08-18 10:05:00.000000',
            'updated_at' => '2026-08-18 10:05:00.000000',
        ]);

        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->method('findOwned')->willReturnOnConsecutiveCalls($stored, $fresh);
        $domains->expects(self::once())
            ->method('recordVerification')
            ->with(41, 73, $stored, true, null, 17);
        $domains->expects(self::never())->method('activate');

        $verification = $this->createMock(DomainVerificationService::class);
        $verification->expects(self::once())
            ->method('verify')
            ->with($stored)
            ->willReturn(['verified' => true, 'dns' => true, 'https' => true, 'error' => null]);

        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::never())->method('consume');

        $response = $this->action($domains, $verification, $stepUp)->verify(
            $this->request(),
            (new ResponseFactory())->createResponse(),
            ['id' => 73],
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['checks']['verified']);
        self::assertSame('2026-08-18 10:05:00.000000', $body['domain']['verified_at']);
    }

    public function testStaleStoredSuccessCannotActivateAfterFreshVerificationFails(): void
    {
        $stored = $this->domain();
        $failed = array_replace($stored, [
            'status' => 'verification_failed',
            'verified_at' => null,
            'last_checked_at' => '2026-08-18 10:05:00.000000',
            'verification_error' => 'DNS TXT challenge nebyla nalezena.',
        ]);

        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->method('findOwned')->willReturnOnConsecutiveCalls($stored, $stored, $failed);
        $domains->expects(self::once())
            ->method('recordVerification')
            ->with(
                41,
                73,
                $stored,
                false,
                'DNS TXT challenge nebyla nalezena.',
                17,
            );
        $domains->expects(self::never())->method('activate');

        $verification = $this->createMock(DomainVerificationService::class);
        $verification->expects(self::once())
            ->method('verify')
            ->with($stored)
            ->willReturn([
                'verified' => false,
                'dns' => false,
                'https' => false,
                'error' => 'DNS TXT challenge nebyla nalezena.',
            ]);

        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::once())
            ->method('consume')
            ->with('fresh-proof', 17, 'session-token', 'domain.activate:73');

        $response = $this->action($domains, $verification, $stepUp)->activate(
            $this->request(),
            (new ResponseFactory())->createResponse(),
            ['id' => 73],
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('domain_not_verified', $body['error']['code']);
        self::assertSame('DNS TXT challenge nebyla nalezena.', $body['error']['message']);
    }

    public function testActivationUsesFreshSuccessForTheSamePersistedDomain(): void
    {
        $stored = $this->domain();
        $fresh = array_replace($stored, [
            'verified_at' => '2026-08-18 10:05:00.000000',
            'last_checked_at' => '2026-08-18 10:05:00.000000',
            'updated_at' => '2026-08-18 10:05:00.000000',
        ]);
        $active = array_replace($fresh, [
            'status' => 'active',
            'is_primary' => true,
            'is_primary_portal' => true,
        ]);
        $events = [];

        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->method('findOwned')->willReturnOnConsecutiveCalls($stored, $stored, $fresh);
        $domains->expects(self::once())
            ->method('recordVerification')
            ->with(41, 73, $stored, true, null, 17)
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'record';
            });
        $domains->expects(self::once())
            ->method('activate')
            ->with(41, 73, true, 17)
            ->willReturnCallback(static function () use (&$events, $active): array {
                $events[] = 'activate';
                return $active;
            });

        $verification = $this->createMock(DomainVerificationService::class);
        $verification->expects(self::once())
            ->method('verify')
            ->with($stored)
            ->willReturnCallback(static function () use (&$events): array {
                $events[] = 'verify';
                return ['verified' => true, 'dns' => true, 'https' => true, 'error' => null];
            });

        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::once())
            ->method('consume')
            ->willReturnCallback(static function () use (&$events): MfaStepUpProof {
                $events[] = 'step_up';
                return new MfaStepUpProof(17, 'domain.activate:73', 'totp', null);
            });

        $response = $this->action($domains, $verification, $stepUp)->activate(
            $this->request(),
            (new ResponseFactory())->createResponse(),
            ['id' => 73],
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('active', $body['status']);
        self::assertSame('portal.synthetic.example', $body['hostname']);
        self::assertSame(['step_up', 'verify', 'record', 'activate'], $events);
    }

    public function testChangedDomainSnapshotCannotBeVerifiedOrActivated(): void
    {
        $stored = $this->domain();
        $changed = array_replace($stored, [
            'status' => 'pending',
            'verification_token' => str_repeat('b', 64),
            'verified_at' => null,
            'updated_at' => '2026-08-18 10:05:00.000000',
        ]);

        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->method('findOwned')->willReturnOnConsecutiveCalls($stored, $changed);
        $domains->expects(self::never())->method('recordVerification');
        $domains->expects(self::never())->method('activate');

        $verification = $this->createMock(DomainVerificationService::class);
        $verification->expects(self::never())->method('verify');

        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::once())->method('consume');

        $response = $this->action($domains, $verification, $stepUp)->activate(
            $this->request(),
            (new ResponseFactory())->createResponse(),
            ['id' => 73],
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('domain_state_conflict', $body['error']['code']);
        self::assertSame(
            'Doména se před ověřením změnila; spusť kontrolu znovu.',
            $body['error']['message'],
        );
    }

    public function testDomainChangeDuringFreshCheckCannotActivate(): void
    {
        $stored = $this->domain();

        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->method('findOwned')->willReturn($stored);
        $domains->expects(self::once())
            ->method('recordVerification')
            ->with(41, 73, $stored, true, null, 17)
            ->willThrowException(new \DomainException(
                'Doména se během ověření změnila; spusť kontrolu znovu.',
            ));
        $domains->expects(self::never())->method('activate');

        $verification = $this->createMock(DomainVerificationService::class);
        $verification->expects(self::once())
            ->method('verify')
            ->with($stored)
            ->willReturn(['verified' => true, 'dns' => true, 'https' => true, 'error' => null]);

        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::once())->method('consume');

        $response = $this->action($domains, $verification, $stepUp)->activate(
            $this->request(),
            (new ResponseFactory())->createResponse(),
            ['id' => 73],
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('domain_state_conflict', $body['error']['code']);
        self::assertSame(
            'Doména se během ověření změnila; spusť kontrolu znovu.',
            $body['error']['message'],
        );
    }

    public function testActivationRechecksCanonicalHostnameAfterConfigurationChange(): void
    {
        $stored = $this->domain();
        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->expects(self::once())->method('findOwned')->willReturn($stored);
        $domains->expects(self::never())->method('recordVerification');
        $domains->expects(self::never())->method('activate');

        $verification = $this->createMock(DomainVerificationService::class);
        $verification->expects(self::never())->method('verify');
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->expects(self::never())->method('consume');

        $response = $this->action(
            $domains,
            $verification,
            $stepUp,
            'https://portal.synthetic.example',
        )->activate(
            $this->request(),
            (new ResponseFactory())->createResponse(),
            ['id' => 73],
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('canonical_hostname_conflict', $body['error']['code']);
    }

    private function action(
        SupplierDomainRepository $domains,
        DomainVerificationService $verification,
        MfaStepUpService $stepUp,
        string $appUrl = 'https://app.synthetic.example',
    ): SupplierDomainAction {
        return new SupplierDomainAction(
            $domains,
            new SupplierDomainRegistrationService(
                $domains,
                new HostnameNormalizer(),
                new Config(['app' => ['url' => $appUrl]]),
            ),
            new SupplierDomainVerificationService($domains, $verification),
            $stepUp,
            $this->createStub(ActivityLogger::class),
            $this->createStub(IpMatcher::class),
            new TenantDomainFeature(new Config(['domains' => ['enabled' => true]])),
        );
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/settings/domains/73/activate')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41)
            ->withParsedBody(['step_up_token' => 'fresh-proof', 'is_primary' => true]);
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
