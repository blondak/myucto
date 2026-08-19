<?php

declare(strict_types=1);

namespace MyInvoice\Action\Public;

use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Tenant\TenantDomainContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DomainVerificationAction
{
    public function __construct(private readonly SupplierDomainRepository $domains) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $context = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        $provided = strtolower((string) ($args['token'] ?? ''));
        if (!$context instanceof TenantDomainContext
            || $context->mode !== TenantDomainContext::VERIFICATION
            || $context->domainId === null
            || $context->supplierId === null
            || preg_match('/^[a-f0-9]{64}$/D', $provided) !== 1
        ) {
            return $response->withStatus(404);
        }

        $domain = $this->domains->findOwned($context->supplierId, $context->domainId);
        $expected = (string) ($domain['verification_token'] ?? '');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            return $response->withStatus(404);
        }

        $response->getBody()->write('myucto-verification=' . $expected);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(200);
    }
}
