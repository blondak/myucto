<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Cache\SupplierWriteGenerations;
use MyInvoice\Infrastructure\Config\Config;

/**
 * Cache souhrnu měsíční kontroly firmy pro přehled firem.
 *
 * Přehled pouští pro každou firmu jedenáct kontrol, u skupiny s desítkami firem jde
 * o stovky dotazů na jedno otevření stránky. Mezi dvěma otevřeními se přitom
 * data většiny firem nezmění.
 *
 * Klíč nese den (kontroly se počítají k dnešku) a generace dat firmy i globální
 * ({@see SupplierWriteGenerations}). Zápis do firmy tak její souhrn zneplatní
 * okamžitě, ostatní firmy zůstanou v cache. TTL kryje zápisy, které generace
 * neodchytí (zápis requestu jedné firmy do dat jiné).
 *
 * Bez Redisu a pod PHPUnitem je průchozí. Detail měsíční kontroly se necachuje.
 */
final class PortfolioCheckCache
{
    public const TTL = 600;
    private const ENTRY_PREFIX = 'pfmc:';

    private readonly bool $enabled;

    public function __construct(
        private readonly RedisFactory $redis,
        Config $config,
    ) {
        $this->enabled = !defined('PHPUNIT_COMPOSER_INSTALL')
            && (bool) $config->get('cache.portfolio_checks_enabled', true);
    }

    /**
     * @template T of array|null
     * @param callable():T $producer
     * @return T
     */
    public function remember(int $supplierId, \DateTimeImmutable $now, callable $producer): mixed
    {
        if (!$this->enabled) {
            return $producer();
        }

        $gens = $this->redis->run(static fn ($c) => $c->mget([
            SupplierWriteGenerations::KEY_PREFIX . '0',
            SupplierWriteGenerations::KEY_PREFIX . $supplierId,
        ]));
        if (!is_array($gens)) {
            return $producer();
        }

        $key = self::ENTRY_PREFIX . $supplierId . ':' . $now->format('Y-m-d') . ':' . (int) ($gens[0] ?? 0) . ':' . (int) ($gens[1] ?? 0);
        $raw = $this->redis->run(static fn ($c) => $c->get($key));
        if (is_string($raw) && $raw !== '') {
            $cached = json_decode($raw, true);
            if (is_array($cached) && array_key_exists('summary', $cached)) {
                return $cached['summary'];
            }
        }

        // Generace se čte PŘED výpočtem: zápis, který doběhne během něj, zvedne
        // generaci a výsledek uložený pod starou už nikdo nepřečte.
        $summary = $producer();
        $payload = json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            $this->redis->run(static fn ($c) => $c->setex($key, self::TTL, $payload));
        }

        return $summary;
    }
}
