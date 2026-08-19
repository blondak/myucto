<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\SupplierDomainRepository;

final class TenantUrlResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly SupplierDomainRepository $domains,
    ) {}

    public function canonicalBaseUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }

    public function portalBaseUrl(int $supplierId): string
    {
        return $this->baseFor($supplierId, 'portal');
    }

    public function publicBaseUrl(int $supplierId): string
    {
        return $this->baseFor($supplierId, 'public_links');
    }

    public function urlFor(int $supplierId, string $purpose, string $path): string
    {
        if ($path === ''
            || $path[0] !== '/'
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
        ) {
            throw new \InvalidArgumentException('Tenant URL vyžaduje bezpečnou absolutní cestu.');
        }

        return $this->baseFor($supplierId, $purpose) . $path;
    }

    /** Zpětně kompatibilní název pro již zapojené call-site. */
    public function forSupplier(int $supplierId, string $purpose, string $path): string
    {
        return $this->urlFor($supplierId, $purpose, $path);
    }

    private function baseFor(int $supplierId, string $purpose): string
    {
        if (!in_array($purpose, ['portal', 'public_links'], true)) {
            throw new \InvalidArgumentException('Neplatný účel tenant URL.');
        }
        $domain = $supplierId > 0 ? $this->domains->primaryForSupplier($supplierId, $purpose) : null;
        return $domain !== null
            ? 'https://' . $domain['hostname']
            : $this->canonicalBaseUrl();
    }
}
