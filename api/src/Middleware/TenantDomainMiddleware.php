<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\Tenant\TenantDomainContext;
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
        private readonly AppUrlConfiguration $appUrl,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = RequestPath::normalize($request->getUri()->getPath());
        $isReadOnlyHealth = in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)
            && $path === '/api/health';
        // Setup musí zůstat dostupný z LAN hostname ještě před vytvořením admina
        // a dokonce před existencí supplier_domains. FirstRunLock je outer vrstva
        // a zde sdílíme jeho přesný allowlist místo druhé kopie pravidla.
        if ($this->firstRun->needsSetup()
            && $this->firstRun->allowsDuringSetup($request->getMethod(), $path)
        ) {
            return $handler->handle($request);
        }

        // Když canonical origin nejde použít, běžný host gate nemá hostname,
        // přes který by správce zjistil příčinu. Výjimka proto zpřístupní pouze
        // read-only health operaci; platné app.url ani žádná jiná cesta bypass
        // nedostanou. API v1 je v této chvíli už přepsané na /api/health.
        if ($this->appUrl->needsHealthHostBypass() && $isReadOnlyHealth) {
            return $handler->handle($request);
        }

        $context = $this->resolver->resolve($request);
        if ($context->mode === TenantDomainContext::CONFIGURATION_ERROR) {
            $this->appUrl->hostnameConflictStatus();
        }

        // Monitoring i akceptační testy hostingu volají health přes hostname
        // instance, ne přes canonical `app.url` — a při diagnostice i přes něco,
        // co v `supplier_domains` nikdy nebude. Host gate proto read-only health
        // nezavírá tam, kde by jinak vrátil 421. Bez téhle výjimky by se
        // nedostupnost a špatně nastavené `app.url` navenek tvářily stejně.
        //
        // Vlastní domény firem (CUSTOM / VERIFICATION) výjimku NEDOSTÁVAJÍ:
        // health je mimo klientské rozhraní a verze ani stav instalace na
        // klientskou doménu nepatří — tam dál platí verdikt politiky.
        if ($isReadOnlyHealth && in_array($context->mode, [
            TenantDomainContext::UNKNOWN,
            TenantDomainContext::CONFIGURATION_ERROR,
        ], true)) {
            return $handler->handle($request->withAttribute(self::ATTR_CONTEXT, $context));
        }

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
