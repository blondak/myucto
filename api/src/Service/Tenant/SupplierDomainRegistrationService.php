<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\SupplierDomainRepository;

final class SupplierDomainRegistrationService
{
    public function __construct(
        private readonly SupplierDomainRepository $domains,
        private readonly HostnameNormalizer $hostnames,
        private readonly Config $config,
    ) {}

    /** @return array<string,mixed> */
    public function register(int $supplierId, string $hostname, string $purpose, int $userId): array
    {
        $hostname = $this->hostnames->normalizeDomain($hostname);
        $this->assertNotCanonicalHostname($hostname);

        return $this->domains->create($supplierId, $hostname, $purpose, $userId);
    }

    public function assertNotCanonicalHostname(string $hostname): void
    {
        if ($this->hostnames->normalizeDomain($hostname) === $this->canonicalHostname()) {
            throw new SupplierDomainHostnameCollisionException(
                'Hostname nastavený v app.url nelze použít jako vlastní doménu firmy. Zadejte jiný hostname.',
            );
        }
    }

    private function canonicalHostname(): string
    {
        $appUrl = trim((string) $this->config->get('app.url', ''));
        try {
            $parts = $appUrl === '' ? false : parse_url($appUrl);
        } catch (\ValueError) {
            $parts = false;
        }

        $hostname = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if ($hostname === '') {
            throw new \RuntimeException(
                'V konfiguraci chybí platný hostname v app.url; vlastní doménu nelze bezpečně založit.',
            );
        }

        try {
            return $this->hostnames->normalizeRequestHost($hostname);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(
                'Hostname v app.url není platný; vlastní doménu nelze bezpečně založit.',
                0,
                $e,
            );
        }
    }
}
