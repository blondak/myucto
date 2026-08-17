<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Middleware\TenantDomainMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PublicTenantGuard
{
    public function __construct(private readonly TenantDomainResolver $resolver) {}

    public function allows(Request $request, int $supplierId): bool
    {
        $context = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        if (!$context instanceof TenantDomainContext) {
            // Guard nesmí při chybějícím middleware tiše fail-open. Přímé volání
            // akce si kontext bezpečně odvodí stejným resolverem jako produkce.
            $context = $this->resolver->resolve($request);
        }
        if ($context->mode === TenantDomainContext::CANONICAL) {
            return true;
        }
        return $context->mode === TenantDomainContext::CUSTOM
            && $context->allowsPublicLinks()
            && $context->supplierId === $supplierId;
    }
}
