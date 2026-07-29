<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Cache;

use MyInvoice\Infrastructure\Config\Config;
use Predis\Client as RedisClient;

/**
 * Vrací Predis klienta nebo null pokud je Redis vypnutý / nedostupný.
 * Klíče se prefixují přes `cfg.redis.prefix`.
 *
 * Všechna volání veď přes `run()`. `client()` ověří spojení jen jednou za request,
 * takže pád Redisu POTÉ vyhodí z Predisu výjimku uprostřed requestu — `run()` ji
 * zachytí, klienta zneplatní a zbytek requestu jede degradovaně místo HTTP 500.
 */
final class RedisFactory
{
    private ?RedisClient $client = null;
    private bool $checked = false;

    public function __construct(private readonly Config $config) {}

    public function client(): ?RedisClient
    {
        if ($this->checked) {
            return $this->client;
        }
        $this->checked = true;

        if (!$this->config->get('redis.enabled', false)) {
            return null;
        }

        $params = [
            'scheme'   => 'tcp',
            'host'     => (string) $this->config->get('redis.host', '127.0.0.1'),
            'port'     => (int) $this->config->get('redis.port', 6379),
            'database' => (int) $this->config->get('redis.db', 0),
            'timeout'  => 1.5,
        ];

        // Bez tohohle by se heslo z cfg/ENV nikdy neposlalo a spojení proti Redisu
        // s `requirepass` by tiše spadlo do fallbacku.
        $auth = $this->config->get('redis.auth');
        if (is_string($auth) && $auth !== '') {
            $params['password'] = $auth;
        }

        try {
            $this->client = new RedisClient($params, [
                'prefix' => (string) $this->config->get('redis.prefix', 'myinvoice:'),
            ]);
            $this->client->ping();
        } catch (\Throwable) {
            $this->client = null;
        }

        return $this->client;
    }

    public function isAvailable(): bool
    {
        return $this->client() !== null;
    }

    /**
     * Spustí operaci nad Redisem. Když Redis není nebo volání selže, vrátí $default
     * a pro zbytek requestu se na Redis rezignuje (další `run()` už jen vrací $default).
     *
     * @template T
     * @param callable(RedisClient): T $fn
     * @param T|null $default
     * @return T|null
     */
    public function run(callable $fn, mixed $default = null): mixed
    {
        $client = $this->client();
        if ($client === null) {
            return $default;
        }

        try {
            return $fn($client);
        } catch (\Throwable) {
            // Spojení umřelo uprostřed requestu — degraduj, neshazuj request.
            $this->client = null;
            $this->checked = true;
            return $default;
        }
    }
}
