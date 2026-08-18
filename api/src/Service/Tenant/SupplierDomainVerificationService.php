<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Repository\SupplierDomainRepository;

/** Spojuje externí DNS/HTTPS kontrolu s optimistickým zápisem jejího výsledku. */
final class SupplierDomainVerificationService
{
    private const SNAPSHOT_FIELDS = [
        'id',
        'supplier_id',
        'hostname',
        'verification_token',
        'status',
        'updated_at',
    ];

    public function __construct(
        private readonly SupplierDomainRepository $domains,
        private readonly DomainVerificationService $verification,
    ) {}

    /**
     * @param array<string,mixed>|null $expectedDomain
     * @return array{
     *   domain:array<string,mixed>,
     *   checks:array{verified:bool,dns:bool,https:bool,error:?string}
     * }
     */
    public function verifyCurrent(
        int $supplierId,
        int $domainId,
        int $userId,
        ?array $expectedDomain = null,
    ): array {
        $domain = $this->domains->findOwned($supplierId, $domainId);
        if ($domain === null) throw new \OutOfBoundsException('Doména neexistuje.');
        if ($expectedDomain !== null && !$this->sameSnapshot($domain, $expectedDomain)) {
            throw new \DomainException('Doména se před ověřením změnila; spusť kontrolu znovu.');
        }

        $checks = $this->verification->verify($domain);
        $this->domains->recordVerification(
            $supplierId,
            $domainId,
            $domain,
            $checks['verified'],
            $checks['error'],
            $userId,
        );

        return [
            'domain' => $this->domains->findOwned($supplierId, $domainId)
                ?? throw new \OutOfBoundsException('Doména po ověření neexistuje.'),
            'checks' => $checks,
        ];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $expected */
    private function sameSnapshot(array $current, array $expected): bool
    {
        foreach (self::SNAPSHOT_FIELDS as $field) {
            if (($current[$field] ?? null) !== ($expected[$field] ?? null)) return false;
        }
        return true;
    }
}
