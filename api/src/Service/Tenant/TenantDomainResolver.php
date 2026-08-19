<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\SupplierDomainRepository;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TenantDomainResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly HostnameNormalizer $normalizer,
        private readonly SupplierDomainRepository $domains,
        private readonly TenantDomainFeature $feature,
    ) {}

    public function resolve(Request $request): TenantDomainContext
    {
        $rawHost = $request->getUri()->getHost();
        // Vypnutá featura = stav před zavedením domén: každý hostname je
        // canonical, nic se nedohledává v DB a nic se neodmítá. Origin zůstává
        // `app.url` (i prázdné), aby CSRF kontrola porovnávala přesně to co dřív.
        if (!$this->feature->isEnabled()) {
            return new TenantDomainContext(
                TenantDomainContext::CANONICAL,
                $this->bestEffortHost($rawHost),
                $this->canonicalOrigin(),
            );
        }
        if ($rawHost === '') {
            $authority = trim($request->getHeaderLine('Host'));
            $parsed = $authority !== '' ? parse_url('http://' . $authority, PHP_URL_HOST) : null;
            $rawHost = is_string($parsed) ? $parsed : '';
        }
        if ($rawHost === '' && PHP_SAPI === 'cli') {
            // HTTP/1.1 i HTTP/2 hostname vždy nesou; chybí jen v řadě přímých
            // PSR-7 integračních testů, které aplikaci volají relativní URI.
            $rawHost = (string) parse_url($this->canonicalOrigin(), PHP_URL_HOST);
        }
        try {
            $requestHost = $this->normalizer->normalizeRequestHost($rawHost);
        } catch (\InvalidArgumentException) {
            return new TenantDomainContext(TenantDomainContext::UNKNOWN, '', '');
        }

        $canonicalOrigin = $this->canonicalOrigin();
        if ($canonicalOrigin === '') {
            $port = $request->getUri()->getPort();
            $origin = $request->getUri()->getScheme() . '://' . $requestHost
                . ($port !== null ? ':' . $port : '');
            return new TenantDomainContext(TenantDomainContext::CANONICAL, $requestHost, $origin);
        }

        $canonicalHost = (string) parse_url($canonicalOrigin, PHP_URL_HOST);
        try {
            $canonicalHost = $this->normalizer->normalizeRequestHost($canonicalHost);
        } catch (\InvalidArgumentException) {
            return new TenantDomainContext(TenantDomainContext::UNKNOWN, $requestHost, '');
        }
        if (hash_equals($canonicalHost, $requestHost)) {
            try {
                $collidingDomain = $this->domains->findByHostname($requestHost);
            } catch (\PDOException) {
                // Při nedostupné DB nech canonical request doběhnout. Health pak
                // může vrátit db=false; žádná tenantová data bez DB stejně nejdou
                // načíst a přepsání této diagnostiky 421 by nápravu ztížilo.
                $collidingDomain = null;
            }
            if ($collidingDomain !== null) {
                return new TenantDomainContext(
                    TenantDomainContext::CONFIGURATION_ERROR,
                    $requestHost,
                    $canonicalOrigin,
                );
            }
            return new TenantDomainContext(TenantDomainContext::CANONICAL, $requestHost, $canonicalOrigin);
        }

        $domain = $this->domains->findByHostname($requestHost);
        if ($domain === null) {
            return new TenantDomainContext(TenantDomainContext::UNKNOWN, $requestHost, '');
        }

        $mode = $domain['status'] === 'active'
            ? TenantDomainContext::CUSTOM
            : TenantDomainContext::VERIFICATION;
        return new TenantDomainContext(
            $mode,
            $requestHost,
            'https://' . $requestHost,
            (int) $domain['id'],
            (int) $domain['supplier_id'],
            (string) $domain['purpose'],
            (string) $domain['status'],
        );
    }

    public function canonicalOrigin(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }

    /** Hostname jen pro informaci — s vypnutou featurou na něm nic nezávisí. */
    private function bestEffortHost(string $rawHost): string
    {
        try {
            return $this->normalizer->normalizeRequestHost($rawHost);
        } catch (\InvalidArgumentException) {
            return '';
        }
    }
}
