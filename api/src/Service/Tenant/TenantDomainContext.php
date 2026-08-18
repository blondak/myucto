<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

final readonly class TenantDomainContext
{
    public const CANONICAL = 'canonical';
    public const CUSTOM = 'custom';
    public const VERIFICATION = 'verification';
    public const UNKNOWN = 'unknown';
    public const CONFIGURATION_ERROR = 'configuration_error';

    public function __construct(
        public string $mode,
        public string $hostname,
        public string $origin,
        public ?int $domainId = null,
        public ?int $supplierId = null,
        public ?string $purpose = null,
        public ?string $status = null,
    ) {
        if (!in_array($mode, [
            self::CANONICAL,
            self::CUSTOM,
            self::VERIFICATION,
            self::UNKNOWN,
            self::CONFIGURATION_ERROR,
        ], true)) {
            throw new \InvalidArgumentException('Neplatný režim tenant domény.');
        }
    }

    public function locksSupplier(): bool
    {
        return $this->mode === self::CUSTOM && ($this->supplierId ?? 0) > 0;
    }

    public function allowsPortal(): bool
    {
        return $this->mode === self::CANONICAL
            || ($this->mode === self::CUSTOM && in_array($this->purpose, ['portal', 'all'], true));
    }

    public function allowsPublicLinks(): bool
    {
        return $this->mode === self::CANONICAL
            || ($this->mode === self::CUSTOM && in_array($this->purpose, ['public_links', 'all'], true));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'hostname' => $this->hostname,
            'origin' => $this->origin,
            'locked' => $this->locksSupplier(),
            'supplier_id' => $this->supplierId,
            'purpose' => $this->purpose,
        ];
    }
}
