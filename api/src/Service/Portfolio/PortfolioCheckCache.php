<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Infrastructure\Cache\SupplierDataCache;

/**
 * Cache souhrnu měsíční kontroly firmy pro přehled firem.
 *
 * Přehled pouští pro každou firmu jedenáct kontrol, u skupiny s desítkami firem jde
 * o stovky dotazů na jedno otevření stránky. Mezi dvěma otevřeními se přitom data
 * většiny firem nezmění. Zneplatnění a TTL viz {@see SupplierDataCache}. Detail
 * měsíční kontroly se necachuje.
 */
final class PortfolioCheckCache
{
    public function __construct(private readonly SupplierDataCache $cache) {}

    /**
     * @param callable():(array|null) $producer
     */
    public function remember(int $supplierId, \DateTimeImmutable $now, callable $producer): ?array
    {
        return $this->cache->remember($supplierId, 'portfolio-check', $now, $producer);
    }
}
