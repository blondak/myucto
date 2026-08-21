<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Settings;

use MyInvoice\Action\Settings\SupplierDomainAction;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\DomainVerificationService;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\SupplierDomainRegistrationService;
use MyInvoice\Service\Tenant\SupplierDomainVerificationService;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * S vypnutou featurou nesmí správa domén existovat ani přes přímé volání API.
 * Kdyby šla obejít schované UI, firma by si doménu aktivovala a resolver by ji
 * (vypnutý) vyhodnotil jako canonical — hostname by přestal být hranicí tenanta.
 */
final class SupplierDomainFeatureDisabledTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function handlerProvider(): array
    {
        return [
            'výpis'     => ['list'],
            'založení'  => ['create'],
            'úprava'    => ['update'],
            'rotace'    => ['rotateChallenge'],
            'ověření'   => ['verify'],
            'aktivace'  => ['activate'],
            'vypnutí'   => ['disable'],
            'smazání'   => ['delete'],
        ];
    }

    #[DataProvider('handlerProvider')]
    public function testEveryHandlerIsNotFoundWhileFeatureIsDisabled(string $handler): void
    {
        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->expects(self::never())->method(self::anything());
        $action = $this->action($domains, false);

        $response = in_array($handler, ['list', 'create'], true)
            ? $action->{$handler}($this->request(), (new ResponseFactory())->createResponse())
            : $action->{$handler}($this->request(), (new ResponseFactory())->createResponse(), ['id' => 7]);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('not_found', (string) $response->getBody());
    }

    public function testEnabledFeatureLetsTheRequestReachTheUsualAuthorizationRules(): void
    {
        $domains = $this->createStub(SupplierDomainRepository::class);
        $domains->method('listForSupplier')->willReturn([]);

        $response = $this->action($domains, true)
            ->list($this->request(), (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
    }

    private function action(SupplierDomainRepository $domains, bool $enabled): SupplierDomainAction
    {
        return new SupplierDomainAction(
            $domains,
            new SupplierDomainRegistrationService(
                $domains,
                new HostnameNormalizer(),
                new Config(['app' => ['url' => 'https://app.example.test']]),
            ),
            new SupplierDomainVerificationService(
                $domains,
                $this->createStub(DomainVerificationService::class),
            ),
            $this->createStub(MfaStepUpService::class),
            $this->createStub(ActivityLogger::class),
            $this->createStub(IpMatcher::class),
            new TenantDomainFeature(new Config(['domains' => ['enabled' => $enabled]])),
            new ManagedModeGuard(new Config([])),
        );
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/settings/domains')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41);
    }
}
