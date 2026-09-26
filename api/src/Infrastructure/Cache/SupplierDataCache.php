<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Cache;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Cache výsledku spočítaného nad daty JEDNÉ firmy (souhrn dashboardu, kontroly
 * v přehledu firem).
 *
 * Klíč nese den (výpočty „k dnešku") a generaci dat firmy i globální generaci
 * ({@see SupplierWriteGenerations}). Zápis do firmy tak její položky zneplatní
 * okamžitě, ostatní firmy zůstanou v cache. TTL kryje zápisy, které generace
 * neodchytí (zápis requestu jedné firmy do dat jiné).
 *
 * Bez Redisu a pod PHPUnitem je průchozí.
 */
final class SupplierDataCache
{
    public const TTL = 600;
    private const ENTRY_PREFIX = 'sdc:';

    private readonly bool $enabled;

    public function __construct(
        private readonly RedisFactory $redis,
        Config $config,
    ) {
        $this->enabled = !defined('PHPUNIT_COMPOSER_INSTALL')
            && (bool) $config->get('cache.supplier_data_enabled', true);
    }

    /**
     * @param string $name druh výsledku včetně všeho, na čem kromě dat firmy závisí
     *                     (např. oprávnění uživatele)
     * @template T
     * @param callable():T $producer výsledek musí jít uložit jako JSON
     * @return T
     */
    public function remember(int $supplierId, string $name, \DateTimeImmutable $now, callable $producer): mixed
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

        $key = self::ENTRY_PREFIX . $name . ':' . $supplierId . ':' . $now->format('Y-m-d')
            . ':' . (int) ($gens[0] ?? 0) . ':' . (int) ($gens[1] ?? 0);
        $raw = $this->redis->run(static fn ($c) => $c->get($key));
        if (is_string($raw) && $raw !== '') {
            $cached = json_decode($raw, true);
            if (is_array($cached) && array_key_exists('v', $cached)) {
                return $cached['v'];
            }
        }

        // Generace se čte PŘED výpočtem: zápis, který doběhne během něj, zvedne
        // generaci a výsledek uložený pod starou už nikdo nepřečte.
        $value = $producer();
        $payload = json_encode(['v' => $value], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($payload !== false) {
            $this->redis->run(static fn ($c) => $c->setex($key, self::TTL, $payload));
        }

        return $value;
    }
}
