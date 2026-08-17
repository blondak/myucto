<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Service\Tenant\TenantDomainPolicy;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/** Hostname je autoritativní tenantová hranice před autentizací i supplier scope. */
final class TenantDomainMiddleware implements MiddlewareInterface
{
    public const ATTR_CONTEXT = 'tenant.domain_context';

    public function __construct(
        private readonly TenantDomainResolver $resolver,
        private readonly TenantDomainPolicy $policy,
        private readonly ResponseFactory $responses,
        private readonly FirstRunLockMiddleware $firstRun,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = RequestPath::normalize($request->getUri()->getPath());
        // Setup musí zůstat dostupný z LAN hostname ještě před vytvořením admina
        // a dokonce před existencí supplier_domains. FirstRunLock je outer vrstva
        // a zde sdílíme jeho přesný allowlist místo druhé kopie pravidla.
        if ($this->firstRun->needsSetup()
            && $this->firstRun->allowsDuringSetup($request->getMethod(), $path)
        ) {
            return $handler->handle($request);
        }

        $context = $this->resolver->resolve($request);

        $denial = $this->policy->denial($context, $request->getMethod(), $path);
        if ($denial !== null) {
            return Json::error(
                $this->responses->createResponse($denial['status']),
                $denial['code'],
                $denial['message'],
                $denial['status'],
            );
        }

        return $handler->handle($request->withAttribute(self::ATTR_CONTEXT, $context));
    }
}
