<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Settings;

use MyInvoice\Action\Settings\SupplierDomainAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\DomainVerificationService;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Tenant\SupplierDomainRegistrationService;
use MyInvoice\Service\Tenant\SupplierDomainVerificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SupplierDomainCreationTest extends TestCase
{
    #[DataProvider('canonicalHostnameVariants')]
    public function testCanonicalAppUrlHostnameCannotCreateSupplierDomain(
        string $appUrl,
        string $candidate,
    ): void
    {
        $config = new Config([
            'app' => ['url' => $appUrl],
        ]);
        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->expects(self::never())->method('create');
        $domains->expects(self::never())->method('update');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');
        $verification = $this->createMock(DomainVerificationService::class);
        $verification->expects(self::never())->method('verify');

        $response = $this->action($config, $domains, $activity, $verification)->create(
            $this->request($candidate),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('canonical_hostname_conflict', $body['error']['code']);
        self::assertSame(
            'Hostname nastavený v app.url nelze použít jako vlastní doménu firmy. Zadejte jiný hostname.',
            $body['error']['message'],
        );
    }

    /** @return iterable<string,array{string,string}> */
    public static function canonicalHostnameVariants(): iterable
    {
        yield 'case and trailing dots; canonical scheme and port' => [
            'https://APP.Synthetic.Example.:8443/',
            'app.synthetic.example.',
        ];
        yield 'canonical casing and trailing dot' => [
            'https://APP.Synthetic.Example./',
            'App.Synthetic.Example',
        ];
        if (function_exists('idn_to_ascii')) {
            yield 'unicode candidate equals canonical punycode' => [
                'https://faktura.xn--esko-fua:443/',
                'Faktura.Česko.',
            ];
        }
    }

    public function testNonCanonicalDomainKeepsExistingCreationBehavior(): void
    {
        $config = new Config([
            'app' => ['url' => 'https://app.synthetic.example'],
        ]);
        $created = [
            'id' => 73,
            'supplier_id' => 41,
            'hostname' => 'portal.synthetic.example',
            'purpose' => 'portal',
            'status' => 'pending',
            'is_primary' => false,
            'is_primary_portal' => false,
            'is_primary_public' => false,
            'verification_token' => str_repeat('a', 64),
        ];
        $domains = $this->createMock(SupplierDomainRepository::class);
        $domains->expects(self::once())
            ->method('create')
            ->with(41, 'portal.synthetic.example', 'portal', 17)
            ->willReturn($created);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())
            ->method('log')
            ->with(
                'supplier_domain.created',
                17,
                'supplier_domain',
                73,
                ['hostname' => 'portal.synthetic.example', 'purpose' => 'portal'],
                null,
                '',
                41,
            );

        $response = $this->action($config, $domains, $activity)->create(
            $this->request(' Portal.Synthetic.Example. '),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('portal.synthetic.example', $body['hostname']);
        self::assertSame('portal', $body['purpose']);
    }

    private function action(
        Config $config,
        SupplierDomainRepository $domains,
        ActivityLogger $activity,
        ?DomainVerificationService $verification = null,
    ): SupplierDomainAction {
        $verification ??= $this->createStub(DomainVerificationService::class);

        return new SupplierDomainAction(
            $domains,
            new SupplierDomainRegistrationService(
                $domains,
                new HostnameNormalizer(),
                $config,
            ),
            new SupplierDomainVerificationService(
                $domains,
                $verification,
            ),
            $this->createStub(MfaStepUpService::class),
            $activity,
            $this->createStub(IpMatcher::class),
            new TenantDomainFeature(new Config(['domains' => ['enabled' => true]])),
        );
    }

    private function request(string $hostname): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/settings/domains')
            ->withHeader('Host', 'request-host.synthetic.example')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41)
            ->withParsedBody([
                'hostname' => $hostname,
                'purpose' => 'portal',
                'is_primary' => false,
            ]);
    }
}
