<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DomainContextAction
{
    public function __construct(private readonly TenantDomainResolver $resolver) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $context = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        if (!$context instanceof TenantDomainContext) {
            $context = $this->resolver->resolve($request);
        }
        $data = $context->toArray();
        $data['canonical_base_url'] = $this->resolver->canonicalOrigin();
        $data['canonical_login_url'] = $data['canonical_base_url'] . '/login';
        return Json::ok($response, $data);
    }
}
