<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Vlastní domény firem jsou opt-in (`domains.enabled` v cfg.php), a to celé:
 * vypnuté nezapínají jen správu v Nastavení, ale ani rozpoznávání hostname.
 *
 * Důvod je bezpečnostní, ne kosmetický. Kdyby přepínač vypnul pouze host gate
 * a administrace zůstala dostupná, firma by si doménu aktivovala, resolver by
 * ji ale vyhodnotil jako canonical — hostname by přestal být tenantová hranice
 * a `X-Supplier-Id` by ji zase přebilo. Proto se vypíná celý mechanismus naráz
 * a instalace se chová přesně jako před zavedením domén: každý hostname je
 * canonical a nic ho neodmítne.
 */
final class TenantDomainFeature
{
    public function __construct(private readonly Config $config) {}

    public function isEnabled(): bool
    {
        return filter_var(
            $this->config->get('domains.enabled', false),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) === true;
    }
}
